<?php

namespace App\Models;

use App\Enums\BidType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bid extends Model
{
    protected $fillable = [
        'auction_lot_id',
        'user_id',
        'amount',
        'type',
        'is_winning',
        'ip_address',
        'placed_at',
    ];

    protected $casts = [
        'amount'     => 'integer',
        'type'       => BidType::class,
        'is_winning' => 'boolean',
        'placed_at'  => 'datetime',
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
}
