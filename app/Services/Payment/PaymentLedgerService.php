<?php

namespace App\Services\Payment;

use App\Enums\PaymentMethod;
use App\Enums\PaymentTransactionType;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writes immutable ledger entries (charge adjustments, reversals, refunds, late
 * fees) and keeps the invoice balance in sync. Every entry records who authored
 * and approved it; entries are created already-verified because they are
 * deliberate admin actions, not buyer submissions awaiting verification.
 */
class PaymentLedgerService
{
    /**
     * Apply a signed charge-side adjustment to what the buyer owes.
     * Positive increases the balance (a charge), negative decreases it (a credit/
     * discount). Recorded as an immutable ledger row and reflected in the
     * invoice's effective total.
     */
    public function applyAdjustment(Invoice $invoice, float $amount, string $reason, User $admin, ?string $feeType = null): InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $amount, $reason, $admin, $feeType) {
            $entry = InvoicePayment::create([
                'invoice_id'       => $invoice->id,
                'user_id'          => $invoice->buyer_id,
                'method'           => PaymentMethod::Other,
                'transaction_type' => PaymentTransactionType::Adjustment,
                'fee_type'         => $feeType,
                'amount'           => $amount,
                'status'           => 'verified',
                'notes'            => $reason,
                'created_by'       => $admin->id,
                'processed_by'     => $admin->id,
                'processed_at'     => now(),
                'approved_by'      => $admin->id,
                'approved_at'      => now(),
                'approval_note'    => $reason,
            ]);

            $invoice->recalculateBalance();

            return $entry;
        });
    }

    /**
     * Convenience wrapper: apply the configurable late fee as a positive
     * adjustment. Never auto-invoked — only when an admin chooses to.
     */
    public function applyLateFee(Invoice $invoice, ?float $amount, User $admin): InvoicePayment
    {
        $fee = $amount ?? (float) PaymentSetting::current()->late_fee_amount;

        return $this->applyAdjustment($invoice, abs($fee), 'Late payment fee', $admin, 'Late Payment Fee');
    }

    /**
     * Reverse a previously-credited payment (payment-side negative entry).
     * Used to undo a payment recorded/verified in error. The original row is
     * left intact for audit; the reversal nets it out.
     */
    public function reversePayment(Invoice $invoice, InvoicePayment $original, ?string $reason, User $admin): InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $original, $reason, $admin) {
            $entry = InvoicePayment::create([
                'invoice_id'       => $invoice->id,
                'user_id'          => $original->user_id,
                'method'           => $original->method,
                'transaction_type' => PaymentTransactionType::Reversal,
                'amount'           => -1 * (float) $original->amount,
                'reference'        => null,
                'status'           => 'verified',
                'notes'            => $reason ?? "Reversal of payment #{$original->id}",
                'created_by'       => $admin->id,
                'processed_by'     => $admin->id,
                'processed_at'     => now(),
                'approved_by'      => $admin->id,
                'approved_at'      => now(),
                'approval_note'    => $reason,
            ]);

            $invoice->recalculateBalance();

            return $entry;
        });
    }

    /**
     * Record a refund issued to the buyer (payment-side negative entry).
     * This is a ledger record only — it does not itself move money through any
     * gateway (Stripe refunds are out of scope for this phase).
     */
    public function recordRefund(Invoice $invoice, float $amount, string $reason, User $admin): InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $amount, $reason, $admin) {
            $entry = InvoicePayment::create([
                'invoice_id'       => $invoice->id,
                'user_id'          => $invoice->buyer_id,
                'method'           => PaymentMethod::Other,
                'transaction_type' => PaymentTransactionType::Refund,
                'amount'           => -1 * abs($amount),
                'status'           => 'verified',
                'notes'            => $reason,
                'created_by'       => $admin->id,
                'processed_by'     => $admin->id,
                'processed_at'     => now(),
                'approved_by'      => $admin->id,
                'approved_at'      => now(),
                'approval_note'    => $reason,
            ]);

            $invoice->recalculateBalance();

            return $entry;
        });
    }
}
