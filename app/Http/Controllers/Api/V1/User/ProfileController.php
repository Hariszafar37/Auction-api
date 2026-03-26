<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdatePaymentInfoRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * GET /api/v1/profile
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['buyerProfile', 'dealerProfile']);

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
}
