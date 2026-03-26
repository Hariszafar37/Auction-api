<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Vehicle extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'seller_id',
        'vin',
        'year',
        'make',
        'model',
        'trim',
        'color',
        'mileage',
        'body_type',
        'transmission',
        'engine',
        'fuel_type',
        'condition_light',
        'condition_notes',
        'has_title',
        'title_state',
        'status',
    ];

    protected $casts = [
        'has_title' => 'boolean',
        'mileage'   => 'integer',
        'year'      => 'integer',
    ];

    // ─── Media collections ───────────────────────────────────────────────────────

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useFallbackUrl('')
            ->withResponsiveImages();

        $this->addMediaCollection('videos');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(400)
            ->height(300)
            ->sharpen(5)
            ->performOnCollections('images')
            ->nonQueued();
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function auctionLots(): HasMany
    {
        return $this->hasMany(AuctionLot::class);
    }

    /**
     * The active (non-terminal) lot currently holding this vehicle.
     */
    public function activeLot(): HasOne
    {
        return $this->hasOne(AuctionLot::class)
            ->whereNotIn('status', ['sold', 'reserve_not_met', 'no_sale', 'cancelled'])
            ->latest();
    }

    public function notificationSubscriptions(): HasMany
    {
        return $this->hasMany(VehicleNotificationSubscription::class);
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────────

    /**
     * Only vehicles visible on the public inventory.
     * Excludes withdrawn vehicles, soft-deleted records, and sold vehicles.
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', ['available', 'in_auction']);
    }

    /**
     * Apply keyword search across VIN, make, and model.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('vin',   'like', "%{$term}%")
              ->orWhere('make',  'like', "%{$term}%")
              ->orWhere('model', 'like', "%{$term}%");
        });
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    public function markAsInAuction(): void
    {
        $this->update(['status' => 'in_auction']);
    }

    public function markAsSold(): void
    {
        $this->update(['status' => 'sold']);
    }

    public function markAsAvailable(): void
    {
        $this->update(['status' => 'available']);
    }

    public function markAsWithdrawn(): void
    {
        $this->update(['status' => 'withdrawn']);
    }

    /**
     * Whether the vehicle can be edited (not sold or currently in a live auction).
     */
    public function isEditable(): bool
    {
        return ! in_array($this->status, ['sold']);
    }

    /**
     * Whether a status can be manually overridden by an admin.
     * Prevents manual override of vehicles actively in a live auction.
     */
    public function canManuallyChangeStatusTo(string $newStatus): bool
    {
        // Sold vehicles may only go back to available (e.g. after a failed transaction)
        // Vehicles in_auction can be manually withdrawn (e.g. admin intervention)
        // All transitions are permitted for admin override
        return true;
    }
}
