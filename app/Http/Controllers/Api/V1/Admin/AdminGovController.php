<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\Account\AccountApproved;
use App\Events\Account\AccountRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateGovProfileRequest;
use App\Models\GovProfile;
use App\Models\User;
use App\Notifications\GovAccountInvite;
use App\Services\Approval\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminGovController extends Controller
{
    /**
     * POST /api/v1/admin/government
     *
     * Create a government/charity/repo user and profile (admin only).
     */
    public function store(CreateGovProfileRequest $request): JsonResponse
    {
        [$user, $profile, $token] = DB::transaction(function () use ($request): array {
            $user = User::create([
                'name'         => $request->point_of_contact_name,
                'first_name'   => $request->point_of_contact_name,
                'last_name'    => '',
                'email'        => $request->email,
                'account_type' => 'government',
                'status'       => 'pending_email_verification',
                'password'     => null,
            ]);

            $user->assignRole('buyer');

            $token = Str::random(64);

            $profile = GovProfile::create([
                'user_id'               => $user->id,
                'entity_name'           => $request->entity_name,
                'entity_subtype'        => $request->entity_subtype,
                'department_division'   => $request->department_division,
                'point_of_contact_name' => $request->point_of_contact_name,
                'contact_title'         => $request->contact_title,
                'phone'                 => $request->phone,
                'office_phone'          => $request->office_phone,
                'address'               => $request->address,
                'city'                  => $request->city,
                'state'                 => $request->state,
                'zip'                   => $request->zip,
                'approval_status'       => 'pending',
                'invite_token'          => $token,
            ]);

            return [$user, $profile, $token];
        });

        // The account is created with `status = pending_email_verification` and no
        // password, so it is unusable until the holder accepts an invitation. That
        // invitation used to be a separate manual step that the admin UI never
        // exposed, which left every government account stranded — so it is issued
        // here, on creation.
        //
        // Delivery happens after the transaction commits: a mail outage must not
        // roll back the account. The token is already persisted either way, so the
        // admin can resend from the account detail page.
        $invitationSent = $this->deliverInvite($user, $token);

        // `invite_sent_at` records a delivery, not an attempt, so the detail page
        // reads "Not sent" and offers "Send invitation" when the mail failed.
        if ($invitationSent) {
            $profile->update(['invite_sent_at' => now()]);
        }

        return $this->success(
            [
                'user'        => $user->only(['id', 'name', 'email', 'account_type', 'status']),
                'gov_profile' => $profile->refresh(),
                'invite_sent' => $invitationSent,
            ],
            $invitationSent
                ? 'Government account created and invitation emailed.'
                : 'Government account created, but the invitation email could not be sent. Use “Resend invitation” to try again.',
            201
        );
    }

    /**
     * POST /api/v1/admin/government/{user}/invite
     *
     * Generate and send an invitation email to the government user.
     */
    public function sendInvite(User $user): JsonResponse
    {
        $profile = $user->govProfile;

        if (! $profile) {
            return $this->error('No government profile found for this user.', 404, 'not_found');
        }

        if ($profile->invite_accepted_at) {
            return $this->error('This invitation has already been accepted.', 422, 'already_accepted');
        }

        $token = Str::random(64);

        // Deliver before persisting. Rotating the token first would invalidate an
        // invitation that is already in the holder's inbox, so a failed resend
        // would leave the account with no working link at all.
        if (! $this->deliverInvite($user, $token)) {
            return $this->error('The invitation email could not be sent. Please try again.', 503, 'mail_failed');
        }

        $profile->update([
            'invite_token'   => $token,
            'invite_sent_at' => now(),
        ]);

        return $this->success(
            ['invite_sent_at' => $profile->refresh()->invite_sent_at?->toIso8601String()],
            'Invitation sent.'
        );
    }

    /**
     * Email the invitation, reporting whether it actually went out.
     *
     * Mail failure is logged rather than thrown so a delivery outage degrades to
     * "resend later" instead of losing an account the admin has already keyed in.
     */
    private function deliverInvite(User $user, string $token): bool
    {
        try {
            $user->notify(new GovAccountInvite($token));

            return true;
        } catch (\Throwable $e) {
            Log::error('Government account invitation failed to send.', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * GET /api/v1/admin/government/pending
     *
     * List government profiles pending approval.
     */
    public function pending(): JsonResponse
    {
        $users = \App\Models\User::whereHas('govProfile', fn ($q) => $q->where('approval_status', 'pending'))
            ->with('govProfile')
            ->latest()
            ->get();

        return $this->success($users->map(fn ($user) => $this->formatGovUser($user)));
    }

    /**
     * GET /api/v1/admin/government/{user}
     *
     * Return a single government account detail.
     */
    public function show(User $user): JsonResponse
    {
        if ($user->account_type !== 'government') {
            return $this->error('User is not a government account.', 404, 'not_found');
        }

        $user->load('govProfile');

        return $this->success($this->formatGovUser($user));
    }

    /**
     * POST /api/v1/admin/government/{user}/approve
     */
    public function approve(User $user, ApprovalService $approvals): JsonResponse
    {
        $profile = $user->govProfile;

        if (! $profile) {
            return $this->error('No government profile found for this user.', 404, 'not_found');
        }

        $previousStatus = $profile->approval_status;

        $profile->update([
            'approval_status' => 'approved',
            'reviewed_by'     => auth()->id(),
            'reviewed_at'     => now(),
        ]);

        $approvals->record(ApprovalService::TYPE_GOVERNMENT, $profile->id, $user->id, 'approved', $previousStatus, 'approved', null, auth()->id());

        $user->update(['status' => 'active']);
        event(new AccountApproved($user, 'government'));

        $user->load('govProfile');
        return $this->success($this->formatGovUser($user), 'Government account approved.');
    }

    /**
     * POST /api/v1/admin/government/{user}/reject
     */
    public function reject(User $user, Request $request, ApprovalService $approvals): JsonResponse
    {
        $request->validate([
            'rejection_reason' => ['required_without:reason', 'nullable', 'string'],
            'reason'           => ['required_without:rejection_reason', 'nullable', 'string'],
        ]);
        $rejectionReason = $request->rejection_reason ?? $request->reason;

        $profile = $user->govProfile;

        if (! $profile) {
            return $this->error('No government profile found for this user.', 404, 'not_found');
        }

        $previousStatus = $profile->approval_status;

        $profile->update([
            'approval_status'  => 'rejected',
            'rejection_reason' => $rejectionReason,
            'reviewed_by'      => auth()->id(),
            'reviewed_at'      => now(),
        ]);

        $approvals->record(ApprovalService::TYPE_GOVERNMENT, $profile->id, $user->id, 'rejected', $previousStatus, 'rejected', $rejectionReason, auth()->id());

        $user->update(['status' => 'suspended']);
        event(new AccountRejected($user, 'government', $rejectionReason));

        $user->load('govProfile');
        return $this->success($this->formatGovUser($user), 'Government account rejected.');
    }

    private function formatGovUser(\App\Models\User $user): array
    {
        $profile = $user->govProfile;
        return [
            'id'               => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'account_type'     => $user->account_type,
            'created_at'       => $user->created_at->toIso8601String(),
            'government_profile' => $profile ? [
                'entity_name'           => $profile->entity_name,
                'entity_subtype'        => $profile->entity_subtype,
                'department_division'   => $profile->department_division,
                'point_of_contact_name' => $profile->point_of_contact_name,
                'contact_title'         => $profile->contact_title,
                'phone'                 => $profile->phone,
                'office_phone'          => $profile->office_phone,
                'address'               => $profile->address,
                'city'                  => $profile->city,
                'state'                 => $profile->state,
                'zip'                   => $profile->zip,
                'approval_status'       => $profile->approval_status,
                'rejection_reason'      => $profile->rejection_reason,
                'admin_notes'           => $profile->admin_notes,
                'invite_sent_at'        => $profile->invite_sent_at?->toIso8601String(),
                'invite_accepted_at'    => $profile->invite_accepted_at?->toIso8601String(),
                'reviewed_at'           => $profile->reviewed_at?->toIso8601String(),
            ] : null,
        ];
    }
}
