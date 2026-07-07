<?php

namespace App\Models;

use App\Enums\SellerSettlementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerSettlement extends Model
{
    protected $fillable = [
        'settlement_number',
        'lot_id',
        'auction_id',
        'seller_id',
        'vehicle_id',
        'outcome',
        'sale_price',
        'registration_fee',
        'commission_amount',
        'no_sale_fee',
        'adjustments_total',
        'net_proceeds',
        'status',
        'release_date',
        'check_number',
        'released_at',
        'check_issued_at',
        'paid_at',
        'collected_at',
        'collection_method',
        'collection_reference',
        'collected_by',
        'fee_snapshot',
        'notes',
    ];

    protected $casts = [
        'sale_price'        => 'integer',
        'registration_fee'  => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'no_sale_fee'       => 'decimal:2',
        'adjustments_total' => 'decimal:2',
        'net_proceeds'      => 'decimal:2',
        'status'            => SellerSettlementStatus::class,
        'release_date'      => 'date',
        'released_at'       => 'datetime',
        'check_issued_at'   => 'datetime',
        'paid_at'           => 'datetime',
        'collected_at'      => 'datetime',
        'fee_snapshot'      => 'array',
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

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(SellerSettlementAdjustment::class, 'settlement_id')->orderBy('created_at');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────────

    public function scopeForSeller($query, int $userId)
    {
        return $query->where('seller_id', $userId);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    /** Is this a finalized settlement (outcome decided), not just a seeded row? */
    public function isFinalized(): bool
    {
        return $this->outcome !== null;
    }

    public function isSold(): bool
    {
        return $this->outcome === 'sold';
    }

    /**
     * The base (pre-adjustment) proceeds implied by the fee line items:
     *   sold    → sale_price − commission − registration
     *   no_sale → −(registration + no_sale_fee)
     */
    public function baseProceeds(): float
    {
        if ($this->isSold()) {
            return round((float) $this->sale_price - (float) $this->commission_amount - (float) $this->registration_fee, 2);
        }

        return round(-1 * ((float) $this->registration_fee + (float) $this->no_sale_fee), 2);
    }

    /**
     * Recompute adjustments_total from the adjustment ledger and re-derive
     * net_proceeds. Keeps net in sync whenever an adjustment is added.
     */
    public function recalculateNet(): void
    {
        $this->adjustments_total = (float) $this->adjustments()->sum('amount');
        $this->net_proceeds      = round($this->baseProceeds() + (float) $this->adjustments_total, 2);
        $this->save();
    }
}
