<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\GovProfile;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/register
     *
     * Creates a password-less account and sends email verification.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $firstName = $request->first_name;
            $lastName  = $request->last_name;

            $user = User::create([
                'name'                     => trim("{$firstName} {$lastName}"),
                'first_name'               => $firstName,
                'middle_name'              => $request->middle_name,
                'last_name'                => $lastName,
                'email'                    => $request->email,
                'password'                 => null,
                'primary_phone'            => $request->primary_phone,
                'secondary_phone'          => $request->secondary_phone,
                'consent_marketing'        => $request->boolean('consent_marketing', false),
                'agreed_terms_at'          => now(),
                'status'                   => 'pending_email_verification',
                'registration_ip_address'  => $request->ip(),
                'terms_version'            => config('app.terms_version', '1.0'),
                'agree_bidder_terms'       => (bool) $request->agree_bidder_terms,
                'agree_ecomm_consent'      => (bool) $request->agree_ecomm_consent,
                'agree_accuracy_confirmed' => (bool) $request->agree_accuracy_confirmed,
            ]);

            $user->assignRole('buyer'); // default role; updated on activation if dealer

            event(new Registered($user));

            return $user;
        });

        return $this->success(
            ['user_id' => $user->id, 'next_step' => 'verify_email'],
            'Registration successful. Please check your email to verify your account.',
            201
        );
    }

    /**
     * GET /api/v1/auth/email/verify/{id}/{hash}
     *
     * Verifies email via signed link, sets status → pending_password,
     * then redirects browser to the frontend set-password page.
     */
    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse|RedirectResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $this->error('Invalid verification link.', 400, 'invalid_link');
        }

        if ($user->hasVerifiedEmail()) {
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            return redirect("{$frontendUrl}/set-password?already_verified=1");
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        $user->update(['status' => 'pending_password']);

        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        return redirect("{$frontendUrl}/set-password?email_verified=1&email=" . urlencode($user->email));
    }

    /**
     * POST /api/v1/auth/resend-verification
     *
     * Public — accepts email, resends verification link.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'exists:users,email']]);

        $user = User::where('email', $request->email)->firstOrFail();

        if ($user->hasVerifiedEmail()) {
            return $this->error('Email is already verified.', 422, 'already_verified');
        }

        $user->sendEmailVerificationNotification();

        return $this->success(message: 'Verification email resent.');
    }

    /**
     * POST /api/v1/auth/set-password
     *
     * Called after email verification. Sets password and advances status → pending_activation.
     */
    public function setPassword(SetPasswordRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->firstOrFail();

        if (! $user->hasVerifiedEmail()) {
            return $this->error('Email address has not been verified yet.', 422, 'email_not_verified');
        }

        if ($user->password_set_at) {
            return $this->error('Password has already been set. Please use the forgot-password flow.', 422, 'password_already_set');
        }

        $user->update([
            'password'        => Hash::make($request->password),
            'password_set_at' => now(),
            'status'          => 'pending_activation',
        ]);

        return $this->success(message: 'Password set successfully. You can now log in.');
    }

    /**
     * POST /api/v1/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->isSuspended()) {
            return $this->error('Your account has been suspended. Please contact support.', 403, 'account_suspended');
        }

        if ($user->isPendingEmailVerification()) {
            return $this->error('Please verify your email address before logging in.', 403, 'email_not_verified');
        }

        if ($user->isPendingPassword()) {
            return $this->error('Please set your password before logging in.', 403, 'password_not_set');
        }

        $token = $user->createToken($request->input('device_name', 'api'))->plainTextToken;

        $user->load(['accountInformation', 'dealerInformation', 'billingInformation']);

        $activationRequired = $user->isActivationRequired();

        return $this->success(
            [
                'user'                => new UserResource($user),
                'token'               => $token,
                'activation_required' => $activationRequired,
                'next_activation_url' => $activationRequired ? '/activation/account-type' : null,
            ],
            'Login successful.'
        );
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(message: 'Logged out successfully.');
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load([
            'accountInformation',
            'dealerInformation',
            'billingInformation',
            'documents',
        ]);

        return $this->success(new UserResource($user));
    }

    /**
     * POST /api/v1/auth/password/forgot
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        if ($status !== Password::RESET_LINK_SENT) {
            return $this->error(__($status), 422, 'reset_failed');
        }

        return $this->success(message: __($status));
    }

    /**
     * POST /api/v1/auth/password/reset
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])
                     ->setRememberToken(Str::random(60));
                $user->save();
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->error(__($status), 422, 'reset_failed');
        }

        return $this->success(message: __($status));
    }

    /**
     * GET /api/v1/auth/accept-invite?token=...
     *
     * Validate a government account invite token and return the associated
     * email so the frontend can pre-fill the set-password form.
     */
    public function validateInvite(Request $request): JsonResponse
    {
        $token = $request->query('token');

        if (! $token) {
            return $this->error('Invite token is required.', 422, 'token_required');
        }

        $profile = GovProfile::where('invite_token', $token)->first();

        if (! $profile) {
            return $this->error('This invite link is invalid or has already been used.', 404, 'invalid_token');
        }

        $user = $profile->user;

        return $this->success([
            'email'       => $user->email,
            'entity_name' => $profile->entity_name,
        ]);
    }

    /**
     * POST /api/v1/auth/accept-invite
     *
     * Accept a government account invitation: marks email as verified and
     * advances the user to pending_password status so they can set a password.
     */
    public function acceptInvite(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
        ]);

        $profile = GovProfile::where('invite_token', $request->token)->first();

        if (! $profile) {
            return $this->error('This invite link is invalid or has already been used.', 404, 'invalid_token');
        }

        $user = $profile->user;

        $user->update([
            'email_verified_at' => $user->email_verified_at ?? now(),
            'status'            => 'pending_password',
        ]);

        $profile->update([
            'invite_accepted_at' => now(),
            'invite_token'       => null,
        ]);

        return $this->success([
            'email' => $user->email,
        ], 'Invitation accepted. Please set your password.');
    }
}
