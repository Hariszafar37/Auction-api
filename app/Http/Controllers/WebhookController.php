<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Mail\PaymentReceived;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class WebhookController extends Controller
{
    /**
     * POST /webhook/stripe
     *
     * Verifies Stripe signature and dispatches handling by event type.
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
        $payment = InvoicePayment::where('reference', $paymentIntent->id)
            ->where('status', 'pending')
            ->first();

        if (! $payment) {
            // Could be a deposit PI not yet linked, or already processed
            Log::info('Stripe webhook: no pending InvoicePayment found for PI', [
                'payment_intent_id' => $paymentIntent->id,
            ]);
            return;
        }

        DB::transaction(function () use ($payment, $paymentIntent) {
            $payment->update([
                'status'       => 'completed',
                'processed_at' => now(),
            ]);

            $invoice = $payment->invoice;
            $invoice->recalculateBalance();

            // If this was the deposit intent, mark deposit as captured
            if ($invoice->stripe_deposit_intent_id === $paymentIntent->id) {
                $invoice->update(['deposit_status' => 'captured']);
            }
        });

        // Queue payment receipt email — reload fresh invoice after balance update
        $invoice = $payment->fresh()->invoice->load(['buyer', 'vehicle', 'auction', 'lot', 'payments']);
        Mail::to($invoice->buyer->email)->queue(new PaymentReceived($invoice, $payment->fresh()));
    }

    private function handlePaymentFailed(object $paymentIntent): void
    {
        $payment = InvoicePayment::where('reference', $paymentIntent->id)->first();

        if (! $payment) {
            return;
        }

        $payment->update(['status' => 'failed']);

        // Mark deposit as failed if this was the deposit intent
        $invoice = $payment->invoice;
        if ($invoice->stripe_deposit_intent_id === $paymentIntent->id) {
            $invoice->update(['deposit_status' => 'failed']);
        }
    }
}
