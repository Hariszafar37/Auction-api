<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StorePaymentMethodRequest;
use App\Http\Resources\UserResource;
use App\Services\Payment\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentMethodController extends Controller
{
    public function __construct(
        private readonly StripeService $stripe,
    ) {}

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
     * GET /api/v1/users/payment-method/setup-intent
     *
     * Creates a Stripe SetupIntent (and a Customer if needed) so the frontend
     * can collect and confirm a reusable card via Stripe Elements. Returns the
     * client_secret — no card data ever touches our server.
     */
    public function setupIntent(Request $request): JsonResponse
    {
        if (! $this->stripe->configured()) {
            return $this->error('Card setup is temporarily unavailable.', 503, 'stripe_not_configured');
        }

        $user = $request->user();

        if (! $user->billingInformation) {
            return $this->error(
                'Please complete your billing address before adding a payment method.',
                422,
                'billing_address_required'
            );
        }

        try {
            $intent = $this->stripe->createSetupIntent($user);
        } catch (\Throwable $e) {
            Log::warning('Failed to create Stripe SetupIntent', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return $this->error('Could not start card setup. Please try again.', 502, 'stripe_setup_failed');
        }

        return $this->success([
            'client_secret' => $intent->client_secret,
            'setup_intent_id' => $intent->id,
        ], 'Setup intent created.');
    }

    /**
     * POST /api/v1/users/payment-method
     *
     * Persists a reusable Stripe PaymentMethod that the frontend already
     * confirmed via the SetupIntent + Stripe Elements. We attach it to the
     * customer, set it as the default for off-session charges, and store only
     * display metadata (brand / last4 / expiry) plus the pm_... handle.
     *
     * No raw PAN / CVV is ever transmitted to or stored by the backend.
     */
    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        if (! $this->stripe->configured()) {
            return $this->error('Card setup is temporarily unavailable.', 503, 'stripe_not_configured');
        }

        $user    = $request->user();
        $billing = $user->billingInformation;

        if (! $billing) {
            return $this->error(
                'Please complete your billing address before adding a payment method.',
                422,
                'billing_address_required'
            );
        }

        $paymentMethodId = $request->input('payment_method_id');

        try {
            $customerId = $this->stripe->ensureCustomer($user);
            $pm = $this->stripe->attachPaymentMethod($customerId, $paymentMethodId);
        } catch (\Throwable $e) {
            Log::warning('Failed to attach Stripe PaymentMethod', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return $this->error('We could not save that card. Please try again.', 502, 'stripe_attach_failed');
        }

        $card = $pm->card ?? null;

        $billing->update([
            'cardholder_name'          => $request->input('cardholder_name', $pm->billing_details->name ?? $billing->cardholder_name),
            'stripe_payment_method_id' => $pm->id,
            'card_brand'               => $card->brand ?? $billing->card_brand,
            'card_last_four'           => $card->last4 ?? $billing->card_last_four,
            'card_expiry_month'        => $card->exp_month ?? $billing->card_expiry_month,
            'card_expiry_year'         => $card->exp_year ?? $billing->card_expiry_year,
            'payment_method_added'     => true,
        ]);

        return $this->success(
            new UserResource($user->fresh()->load('billingInformation')),
            'Payment method saved.'
        );
    }
}
