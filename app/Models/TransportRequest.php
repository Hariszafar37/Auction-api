<?php

namespace App\Models;

use App\Enums\TransportRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportRequest extends Model
{
    protected $fillable = [
        'lot_id',
        'buyer_id',
        'pickup_location',
        'delivery_address',
        'preferred_dates',
        'notes',
        'status',
        'quote_amount',
        'admin_notes',
        'quoted_at',
        'arranged_at',
        'cancelled_at',
    ];

    protected $casts = [
        'status'       => TransportRequestStatus::class,
        'quote_amount' => 'decimal:2',
        'quoted_at'    => 'datetime',
        'arranged_at'  => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────────

    public function lot(): BelongsTo
    {
        return $this->belongsTo(AuctionLot::class, 'lot_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}
