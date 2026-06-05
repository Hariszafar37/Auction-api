<?php

namespace App\Services\Payment;

use App\Enums\InvoiceStatus;
use App\Mail\DepositActionRequired;
use App\Mail\InvoiceCreated;
use App\Models\AuctionLot;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InvoiceService
{
    public function __construct(
        private readonly FeeCalculationService $fees,
        private readonly StripeService $stripe,
    ) {}

    /**
     * Create an invoice for a won auction lot.
     * Idempotent — returns existing invoice if already created for this lot.
     * Also initiates Stripe deposit hold and queues the InvoiceCreated notification.
     */
    public function createForLot(AuctionLot $lot): Invoice
    {
        // Idempotency guard
        $existing = Invoice::where('lot_id', $lot->id)->first();
        if ($existing) {
            return $existing;
        }

        $lot->loadMissing(['auction', 'vehicle', 'buyer']);

        $salePrice = $lot->sold_price;
        $location  = $lot->auction?->location;

        $breakdown = $this->fees->calculate($salePrice, $location);

        $invoice = DB::transaction(function () use ($lot, $salePrice, $breakdown) {
            return Invoice::create([
                'invoice_number'     => $this->generateInvoiceNumber(),
                'lot_id'             => $lot->id,
                'auction_id'         => $lot->auction_id,
                'buyer_id'           => $lot->buyer_id,
                'vehicle_id'         => $lot->vehicle_id,
                'sale_price'                 => $salePrice,
                'deposit_amount'             => $breakdown['deposit_amount'],
                'buyer_fee_amount'           => $breakdown['buyer_fee_amount'],
                'tax_amount'                 => $breakdown['tax_amount'],
                'tags_amount'                => $breakdown['tags_amount'],
                'storage_days'               => 0,
                'storage_fee_amount'         => 0,
                'online_platform_fee_amount' => $breakdown['online_platform_fee_amount'],
                'total_amount'               => $breakdown['total_amount'],
                'amount_paid'        => 0,
                'balance_due'        => $breakdown['total_amount'],
                'status'             => InvoiceStatus::Pending,
                'fee_snapshot'       => $breakdown['snapshot'],
                'due_at'             => now()->addDays(7),
            ]);
        });

        // Capture-as-prepayment: charge the deposit off-session on win using the
        // buyer's saved card. Credited toward the invoice total (never additive).
        // Non-fatal — a failure here never blocks invoice creation or the win flow.
        if ($breakdown['deposit_amount'] > 0 && $this->stripe->configured()) {
            $this->chargeDeposit($invoice, (float) $breakdown['deposit_amount']);
        }

        // FIX 7: Queue invoice created notification email
        $invoice->loadMissing(['buyer', 'vehicle', 'lot']);
        if ($invoice->buyer?->email) {
            Mail::to($invoice->buyer->email)->queue(new InvoiceCreated($invoice));
        }

        return $invoice;
    }

    /**
     * Recalculate storage fees and update the invoice.
     * Called when admin updates storage days after initial invoice creation.
     */
    public function updateStorage(Invoice $invoice, int $storageDays): Invoice
    {
        $lot      = $invoice->lot()->with('auction')->first();
        $location = $lot?->auction?->location;

        $breakdown = $this->fees->calculate(
            $invoice->sale_price,
            $location,
            $storageDays
        );

        $invoice->update([
            'storage_days'       => $storageDays,
            'storage_fee_amount' => $breakdown['storage_fee_amount'],
            'total_amount'       => $breakdown['total_amount'],
            'balance_due'        => max(0, $breakdown['total_amount'] - (float) $invoice->amount_paid),
            'fee_snapshot'       => $breakdown['snapshot'],
        ]);

        // Re-evaluate status after update
        $invoice->recalculateBalance();

        return $invoice->fresh();
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    /**
     * Charge the deposit off-session against the buyer's saved card and credit it
     * to the invoice (capture-as-prepayment). Idempotent and fully non-fatal:
     *   - never charges twice (DB guard + Stripe idempotency key),
     *   - never throws into the win/invoice-creation flow.
     *
     * Outcomes:
     *   success            → deposit_status=captured, completed InvoicePayment,
     *                        balance_due = total − deposit
     *   declined / error   → deposit_status=failed, buyer notified to fix card
     *   SCA required       → deposit_status=requires_action, buyer notified to
     *                        authenticate (client_secret stored for retry)
     *   no saved card      → deposit_status=failed, buyer notified
     */
    public function chargeDeposit(Invoice $invoice, float $depositAmount): void
    {
        // Idempotency: never re-charge a captured (or already-credited) deposit.
        if ($invoice->deposit_status === 'captured') {
            return;
        }
        if ($invoice->payments()->where('method', 'deposit')->where('status', 'completed')->exists()) {
            return;
        }

        $invoice->loadMissing('buyer.billingInformation');
        $buyer = $invoice->buyer;
        $pmId  = $buyer?->billingInformation?->stripe_payment_method_id;

        if (! $buyer || ! $pmId || ! $buyer->stripe_customer_id) {
            $invoice->update(['deposit_status' => 'failed']);
            $this->notifyDepositActionRequired($invoice, 'no_payment_method');
            return;
        }

        try {
            $pi = $this->stripe->chargeOffSession(
                $buyer->stripe_customer_id,
                $pmId,
                (int) round($depositAmount * 100),
                [
                    'invoice_id'     => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'buyer_id'       => $buyer->id,
                    'type'           => 'deposit',
                ],
                'deposit_pi_' . $invoice->id,
                "Deposit — Invoice {$invoice->invoice_number}",
            );
        } catch (\Stripe\Exception\CardException $e) {
            $error       = $e->getError();
            $needsAction = ($e->getStripeCode() === 'authentication_required');
            $pi          = $error?->payment_intent ?? null;

            $this->recordDepositAttempt(
                $invoice,
                $buyer,
                $depositAmount,
                $needsAction ? 'requires_action' : 'failed',
                $pi->id ?? null,
                $pi->client_secret ?? null,
            );
            $invoice->update([
                'stripe_deposit_intent_id' => $pi->id ?? $invoice->stripe_deposit_intent_id,
                'deposit_status'           => $needsAction ? 'requires_action' : 'failed',
            ]);
            $this->notifyDepositActionRequired($invoice, $needsAction ? 'requires_action' : 'declined');
            return;
        } catch (\Throwable $e) {
            Log::warning('Deposit charge failed (non-fatal)', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);
            $invoice->update(['deposit_status' => 'failed']);
            $this->notifyDepositActionRequired($invoice, 'failed');
            return;
        }

        $this->finalizeCapturedDeposit($invoice, $buyer, $depositAmount, $pi->id);
    }

    /**
     * Retry a failed/requires-action deposit charge (e.g. after the buyer fixes
     * their card). Uses a fresh idempotency key so Stripe re-evaluates the card.
     * Returns true on a successful capture.
     */
    public function retryDeposit(Invoice $invoice): bool
    {
        if ($invoice->deposit_status === 'captured' || (float) $invoice->deposit_amount <= 0) {
            return $invoice->deposit_status === 'captured';
        }
        if (! $this->stripe->configured()) {
            return false;
        }

        $invoice->loadMissing('buyer.billingInformation');
        $buyer = $invoice->buyer;
        $pmId  = $buyer?->billingInformation?->stripe_payment_method_id;

        if (! $buyer || ! $pmId || ! $buyer->stripe_customer_id) {
            return false;
        }

        try {
            $pi = $this->stripe->chargeOffSession(
                $buyer->stripe_customer_id,
                $pmId,
                (int) round((float) $invoice->deposit_amount * 100),
                [
                    'invoice_id'     => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'buyer_id'       => $buyer->id,
                    'type'           => 'deposit',
                ],
                'deposit_pi_' . $invoice->id . '_retry_' . now()->timestamp,
                "Deposit (retry) — Invoice {$invoice->invoice_number}",
            );
        } catch (\Throwable $e) {
            Log::warning('Deposit retry failed', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }

        $this->finalizeCapturedDeposit($invoice, $buyer, (float) $invoice->deposit_amount, $pi->id);
        return true;
    }

    /**
     * Record a successful capture: flip/seed the deposit InvoicePayment to
     * completed and recalc the balance so the deposit is credited toward total.
     */
    private function finalizeCapturedDeposit(Invoice $invoice, User $buyer, float $amount, string $piId): void
    {
        DB::transaction(function () use ($invoice, $buyer, $amount, $piId) {
            InvoicePayment::updateOrCreate(
                ['invoice_id' => $invoice->id, 'method' => 'deposit'],
                [
                    'user_id'              => $buyer->id,
                    'amount'               => $amount,
                    'reference'            => $piId,
                    'stripe_client_secret' => null,
                    'status'               => 'completed',
                    'processed_at'         => now(),
                ]
            );

            $invoice->update([
                'stripe_deposit_intent_id' => $piId,
                'deposit_status'           => 'captured',
                'deposit_captured_at'      => now(),
            ]);

            $invoice->recalculateBalance();
        });
    }

    /**
     * Seed/flip the deposit InvoicePayment to a non-crediting status
     * (failed / requires_action) for history + SCA retry.
     */
    private function recordDepositAttempt(
        Invoice $invoice,
        User $buyer,
        float $amount,
        string $status,
        ?string $piId,
        ?string $clientSecret = null,
    ): void {
        InvoicePayment::updateOrCreate(
            ['invoice_id' => $invoice->id, 'method' => 'deposit'],
            [
                'user_id'              => $buyer->id,
                'amount'               => $amount,
                'reference'            => $piId,
                'stripe_client_secret' => $clientSecret,
                'status'               => $status,
            ]
        );
    }

    /**
     * Queue the buyer notification asking them to fix their card / authenticate.
     * Non-fatal — a mail failure must never affect the deposit flow.
     */
    private function notifyDepositActionRequired(Invoice $invoice, string $reason): void
    {
        try {
            $invoice->loadMissing(['buyer', 'vehicle', 'lot']);
            if ($invoice->buyer?->email) {
                Mail::to($invoice->buyer->email)->queue(new DepositActionRequired($invoice, $reason));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to queue deposit action-required email', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function generateInvoiceNumber(): string
    {
        $year    = now()->year;
        $lastInv = Invoice::whereYear('created_at', $year)->lockForUpdate()->count();
        $seq     = str_pad($lastInv + 1, 5, '0', STR_PAD_LEFT);
        return "INV-{$year}-{$seq}";
    }
}
