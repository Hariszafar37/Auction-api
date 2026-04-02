<?php

namespace App\Models;

use App\Enums\LotStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuctionLot extends Model
{
    protected $fillable = [
        'auction_id',
        'vehicle_id',
        'lot_number',
        'status',
        'starting_bid',
        'reserve_price',
        'current_bid',
        'current_winner_id',
        'bid_count',
        'requires_seller_approval',
        'dealer_only',
        'seller_notified_at',
        'seller_decision_deadline',
        'seller_approved_at',
        'countdown_ends_at',
        'countdown_seconds',
        'countdown_extensions',
        'opened_at',
        'closed_at',
        'sold_price',
        'buyer_id',
    ];

    protected $casts = [
        'status'                   => LotStatus::class,
        'requires_seller_approval' => 'boolean',
        'dealer_only'              => 'boolean',
        'starting_bid'             => 'integer',
        'reserve_price'            => 'integer',
        'current_bid'              => 'integer',
        'sold_price'               => 'integer',
        'bid_count'                => 'integer',
        'countdown_seconds'        => 'integer',
        'countdown_extensions'     => 'integer',
        'countdown_ends_at'        => 'datetime',
        'opened_at'                => 'datetime',
        'closed_at'                => 'datetime',
        'seller_notified_at'       => 'datetime',
        'seller_decision_deadline' => 'datetime',
        'seller_approved_at'       => 'datetime',
    ];

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function currentWinner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_winner_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function proxyBids(): HasMany
    {
        return $this->hasMany(ProxyBid::class);
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereIn('status', [LotStatus::Open->value, LotStatus::Countdown->value]);
    }

    public function scopeInCountdown($query)
    {
        return $query->where('status', LotStatus::Countdown->value)
                     ->where('countdown_ends_at', '<=', now());
    }

    public function scopePendingIfSaleDecision($query)
    {
        return $query->where('status', LotStatus::IfSale->value)
                     ->where('seller_decision_deadline', '<=', now());
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function hasReserveMet(): bool
    {
        if ($this->reserve_price === null) {
            return true; // no reserve = always met
        }

        return ($this->current_bid ?? 0) >= $this->reserve_price;
    }

    public function nextMinimumBid(): int
    {
        $current = $this->current_bid ?? ($this->starting_bid - BidIncrement::forAmount($this->starting_bid));
        return $current + BidIncrement::forAmount($current);
    }

    public function isInCountdownWindow(): bool
    {
        return $this->status === LotStatus::Countdown
            && $this->countdown_ends_at
            && $this->countdown_ends_at->isFuture();
    }
}
