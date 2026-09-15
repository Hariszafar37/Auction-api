<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvoicePayment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'user_id',
        'method',
        'transaction_type',
        'fee_type',
        'amount',
        'reference',
        'stripe_client_secret',
        'card_source',
        'status',
        'notes',
        'processed_at',
        'received_at',
        'processed_by',
        'created_by',
        'approved_by',
        'approved_at',
        'approval_note',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'method'           => PaymentMethod::class,
        'transaction_type' => PaymentTransactionType::class,
        'processed_at'     => 'datetime',
        'received_at'      => 'datetime',
        'approved_at'      => 'datetime',
    ];

    protected $attributes = [
        'transaction_type' => 'payment',
    ];

    protected $hidden = ['stripe_client_secret'];

    /**
     * Statuses a stripe_card row can hold while no money has moved.
     *
     * 'pending'  — a PaymentIntent exists but the buyer has not successfully
     *              submitted card details (Stripe shows it as "Incomplete").
     * 'canceled' — the intent was abandoned and has since been cancelled, either
     *              by the buyer, by the reconciliation sweeper, or by Stripe's own
     *              24-hour auto-cancel relayed through payment_intent.canceled.
     *
     * A card charge is synchronous: it settles to 'completed' or 'failed' within
     * seconds. Anything still in this list is therefore an unfinished attempt, not
     * a payment in flight, and must not be shown to the buyer as one.
     */
    public const CARD_UNSETTLED_STATUSES = ['pending', 'canceled'];

    /**
     * True when this row is a card attempt that never moved money.
     *
     * Scoped deliberately to stripe_card: 'deposit' rows use their own lifecycle
     * ('authorized' / 'requires_action' / 'failed' / 'completed') and are driven by
     * InvoiceService, while non-card methods legitimately sit at
     * 'pending_verification' awaiting staff review. Neither is affected here.
     */
    public function isUnsettledCardAttempt(): bool
    {
        return $this->method === PaymentMethod::StripeCard
            && in_array($this->status, self::CARD_UNSETTLED_STATUSES, true);
    }

    /**
     * Customer-facing title for an adjustment row. Prefers the explicit fee_type
     * (e.g. "Late Payment Fee"); falls back to the reason/notes for legacy rows
     * created before fee_type existed, then a generic label. Display-only — the
     * internal transaction_type stays 'adjustment'.
     */
    public function adjustmentTitle(): string
    {
        return $this->fee_type ?: ($this->notes ?: 'Adjustment');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
