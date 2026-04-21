<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Mail\PaymentReceived;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

class WebhookController extends Controller
{
    /**
     * POST /webhook/stripe
     *
     * FIX 2 (single source of truth): This is the ONLY place that marks an invoice as paid,
     * creates the completion InvoicePayment record, and sends the receipt email.
     * Always returns 200 — Stripe retries on non-2xx responses.
     */
    public function stripe(Request $request): Response
    {
        $secret    = config('services.stripe.webhook_secret');
        $signature = $request->header('Stripe-Signature', '');
        $payload   = $request->getContent();

        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);
            return response('Invalid signature.', 400);
        } catch (\UnexpectedValueException $e) {
            Log::warning('Stripe webhook invalid payload', ['error' => $e->getMessage()]);
            return response('Invalid payload.', 400);
        }

        match ($event->type) {
            'payment_intent.succeeded'      => $this->handlePaymentSucceeded($event->data->object),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($event->data->object),
            default                         => null, // unhandled events — still return 200
        };

        return response('OK', 200);
    }

    // ─── Handlers ────────────────────────────────────────────────────────────────

    private function handlePaymentSucceeded(object $paymentIntent): void
    {
        // Deposit PIs have status='authorized' — only process regular pending payments here
        $payment = InvoicePayment::where('reference', $paymentIntent->id)
            ->where('status', 'pending')
            ->first();

        if (! $payment) {
            Log::info('Stripe webhook: no pending InvoicePayment found for PI', [
                'payment_intent_id' => $paymentIntent->id,
            ]);
            return;
        }

        $invoice = $payment->invoice;

        // FIX 2: idempotency guard — prevents double-processing if webhook fires twice
        if ($invoice->isPaid()) {
            Log::info('Stripe webhook: invoice already paid, skipping', ['invoice_id' => $invoice->id]);
            return;
        }

        try {
            DB::transaction(function () use ($payment, $invoice, $paymentIntent) {
                $payment->update([
                    'status'       => 'completed',
                    'processed_at' => now(),
                ]);

                // recalculateBalance sets invoice status to Paid/Partial
                $invoice->recalculateBalance();

                // FIX 3: capture the deposit hold once invoice is fully paid
                if (
                    $invoice->fresh()->isPaid()
                    && $invoice->stripe_deposit_intent_id
                    && $invoice->deposit_status === 'authorized'
                ) {
                    $stripe = new StripeClient(config('services.stripe.secret'));
                    $stripe->paymentIntents->capture($invoice->stripe_deposit_intent_id);

                    InvoicePayment::where('reference', $invoice->stripe_deposit_intent_id)
                        ->update(['status' => 'completed', 'processed_at' => now()]);

                    $invoice->update([
                        'deposit_status'      => 'captured',
                        'deposit_captured_at' => now(),
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            // Webhook fired twice — duplicate blocked by unique constraint on reference column.
            // Return silently so Stripe gets a 200 and stops retrying.
            Log::info('Stripe webhook: duplicate PI suppressed by unique constraint', [
                'payment_intent_id' => $paymentIntent->id,
            ]);
            return;
        }

        // Queue receipt email — reload fresh invoice after all mutations
        $invoice = $payment->fresh()->invoice->load(['buyer', 'vehicle', 'auction', 'lot', 'payments']);
        if ($invoice->buyer?->email) {
            Mail::to($invoice->buyer->email)->queue(new PaymentReceived($invoice, $payment->fresh()));
        }
    }

    private function handlePaymentFailed(object $paymentIntent): void
    {
        $payment = InvoicePayment::where('reference', $paymentIntent->id)->first();

        if (! $payment) {
            return;
        }

        $payment->update(['status' => 'failed']);

        $invoice = $payment->invoice;
        if ($invoice->stripe_deposit_intent_id === $paymentIntent->id) {
            $invoice->update(['deposit_status' => 'failed']);
        }
    }
}
