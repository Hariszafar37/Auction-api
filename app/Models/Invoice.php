<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
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
        'total_amount',
        'amount_paid',
        'balance_due',
        'status',
        'fee_snapshot',
        'due_at',
        'paid_at',
        'voided_at',
        'notes',
    ];

    protected $casts = [
        'sale_price'          => 'integer',
        'deposit_amount'      => 'decimal:2',
        'buyer_fee_amount'    => 'decimal:2',
        'tax_amount'          => 'decimal:2',
        'tags_amount'         => 'decimal:2',
        'storage_fee_amount'  => 'decimal:2',
        'total_amount'        => 'decimal:2',
        'amount_paid'         => 'decimal:2',
        'balance_due'         => 'decimal:2',
        'storage_days'        => 'integer',
        'status'              => InvoiceStatus::class,
        'fee_snapshot'        => 'array',
        'due_at'              => 'datetime',
        'paid_at'             => 'datetime',
        'voided_at'           => 'datetime',
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
        return $this->hasMany(InvoicePayment::class)->where('status', 'completed');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────────

    public function scopeForBuyer($query, int $userId)
    {
        return $query->where('buyer_id', $userId);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Partial->value]);
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

    public function recalculateBalance(): void
    {
        $paid = $this->completedPayments()->sum('amount');

        $this->amount_paid = (float) $paid;
        $this->balance_due = max(0, (float) $this->total_amount - (float) $paid);

        if ($this->balance_due <= 0) {
            $this->status   = InvoiceStatus::Paid;
            $this->paid_at  = $this->paid_at ?? now();
        } elseif ((float) $paid > 0) {
            $this->status = InvoiceStatus::Partial;
        }

        $this->save();
    }
}
