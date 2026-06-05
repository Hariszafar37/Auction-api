<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateAccountInformationRequest;
use App\Http\Requests\Profile\UpdateBillingInformationRequest;
use App\Http\Requests\Profile\UpdateBusinessInformationRequest;
use App\Http\Requests\Profile\UpdateDealerInformationRequest;
use App\Http\Requests\User\UpdatePaymentInfoRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Relations eager-loaded whenever we return the profile resource so the
     * frontend always receives every editable section in one payload.
     */
    private const PROFILE_RELATIONS = [
        'buyerProfile',
        'dealerProfile',
        'accountInformation',
        'billingInformation',
        'businessInformation',
        'dealerInformation',
    ];

    /**
     * GET /api/v1/profile
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(self::PROFILE_RELATIONS);

        return $this->success(new UserResource($user));
    }

    /**
     * PATCH /api/v1/profile
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return $this->success(
            new UserResource($user->load(['buyerProfile', 'dealerProfile'])),
            'Profile updated successfully.'
        );
    }

    /**
     * PUT /api/v1/profile/payment
     */
    public function updatePayment(UpdatePaymentInfoRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->buyerProfile) {
            return $this->error('No buyer profile found.', 404, 'not_found');
        }

        $user->buyerProfile->update([
            'stripe_payment_method_id' => $request->payment_method_id,
            'deposit_authorized'       => true,
        ]);

        return $this->success(
            new UserResource($user->load('buyerProfile')),
            'Payment method saved.'
        );
    }

    /**
     * PUT /api/v1/profile/account-information
     *
     * Self-service edit of the user's personal/contact ("shipping") details.
     */
    public function updateAccountInformation(UpdateAccountInformationRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->accountInformation()->updateOrCreate(
            ['user_id' => $user->id],
            $request->validated()
        );

        return $this->success(
            new UserResource($user->fresh()->load(self::PROFILE_RELATIONS)),
            'Contact information updated.'
        );
    }

    /**
     * PUT /api/v1/profile/billing-information
     */
    public function updateBillingInformation(UpdateBillingInformationRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->billingInformation()->updateOrCreate(
            ['user_id' => $user->id],
            $request->validated()
        );

        return $this->success(
            new UserResource($user->fresh()->load(self::PROFILE_RELATIONS)),
            'Billing information updated.'
        );
    }

    /**
     * PUT /api/v1/profile/business-information
     *
     * Business accounts only. Updates the company information record without
     * touching the BusinessProfile approval snapshot or account_intent.
     */
    public function updateBusinessInformation(UpdateBusinessInformationRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->account_type !== 'business') {
            return $this->error('Business information is only available for business accounts.', 422, 'not_a_business');
        }

        $user->businessInformation()->updateOrCreate(
            ['user_id' => $user->id],
            $request->validated()
        );

        return $this->success(
            new UserResource($user->fresh()->load(self::PROFILE_RELATIONS)),
            'Business information updated.'
        );
    }

    /**
     * PUT /api/v1/profile/dealer-information
     *
     * Dealer accounts only. Updates the dealer company information record.
     * dealer_classification is intentionally not editable here — it gates
     * compliance and may only change through an admin-reviewed flow.
     */
    public function updateDealerInformation(UpdateDealerInformationRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->account_type !== 'dealer') {
            return $this->error('Dealer information is only available for dealer accounts.', 422, 'not_a_dealer');
        }

        $user->dealerInformation()->updateOrCreate(
            ['user_id' => $user->id],
            $request->validated()
        );

        return $this->success(
            new UserResource($user->fresh()->load(self::PROFILE_RELATIONS)),
            'Dealer information updated.'
        );
    }
}
