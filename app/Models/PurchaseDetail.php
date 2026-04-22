<?php

namespace App\Models;

use App\Enums\PickupStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseDetail extends Model
{
    protected $fillable = [
        'lot_id',
        'buyer_id',
        'gate_pass_token',
        'gate_pass_generated_at',
        'gate_pass_revoked_at',
        'revocation_reason',
        'pickup_status',
        'pickup_notes',
        'picked_up_at',
        'title_received_at',
        'title_verified_at',
        'title_released_at',
    ];

    protected $casts = [
        'pickup_status'          => PickupStatus::class,
        'gate_pass_generated_at' => 'datetime',
        'gate_pass_revoked_at'   => 'datetime',
        'picked_up_at'           => 'datetime',
        'title_received_at'      => 'datetime',
        'title_verified_at'      => 'datetime',
        'title_released_at'      => 'datetime',
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

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'lot_id', 'lot_id');
    }

    public function transportRequests(): HasMany
    {
        return $this->hasMany(TransportRequest::class, 'lot_id', 'lot_id');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────────

    public function scopeForBuyer($query, int $userId)
    {
        return $query->where('buyer_id', $userId);
    }
}
