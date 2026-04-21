<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\InitiatePaymentRequest;
use App\Http\Resources\Payment\InvoicePaymentResource;
use App\Http\Resources\Payment\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

class PaymentController extends Controller
{
    /**
     * POST /my/invoices/{invoice}/payment-intent
     *
     * FIX 2 (single source of truth): This endpoint ONLY creates the Stripe PaymentIntent
     * and returns the client_secret to the frontend. It does NOT mark the invoice as paid.
     * Marking paid is handled exclusively by the Stripe webhook (payment_intent.succeeded).
     *
     * FIX 1 (idempotency): Uses Stripe idempotency key so repeat calls return the same PI.
     * Also checks for an existing pending payment to avoid creating duplicate InvoicePayment records.
     */
    public function createPaymentIntent(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->buyer_id !== $request->user()->id && ! $request->user()->hasRole('admin')) {
            return $this->error('Invoice not found.', 404);
        }

        if (! $invoice->isOpen()) {
            return $this->error('This invoice is not open for payment.', 422, 'invoice_not_open');
        }

        if (! config('services.stripe.secret')) {
            return $this->error('Card payments are temporarily unavailable.', 503, 'stripe_not_configured');
        }

        // Return existing pending Stripe payment if present (idempotency at DB level)
        $existing = $invoice->payments()
            ->where('method', 'stripe_card')
            ->where('status', 'pending')
            ->first();

        if ($existing && $existing->stripe_client_secret) {
            return $this->success([
                'payment_id'    => $existing->id,
                'client_secret' => $existing->stripe_client_secret,
                'invoice_id'    => $invoice->id,
            ]);
        }

        $amount = (float) $invoice->balance_due;
        $stripe = new StripeClient(config('services.stripe.secret'));

        // FIX 1: idempotency key prevents double-charge if this endpoint is called twice
        $pi = $stripe->paymentIntents->create([
            'amount'      => (int) round($amount * 100),
            'currency'    => 'usd',
            'metadata'    => [
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'buyer_id'       => $invoice->buyer_id,
            ],
            'description' => "Invoice {$invoice->invoice_number}",
        ], [
            'idempotency_key' => 'invoice_pi_' . $invoice->id,
        ]);

        $payment = InvoicePayment::create([
            'invoice_id'           => $invoice->id,
            'user_id'              => $request->user()->id,
            'method'               => 'stripe_card',
            'amount'               => $amount,
            'reference'            => $pi->id,
            'stripe_client_secret' => $pi->client_secret,
            'status'               => 'pending',
        ]);

        return $this->success([
            'payment_id'    => $payment->id,
            'client_secret' => $pi->client_secret,
            'invoice_id'    => $invoice->id,
        ], 'Payment intent created.', 201);
    }

    /**
     * POST /my/invoices/{invoice}/pay
     * Handles offline payments (cash, check, other) only.
     * Stripe card payments use POST /my/invoices/{invoice}/payment-intent.
     */
    public function pay(InitiatePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->buyer_id !== $request->user()->id && ! $request->user()->hasRole('admin')) {
            return $this->error('Invoice not found.', 404);
        }

        if (! $invoice->isOpen()) {
            return $this->error('This invoice is not open for payment.', 422, 'invoice_not_open');
        }

        $method = $request->input('method');
        if ($method === 'stripe_card') {
            return $this->error('Use POST /payment-intent for card payments.', 422, 'use_payment_intent');
        }

        $amount = (float) $request->input('amount');

        if ($amount > (float) $invoice->balance_due) {
            return $this->error('Payment amount exceeds balance due.', 422, 'amount_exceeds_balance');
        }

        return $this->recordOfflinePayment($request, $invoice, $amount);
    }

    /**
     * POST /admin/invoices/{invoice}/record-payment
     * Admin records a cash or check payment — creates pending_verification record.
     */
    public function recordOfflineAsAdmin(InitiatePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        if (! $invoice->isOpen()) {
            return $this->error('This invoice is not open for payment.', 422, 'invoice_not_open');
        }

        return $this->recordOfflinePayment($request, $invoice, (float) $request->input('amount'));
    }

    /**
     * PATCH /admin/invoices/{invoice}/storage
     */
    public function updateStorage(Invoice $invoice): JsonResponse
    {
        request()->validate(['storage_days' => ['required', 'integer', 'min:0']]);

        $service = app(\App\Services\Payment\InvoiceService::class);
        $updated = $service->updateStorage($invoice, (int) request()->input('storage_days'));

        return $this->success(
            new InvoiceResource($updated->load(['lot', 'auction', 'vehicle', 'payments'])),
            'Storage days updated.'
        );
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    private function recordOfflinePayment(InitiatePaymentRequest $request, Invoice $invoice, float $amount): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            return $this->error('Offline payments must be recorded by an admin.', 403);
        }

        $payment = InvoicePayment::create([
            'invoice_id'   => $invoice->id,
            'user_id'      => $invoice->buyer_id,
            'method'       => $request->input('method'),
            'amount'       => $amount,
            'reference'    => $request->input('reference'),
            'status'       => 'pending_verification',
            'notes'        => $request->input('notes'),
            'processed_by' => $request->user()->id,
        ]);

        return $this->success(
            new InvoicePaymentResource($payment),
            'Payment recorded. Awaiting admin verification.',
            201
        );
    }
}
