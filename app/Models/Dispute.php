<?php

namespace App\Models;

use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
    protected $fillable = [
        'auction_lot_id',
        'bid_id',
        'raised_by',
        'reason',
        'status',
        'admin_notes',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'status'      => DisputeStatus::class,
        'resolved_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function lot(): BelongsTo
    {
        return $this->belongsTo(AuctionLot::class, 'auction_lot_id');
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
