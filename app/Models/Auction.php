<?php

namespace App\Models;

use App\Enums\AuctionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Auction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'location',
        'timezone',
        'starts_at',
        'ends_at',
        'status',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
        'status'    => AuctionStatus::class,
    ];

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(AuctionLot::class);
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeUpcoming($query)
    {
        return $query->where('status', AuctionStatus::Scheduled)
                     ->where('starts_at', '>', now());
    }

    public function scopeLive($query)
    {
        return $query->where('status', AuctionStatus::Live);
    }

    public function scopeByLocation($query, string $location)
    {
        return $query->where('location', 'like', "%{$location}%");
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === AuctionStatus::Draft;
    }

    public function isScheduled(): bool
    {
        return $this->status === AuctionStatus::Scheduled;
    }

    public function isLive(): bool
    {
        return $this->status === AuctionStatus::Live;
    }

    public function isEnded(): bool
    {
        return $this->status === AuctionStatus::Ended;
    }
}
