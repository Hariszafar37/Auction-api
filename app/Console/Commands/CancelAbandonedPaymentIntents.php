<?php

namespace App\Console\Commands;

use App\Models\InvoicePayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Reconciles card payment attempts that were started but never completed.
 *
 * A buyer who opens the card form and walks away leaves a PaymentIntent sitting
 * at 'requires_payment_method' — "Incomplete" in the Stripe dashboard — and an
 * InvoicePayment row stuck at 'pending'. No terminal webhook ever fires for that
 * state, so without this sweeper the row would look like a payment in flight
 * indefinitely.
 *
 * This command closes the loop from both ends: it cancels the intent in Stripe
 * (which also emits payment_intent.canceled, keeping the two systems in sync even
 * if this process dies mid-run) and marks the local row 'canceled'.
 *
 * Safety: the PaymentIntent's live status is re-read from Stripe and only the two
 * states that provably hold no money are ever cancelled. Anything that is
 * processing, awaiting 3-D Secure, authorised, or succeeded is left untouched.
 */
class CancelAbandonedPaymentIntents extends Command
{
    protected $signature = 'payments:cancel-abandoned-intents
                            {--minutes=60 : Minimum age, in minutes, before an attempt is considered abandoned}
                            {--dry-run : Report what would be cancelled without changing anything}';

    protected $description = 'Cancel Stripe PaymentIntents for card attempts the buyer never completed';

    /**
     * PaymentIntent statuses that are safe to cancel: no payment method has been
     * successfully attached and confirmed, so no funds are held or captured.
     */
    private const CANCELLABLE_PI_STATUSES = ['requires_payment_method', 'requires_confirmation'];

    /**
     * Stripe PI statuses that are already terminal-cancelled on their side.
     */
    private const ALREADY_CANCELED = 'canceled';

    public function handle(): int
    {
        if (! config('services.stripe.secret')) {
            $this->warn('Stripe is not configured — nothing to reconcile.');
            return self::SUCCESS;
        }

        $minutes = max(1, (int) $this->option('minutes'));
        $dryRun  = (bool) $this->option('dry-run');

        // Only 'pending' rows are actionable; 'canceled' ones are already settled.
        $abandoned = InvoicePayment::query()
            ->where('method', 'stripe_card')
            ->where('status', 'pending')
            ->whereNotNull('reference')
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->get();

        if ($abandoned->isEmpty()) {
            $this->info('No abandoned card payment attempts found.');
            return self::SUCCESS;
        }

        $stripe    = new StripeClient(config('services.stripe.secret'));
        $cancelled = 0;
        $skipped   = 0;

        foreach ($abandoned as $payment) {
            try {
                $pi = $stripe->paymentIntents->retrieve($payment->reference);

                // Stripe already cancelled it (its own 24h auto-cancel). Nothing to
                // call — just bring the local row to the same terminal state.
                if ($pi->status === self::ALREADY_CANCELED) {
                    $this->settle($payment, $dryRun);
                    $cancelled++;
                    continue;
                }

                if (! in_array($pi->status, self::CANCELLABLE_PI_STATUSES, true)) {
                    // In flight, awaiting SCA, authorised or already paid — leave it
                    // strictly alone and let the regular webhooks resolve it.
                    $this->line("  skip  payment #{$payment->id} — intent is '{$pi->status}'");
                    $skipped++;
                    continue;
                }

                if (! $dryRun) {
                    $stripe->paymentIntents->cancel($payment->reference, [
                        'cancellation_reason' => 'abandoned',
                    ]);
                }

                $this->settle($payment, $dryRun);
                $cancelled++;
            } catch (\Throwable $e) {
                // Never let one bad row abort the sweep — the next run retries it.
                $skipped++;
                Log::warning('Abandoned PI cleanup failed for a payment (non-fatal)', [
                    'payment_id'        => $payment->id,
                    'invoice_id'        => $payment->invoice_id,
                    'payment_intent_id' => $payment->reference,
                    'error'             => $e->getMessage(),
                ]);
            }
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}Cancelled {$cancelled} abandoned card attempt(s); skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Bring the local row to its terminal state. The client_secret is cleared so a
     * stale secret can never be handed back to a browser.
     */
    private function settle(InvoicePayment $payment, bool $dryRun): void
    {
        if ($dryRun) {
            $this->line("  would cancel  payment #{$payment->id} ({$payment->reference})");
            return;
        }

        $payment->update([
            'status'               => 'canceled',
            'stripe_client_secret' => null,
        ]);

        $this->line("  cancelled  payment #{$payment->id} ({$payment->reference})");
    }
}
