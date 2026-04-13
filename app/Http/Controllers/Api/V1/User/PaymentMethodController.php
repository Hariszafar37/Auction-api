<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StorePaymentMethodRequest;
use App\Http\Resources\UserResource;
use App\Support\CardBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    /**
     * GET /api/v1/users/payment-method
     * Returns the current payment method metadata (or null if none on file).
     */
    public function show(Request $request): JsonResponse
    {
        $user    = $request->user();
        $billing = $user->billingInformation;

        if (! $billing || ! $billing->payment_method_added) {
            return $this->success(['payment_method' => null], 'No payment method on file.');
        }

        return $this->success([
            'payment_method' => [
                'cardholder_name'  => $billing->cardholder_name,
                'card_brand'       => $billing->card_brand,
                'card_last_four'   => $billing->card_last_four,
                'expiry_month'     => $billing->card_expiry_month,
                'expiry_year'      => $billing->card_expiry_year,
                'is_valid'         => $billing->hasValidCard(),
            ],
        ], 'Payment method retrieved.');
    }

    /**
     * POST /api/v1/users/payment-method
     * Stores card METADATA only. Raw PAN and CVV are discarded after validation.
     * Updates the existing user_billing_information row (1:1 with user).
     *
     * REQUIRES that the billing address has already been saved via the
     * activation wizard — address columns are NOT NULL. Users who reach this
     * endpoint in practice have completed activation (the frontend only
     * exposes /payment-information for active users), so this is a precondition
     * rather than a blocker.
     */
    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        $user    = $request->user();
        $billing = $user->billingInformation;

        if (! $billing) {
            return $this->error(
                'Please complete your billing address before adding a payment method.',
                422,
                'billing_address_required'
            );
        }

        $number   = preg_replace('/\D/', '', (string) $request->input('card_number'));
        $lastFour = substr($number, -4);
        $brand    = CardBrand::detect($number);

        $billing->update([
            'cardholder_name'     => $request->input('cardholder_name'),
            'card_brand'          => $brand,
            'card_last_four'      => $lastFour,
            'card_expiry_month'   => (int) $request->input('expiry_month'),
            'card_expiry_year'    => (int) $request->input('expiry_year'),
            'payment_method_added' => true,
        ]);

        // Intentionally do NOT log, dd(), or persist $number / cvv anywhere.
        unset($number);

        return $this->success(
            new UserResource($user->fresh()->load('billingInformation')),
            'Payment method saved.'
        );
    }
}
