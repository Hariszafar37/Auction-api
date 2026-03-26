<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProxyBid extends Model
{
    protected $fillable = [
        'auction_lot_id',
        'user_id',
        'max_amount',
        'is_active',
        'cancelled_at',
    ];

    protected $casts = [
        'max_amount'   => 'integer',
        'is_active'    => 'boolean',
        'cancelled_at' => 'datetime',
    ];

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function auctionLot(): BelongsTo
    {
        return $this->belongsTo(AuctionLot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    public function cancel(): void
    {
        $this->update([
            'is_active'    => false,
            'cancelled_at' => now(),
        ]);
    }
}
