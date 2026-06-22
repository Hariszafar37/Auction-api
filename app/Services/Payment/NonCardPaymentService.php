<?php

namespace App\Services\Payment;

use App\Enums\PaymentMethod;
use App\Enums\PaymentTransactionType;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentAcknowledgement;
use App\Models\PaymentSetting;
use App\Support\BusinessDays;
use Illuminate\Http\Request;

/**
 * Buyer-facing workflow for Wire / Cash / Cashier's Check:
 *   acknowledge requirements → (view instructions) → submit payment intent.
 *
 * A submission only ever creates a `pending_verification` ledger row — it never
 * credits the invoice. Balances move solely when staff verifies/approves.
 */
class NonCardPaymentService
{
    /**
     * Record an immutable acknowledgement of the payment requirements and apply
     * the non-card deadline to the invoice. Card flows are never touched here.
     */
    public function acknowledge(Invoice $invoice, PaymentMethod $method, Request $request): PaymentAcknowledgement
    {
        $ack = PaymentAcknowledgement::create([
            'invoice_id'      => $invoice->id,
            'user_id'         => $request->user()->id,
            'payment_method'  => $method,
            'acknowledged_at' => now(),
            'ip_address'      => $request->ip(),
            'user_agent'      => substr((string) $request->userAgent(), 0, 512),
        ]);

        $this->applyNonCardDeadline($invoice);

        return $ack;
    }

    /**
     * Buyer self-reports that a non-card payment has been sent. Creates a
     * pending_verification ledger entry that does NOT affect the balance until an
     * admin verifies it.
     */
    public function submit(
        Invoice $invoice,
        PaymentMethod $method,
        float $amount,
        ?string $reference,
        ?string $notes,
        int $userId,
    ): InvoicePayment {
        $payment = InvoicePayment::create([
            'invoice_id'       => $invoice->id,
            'user_id'          => $userId,
            'method'           => $method,
            'transaction_type' => PaymentTransactionType::Payment,
            'amount'           => $amount,
            'reference'        => $reference,
            'status'           => 'pending_verification',
            'notes'            => $notes,
            'created_by'       => $userId,
        ]);

        $this->applyNonCardDeadline($invoice);

        return $payment;
    }

    /**
     * Has this buyer acknowledged the given method for this invoice?
     */
    public function hasAcknowledged(Invoice $invoice, PaymentMethod $method, int $userId): bool
    {
        return $invoice->acknowledgements()
            ->where('user_id', $userId)
            ->where('payment_method', $method->value)
            ->exists();
    }

    /**
     * Set the invoice due date to N full business days after the auction date,
     * per the configurable setting. Only applied to open invoices and only via
     * the non-card flow — card invoices keep their existing 7-day due date.
     */
    public function applyNonCardDeadline(Invoice $invoice): void
    {
        if (! $invoice->isOpen()) {
            return;
        }

        $invoice->loadMissing('auction');
        $auctionDate = $invoice->auction?->ends_at ?? $invoice->auction?->starts_at ?? now();

        $days = (int) PaymentSetting::current()->business_days_to_pay;
        $due  = BusinessDays::add($auctionDate, $days);

        $invoice->update(['due_at' => $due]);
    }
}
