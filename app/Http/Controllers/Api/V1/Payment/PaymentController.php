<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\AcknowledgePaymentRequest;
use App\Http\Requests\Payment\InitiatePaymentRequest;
use App\Http\Requests\Payment\SubmitNonCardPaymentRequest;
use App\Http\Resources\Payment\InvoicePaymentResource;
use App\Http\Resources\Payment\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentSetting;
use App\Services\Payment\NonCardPaymentService;
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

        // Partial-payment support is fully opt-in: without an `amount` the flow is
        // byte-for-byte identical to before (full balance, shared idempotency key,
        // reuse of any pending card payment). An `amount` enables a partial charge
        // while keeping each distinct amount idempotent and reusable on its own.
        $requestedAmount = $request->input('amount');

        if ($requestedAmount === null) {
            // ── DEFAULT PATH (unchanged) ──────────────────────────────────────
            $existing = $invoice->payments()
                ->where('method', 'stripe_card')
                ->where('status', 'pending')
                ->first();

            $amount         = (float) $invoice->balance_due;
            $idempotencyKey = 'invoice_pi_' . $invoice->id;
        } else {
            // ── PARTIAL PATH (opt-in) ─────────────────────────────────────────
            $request->validate(['amount' => ['numeric', 'min:0.01']]);
            $amount = round((float) $requestedAmount, 2);

            if ($amount > (float) $invoice->balance_due) {
                return $this->error('Payment amount exceeds balance due.', 422, 'amount_exceeds_balance');
            }

            $existing = $invoice->payments()
                ->where('method', 'stripe_card')
                ->where('status', 'pending')
                ->where('amount', $amount)
                ->first();

            $idempotencyKey = 'invoice_pi_' . $invoice->id . '_' . (int) round($amount * 100);
        }

        if ($existing && $existing->stripe_client_secret) {
            return $this->success([
                'payment_id'    => $existing->id,
                'client_secret' => $existing->stripe_client_secret,
                'invoice_id'    => $invoice->id,
            ]);
        }

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
            'idempotency_key' => $idempotencyKey,
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
     * GET /my/invoices/{invoice}/payment-options
     *
     * Returns the enabled payment methods plus the admin-configured instructions
     * and acknowledgment copy the buyer needs to proceed with a non-card method.
     * Disabled methods are omitted entirely.
     */
    public function paymentOptions(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->buyer_id !== $request->user()->id && ! $request->user()->hasRole('admin')) {
            return $this->error('Invoice not found.', 404);
        }

        $s = PaymentSetting::current();

        $methods = [];
        if ($s->method_stripe_enabled) {
            $methods['stripe_card'] = ['enabled' => true];
        }
        if ($s->method_wire_enabled) {
            $methods['wire'] = [
                'enabled'      => true,
                'instructions' => [
                    'beneficiary_name' => $s->wire_beneficiary_name,
                    'bank_name'        => $s->wire_bank_name,
                    'routing_number'   => $s->wire_routing_number,
                    'account_number'   => $s->wire_account_number,
                    'swift_code'       => $s->wire_swift_code,
                    'business_address' => $s->wire_business_address,
                    'notes'            => $s->wire_notes,
                ],
            ];
        }
        if ($s->method_cash_enabled) {
            $methods['cash'] = [
                'enabled'      => true,
                'instructions' => [
                    'location'     => $s->payment_location,
                    'office_hours' => $s->office_hours,
                ],
            ];
        }
        if ($s->method_check_enabled) {
            $methods['check'] = [
                'enabled'      => true,
                'instructions' => [
                    'payable_to'        => $s->check_payable_to,
                    'mailing_address'   => $s->check_mailing_address,
                    'accepted_carriers' => $s->accepted_carriers ?? [],
                ],
            ];
        }

        $acknowledged = $invoice->acknowledgements()
            ->where('user_id', $request->user()->id)
            ->pluck('payment_method')
            ->map(fn ($m) => $m instanceof PaymentMethod ? $m->value : $m)
            ->unique()
            ->values();

        return $this->success([
            'deadline_notice'      => $s->payment_deadline_notice,
            'acknowledgment_text'  => $s->acknowledgment_text,
            'business_days_to_pay' => (int) $s->business_days_to_pay,
            'late_fee_amount'      => (float) $s->late_fee_amount,
            'methods'              => $methods,
            'acknowledged_methods' => $acknowledged,
            'balance_due'          => (float) $invoice->balance_due,
        ]);
    }

    /**
     * POST /my/invoices/{invoice}/acknowledge
     *
     * Records the buyer's acknowledgment of the payment requirements + release
     * restrictions (with IP / user-agent for audit) and applies the non-card
     * 2-business-day deadline. Required before submitting a non-card payment.
     */
    public function acknowledge(AcknowledgePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->buyer_id !== $request->user()->id) {
            return $this->error('Invoice not found.', 404);
        }

        if (! $invoice->isOpen()) {
            return $this->error('This invoice is not open for payment.', 422, 'invoice_not_open');
        }

        $method = PaymentMethod::from($request->input('payment_method'));

        if (! $this->methodEnabled($method)) {
            return $this->error('This payment method is currently unavailable.', 422, 'method_disabled');
        }

        app(NonCardPaymentService::class)->acknowledge($invoice, $method, $request);

        return $this->success(
            new InvoiceResource($invoice->fresh()->load(['lot', 'auction', 'vehicle', 'payments'])),
            'Payment requirements acknowledged.',
            201
        );
    }

    /**
     * POST /my/invoices/{invoice}/submit-payment
     *
     * Buyer self-reports a Wire / Cash / Cashier's Check payment. Creates a
     * pending_verification ledger entry — it does NOT change the balance or mark
     * the invoice paid. Staff must record/verify before anything is credited.
     */
    public function submitNonCard(SubmitNonCardPaymentRequest $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->buyer_id !== $request->user()->id) {
            return $this->error('Invoice not found.', 404);
        }

        if (! $invoice->isOpen()) {
            return $this->error('This invoice is not open for payment.', 422, 'invoice_not_open');
        }

        $method = PaymentMethod::from($request->input('method'));

        if (! $this->methodEnabled($method)) {
            return $this->error('This payment method is currently unavailable.', 422, 'method_disabled');
        }

        $service = app(NonCardPaymentService::class);

        if (! $service->hasAcknowledged($invoice, $method, $request->user()->id)) {
            return $this->error('Please acknowledge the payment requirements first.', 422, 'acknowledgment_required');
        }

        if ((float) $request->input('amount') > (float) $invoice->remaining_balance) {
            return $this->error('Payment amount exceeds balance due.', 422, 'amount_exceeds_balance');
        }

        $payment = $service->submit(
            $invoice,
            $method,
            (float) $request->input('amount'),
            $request->input('reference'),
            $request->input('notes'),
            $request->user()->id,
        );

        return $this->success(
            new InvoicePaymentResource($payment),
            'Payment submitted. It will be applied once verified by Colonial Auction Services.',
            201
        );
    }

    private function methodEnabled(PaymentMethod $method): bool
    {
        $s = PaymentSetting::current();

        return match ($method) {
            PaymentMethod::StripeCard => (bool) $s->method_stripe_enabled,
            PaymentMethod::Wire       => (bool) $s->method_wire_enabled,
            PaymentMethod::Cash       => (bool) $s->method_cash_enabled,
            PaymentMethod::Check      => (bool) $s->method_check_enabled,
            default                   => true,
        };
    }

    /**
     * POST /my/invoices/{invoice}/retry-deposit
     *
     * Re-attempts a failed / SCA-pending deposit charge after the buyer has
     * updated their card. Owner-only. Returns the refreshed invoice on success.
     */
    public function retryDeposit(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->buyer_id !== $request->user()->id && ! $request->user()->hasRole('admin')) {
            return $this->error('Invoice not found.', 404);
        }

        if (! in_array($invoice->deposit_status, ['failed', 'requires_action'], true)) {
            return $this->error('No deposit retry is pending for this invoice.', 422, 'deposit_not_retryable');
        }

        $ok = app(\App\Services\Payment\InvoiceService::class)->retryDeposit($invoice);

        if (! $ok) {
            return $this->error(
                'We still could not charge your deposit. Please check your card and try again.',
                422,
                'deposit_retry_failed'
            );
        }

        return $this->success(
            new InvoiceResource($invoice->fresh()->load(['lot', 'auction', 'vehicle', 'payments'])),
            'Deposit charged successfully.'
        );
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
