<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'invoice_number',
        'lot_id',
        'auction_id',
        'buyer_id',
        'vehicle_id',
        'sale_price',
        'deposit_amount',
        'buyer_fee_amount',
        'tax_amount',
        'tags_amount',
        'storage_days',
        'storage_fee_amount',
        'online_platform_fee_amount',
        'storage_fee_total',
        'storage_last_accrued_at',
        'total_amount',
        'amount_paid',
        'balance_due',
        'status',
        'fee_snapshot',
        'stripe_deposit_intent_id',
        'deposit_status',
        'due_at',
        'paid_at',
        'voided_at',
        'deposit_captured_at',
        'overdue_notified_at',
        'notes',
    ];

    protected $casts = [
        'sale_price'               => 'integer',
        'deposit_amount'           => 'decimal:2',
        'buyer_fee_amount'         => 'decimal:2',
        'tax_amount'               => 'decimal:2',
        'tags_amount'              => 'decimal:2',
        'storage_fee_amount'         => 'decimal:2',
        'online_platform_fee_amount' => 'decimal:2',
        'storage_fee_total'          => 'decimal:2',
        'total_amount'             => 'decimal:2',
        'amount_paid'              => 'decimal:2',
        'balance_due'              => 'decimal:2',
        'storage_days'             => 'integer',
        'status'                   => InvoiceStatus::class,
        'fee_snapshot'             => 'array',
        'due_at'                   => 'datetime',
        'paid_at'                  => 'datetime',
        'voided_at'                => 'datetime',
        'storage_last_accrued_at'  => 'datetime',
        'deposit_captured_at'      => 'datetime',
        'overdue_notified_at'      => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────────

    public function lot(): BelongsTo
    {
        return $this->belongsTo(AuctionLot::class, 'lot_id');
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderByDesc('created_at');
    }

    public function completedPayments(): HasMany
    {
        // Count 'completed' (Stripe) and 'verified' (approved offline) payments
        return $this->hasMany(InvoicePayment::class)
            ->whereIn('status', ['completed', 'verified']);
    }

    public function pendingOfflinePayments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)
            ->where('status', 'pending_verification');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(PaymentAcknowledgement::class);
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────────

    public function scopeForBuyer($query, int $userId)
    {
        return $query->where('buyer_id', $userId);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            InvoiceStatus::Pending->value,
            InvoiceStatus::Partial->value,
            InvoiceStatus::Overdue->value,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isOverdue(): bool
    {
        return $this->due_at && $this->due_at->isPast() && ! $this->isPaid();
    }

    /**
     * Signed sum of payment-side ledger entries (payment +, reversal/refund −)
     * that have actually settled (Stripe 'completed' or staff-'verified').
     * Pending/rejected buyer submissions never count — so a buyer self-report
     * cannot move the balance until staff verifies it.
     */
    public function creditedAmount(): float
    {
        return (float) $this->payments()
            ->whereIn('status', ['completed', 'verified'])
            ->whereIn('transaction_type', PaymentTransactionType::paymentSide())
            ->sum('amount');
    }

    /**
     * Computed accessor — always reflects the live arithmetic,
     * not the stored balance_due which may lag a transaction.
     */
    public function getRemainingBalanceAttribute(): float
    {
        return max(0.0, (float) $this->total_amount - (float) $this->amount_paid);
    }

    /**
     * Overpayment marker. Per business rule the invoice balance never goes
     * negative; any excess credited over the total is surfaced here as a buyer
     * credit / refund due for admin handling, while the invoice stays Paid at $0.
     */
    public function getRefundDueAttribute(): float
    {
        return max(0.0, (float) $this->amount_paid - (float) $this->total_amount);
    }

    public function recalculateBalance(): void
    {
        // Ledger-driven: sum signed payment-side rows so reversals/refunds reduce
        // what has been credited. Balance is clamped at $0 — overpayment surfaces
        // via refund_due, never as a negative balance.
        $credited = $this->creditedAmount();

        $this->amount_paid = max(0.0, $credited);
        $this->balance_due = max(0.0, (float) $this->total_amount - $credited);

        if ($this->balance_due <= 0) {
            $this->status  = InvoiceStatus::Paid;
            $this->paid_at = $this->paid_at ?? now();
        } elseif ($credited > 0) {
            $this->status = InvoiceStatus::Partial;
        }

        $this->save();
    }

    /**
     * Release gate: a vehicle/title/gate-pass/document may only be released when
     * the invoice is fully paid AND nothing is still awaiting staff verification.
     * Admin override is handled separately on the PurchaseDetail.
     */
    public function isReleaseEligible(): bool
    {
        return $this->isPaid()
            && (float) $this->balance_due <= 0
            && ! $this->pendingOfflinePayments()->exists();
    }
}
