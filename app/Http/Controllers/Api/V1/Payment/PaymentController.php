<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\InitiatePaymentRequest;
use App\Http\Resources\Payment\InvoicePaymentResource;
use App\Http\Resources\Payment\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

class PaymentController extends Controller
{
    /**
     * POST /my/invoices/{invoice}/pay
     *
     * - stripe_card: creates a Stripe PaymentIntent; returns client_secret for frontend confirmation
     * - cash/check: admin-only; records payment and marks invoice accordingly
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
        $amount = (float) $request->input('amount');

        if ($amount > (float) $invoice->balance_due) {
            return $this->error('Payment amount exceeds balance due.', 422, 'amount_exceeds_balance');
        }

        return match($method) {
            'stripe_card' => $this->initiateStripePayment($request, $invoice, $amount),
            'cash', 'check', 'other' => $this->recordOfflinePayment($request, $invoice, $amount),
            default => $this->error('Unsupported payment method.', 422),
        };
    }

    /**
     * POST /my/invoices/{invoice}/pay/confirm
     * Called after Stripe confirms the PaymentIntent on the frontend.
     */
    public function confirm(Invoice $invoice, InvoicePayment $payment): JsonResponse
    {
        if ($invoice->buyer_id !== request()->user()->id) {
            return $this->error('Invoice not found.', 404);
        }

        $stripe = new StripeClient(config('services.stripe.secret'));
        $pi     = $stripe->paymentIntents->retrieve($payment->reference);

        if ($pi->status !== 'succeeded') {
            return $this->error('Payment has not succeeded yet.', 422, 'payment_not_confirmed');
        }

        DB::transaction(function () use ($payment, $invoice) {
            $payment->update([
                'status'       => 'completed',
                'processed_at' => now(),
            ]);
            $invoice->recalculateBalance();
        });

        return $this->success(
            new InvoiceResource($invoice->fresh()->load(['lot', 'auction', 'vehicle', 'payments'])),
            'Payment confirmed.'
        );
    }

    // ─── Admin: record offline payment ───────────────────────────────────────────

    /**
     * POST /admin/invoices/{invoice}/record-payment
     * Admin records a cash or check payment on behalf of a buyer.
     */
    public function recordOfflineAsAdmin(InitiatePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        if (! $invoice->isOpen()) {
            return $this->error('This invoice is not open for payment.', 422, 'invoice_not_open');
        }

        return $this->recordOfflinePayment($request, $invoice, (float) $request->input('amount'));
    }

    // ─── Admin: update storage days ──────────────────────────────────────────────

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

    private function initiateStripePayment(InitiatePaymentRequest $request, Invoice $invoice, float $amount): JsonResponse
    {
        $stripe = new StripeClient(config('services.stripe.secret'));

        $pi = $stripe->paymentIntents->create([
            'amount'   => (int) round($amount * 100), // Stripe uses cents
            'currency' => 'usd',
            'metadata' => [
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'buyer_id'       => $invoice->buyer_id,
            ],
            'description' => "Invoice {$invoice->invoice_number}",
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

        return $this->success(
            new InvoicePaymentResource($payment),
            'Payment initiated. Complete card confirmation on the frontend.',
            201
        );
    }

    private function recordOfflinePayment(InitiatePaymentRequest $request, Invoice $invoice, float $amount): JsonResponse
    {
        if (! $request->user()->hasRole('admin')) {
            return $this->error('Offline payments must be recorded by an admin.', 403);
        }

        $payment = DB::transaction(function () use ($request, $invoice, $amount) {
            $p = InvoicePayment::create([
                'invoice_id'   => $invoice->id,
                'user_id'      => $invoice->buyer_id,
                'method'       => $request->input('method'),
                'amount'       => $amount,
                'reference'    => $request->input('reference'),
                'status'       => 'completed',
                'notes'        => $request->input('notes'),
                'processed_at' => now(),
                'processed_by' => $request->user()->id,
            ]);

            $invoice->recalculateBalance();
            return $p;
        });

        return $this->success(
            new InvoicePaymentResource($payment),
            'Payment recorded.',
            201
        );
    }
}
