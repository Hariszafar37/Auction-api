<?php

namespace App\Http\Controllers\Api\V1\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\ApplyToSellRequest;
use App\Http\Resources\UserResource;
use App\Models\SellerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerApplicationController extends Controller
{
    /**
     * GET /api/v1/my/seller-application
     *
     * Returns the authenticated user's current seller application status.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $profile = $user->sellerProfile;

        if (! $profile) {
            return $this->success(null, 'No seller application found.');
        }

        return $this->success([
            'approval_status'   => $profile->approval_status,
            'rejection_reason'  => $profile->rejection_reason,
            'application_notes' => $profile->application_notes,
            'vehicle_types'     => $profile->vehicle_types,
            'applied_at'        => $profile->created_at->toIso8601String(),
            'reviewed_at'       => $profile->reviewed_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /api/v1/my/seller-application
     *
     * Submit (or re-submit after rejection) a seller application.
     * Only individual users with status 'active' who are not already sellers may apply.
     * Uses updateOrCreate to allow re-application after rejection.
     */
    public function store(ApplyToSellRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->account_type !== 'individual') {
            return $this->error(
                'Only individual account holders may apply to become a seller.',
                422,
                'wrong_account_type'
            );
        }

        if ($user->status !== 'active') {
            return $this->error(
                'Your account must be fully activated before applying to sell.',
                422,
                'account_not_active'
            );
        }

        if ($user->hasRole('seller')) {
            return $this->error(
                'Your account already has seller access.',
                422,
                'already_seller'
            );
        }

        // Block re-application while a previous application is still pending
        $existing = $user->sellerProfile;
        if ($existing && $existing->approval_status === 'pending') {
            return $this->error(
                'You already have a pending seller application.',
                422,
                'application_pending'
            );
        }

        // updateOrCreate: supports fresh apply and re-apply after rejection
        SellerProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'application_notes' => $request->application_notes,
                'vehicle_types'     => $request->vehicle_types,
                'approval_status'   => 'pending',
                'rejection_reason'  => null,
                'reviewed_by'       => null,
                'reviewed_at'       => null,
                'packet_accepted_at' => now(),
            ]
        );

        return $this->success(
            null,
            'Your seller application has been submitted and is under review.',
            201
        );
    }
}
