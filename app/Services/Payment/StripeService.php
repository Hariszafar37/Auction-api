<?php

namespace App\Services\Payment;

use App\Models\User;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Refund;
use Stripe\SetupIntent;
use Stripe\StripeClient;

/**
 * Thin, injectable wrapper around the Stripe SDK.
 *
 * Centralises every Stripe call required by the real-deposit flow so that:
 *   - Stripe is only ever touched from the service layer (per CLAUDE.md), and
 *   - the gateway can be swapped for a fake in tests via the container, keeping
 *     the suite offline and deterministic.
 *
 * The underlying StripeClient is built lazily from config so that constructing
 * the service with no secret configured (tests, local without keys) never throws.
 */
class StripeService
{
    private ?StripeClient $client = null;

    /**
     * Whether Stripe is configured (a secret key is present).
     */
    public function configured(): bool
    {
        return (bool) config('services.stripe.secret');
    }

    /**
     * Resolve (and memoise) the Stripe client. Throws if no secret is set —
     * callers must guard with configured() first for non-fatal paths.
     */
    protected function client(): StripeClient
    {
        if (! $this->configured()) {
            throw new \RuntimeException('Stripe is not configured.');
        }

        return $this->client ??= new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Ensure the user has a Stripe Customer, creating one if needed.
     * Persists the new id onto the user. Returns the customer id.
     */
    public function ensureCustomer(User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = $this->client()->customers->create([
            'email'    => $user->email,
            'name'     => $user->name,
            'metadata' => ['user_id' => $user->id],
        ]);

        $user->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }

    /**
     * Create a SetupIntent for collecting a reusable card off-session.
     * Returns the SetupIntent (id + client_secret consumed by the frontend).
     */
    public function createSetupIntent(User $user): SetupIntent
    {
        $customerId = $this->ensureCustomer($user);

        return $this->client()->setupIntents->create([
            'customer'             => $customerId,
            'usage'                => 'off_session',
            'payment_method_types' => ['card'],
            'metadata'             => ['user_id' => $user->id],
        ]);
    }

    /**
     * Attach a confirmed PaymentMethod to the customer and set it as the default
     * for future (off-session) invoice charges. Returns the PaymentMethod so the
     * caller can persist display metadata (brand / last4 / expiry).
     */
    public function attachPaymentMethod(string $customerId, string $paymentMethodId): PaymentMethod
    {
        $pm = $this->client()->paymentMethods->retrieve($paymentMethodId);

        // Attach only if not already attached to this customer (idempotent re-saves).
        if ($pm->customer !== $customerId) {
            $pm = $this->client()->paymentMethods->attach($paymentMethodId, [
                'customer' => $customerId,
            ]);
        }

        // Always key off the id Stripe returned, never the one we were handed:
        // attaching a shared test token (pm_card_visa) mints a NEW pm_... and the
        // token itself is not a valid default_payment_method.
        $this->client()->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $pm->id],
        ]);

        return $pm;
    }

    /**
     * Charge a saved PaymentMethod off-session (buyer not present).
     * Confirmed immediately; capture is automatic (capture-as-prepayment).
     *
     * Throws Stripe\Exception\CardException on decline and
     * Stripe\Exception\ApiErrorException (with code 'authentication_required')
     * when SCA is needed — callers decide the fallback.
     */
    public function chargeOffSession(
        string $customerId,
        string $paymentMethodId,
        int $amountCents,
        array $metadata,
        string $idempotencyKey,
        ?string $description = null,
    ): PaymentIntent {
        return $this->client()->paymentIntents->create([
            'amount'         => $amountCents,
            'currency'       => 'usd',
            'customer'       => $customerId,
            'payment_method' => $paymentMethodId,
            'off_session'    => true,
            'confirm'        => true,
            'capture_method' => 'automatic',
            'metadata'       => $metadata,
            'description'    => $description,
        ], [
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * Create an UNCONFIRMED PaymentIntent for a buyer who is present in the
     * browser, to be confirmed client-side by Stripe.js.
     *
     * Passing $customerId + $paymentMethodId attaches the buyer's saved card so
     * the frontend can confirm without re-collecting it; omitting both leaves an
     * anonymous intent that the CardElement supplies a card to. Either way the
     * intent is NOT confirmed here — the buyer is on-session, so Stripe.js runs
     * any 3-D Secure challenge inline and the webhook stays the single source of
     * truth for marking an invoice paid.
     *
     * Contrast with chargeOffSession(), which confirms immediately for a buyer
     * who is NOT present (the deposit flow).
     */
    public function createUnconfirmedPaymentIntent(
        int $amountCents,
        array $metadata,
        string $idempotencyKey,
        ?string $description = null,
        ?string $customerId = null,
        ?string $paymentMethodId = null,
    ): PaymentIntent {
        $params = [
            'amount'      => $amountCents,
            'currency'    => 'usd',
            'metadata'    => $metadata,
            'description' => $description,
        ];

        if ($customerId !== null && $paymentMethodId !== null) {
            $params['customer']       = $customerId;
            $params['payment_method'] = $paymentMethodId;
        }

        return $this->client()->paymentIntents->create($params, [
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * Refund a captured PaymentIntent (full or partial). Used when an admin
     * cancels/voids a sale whose deposit was already captured.
     */
    public function refund(string $paymentIntentId, ?int $amountCents = null): Refund
    {
        $params = ['payment_intent' => $paymentIntentId];
        if ($amountCents !== null) {
            $params['amount'] = $amountCents;
        }

        return $this->client()->refunds->create($params);
    }

    /**
     * Cancel an uncaptured PaymentIntent (legacy 'authorized' hold path).
     */
    public function cancelPaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return $this->client()->paymentIntents->cancel($paymentIntentId);
    }
}
