<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a user's "Notify Me" subscription for a vehicle.
 *
 * When the vehicle is assigned to an auction, users who subscribed will
 * receive a notification (e.g. email, push). The `notified_at` field is
 * set once the notification has been dispatched to prevent duplicate sends.
 *
 * Unique constraint: one subscription per user per vehicle.
 */
class VehicleNotificationSubscription extends Model
{
    protected $fillable = [
        'vehicle_id',
        'user_id',
        'notified_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
    ];

    // ─── Relationships ───────────────────────────────────────────────────────────

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    public function hasBeenNotified(): bool
    {
        return $this->notified_at !== null;
    }

    public function markNotified(): void
    {
        $this->update(['notified_at' => now()]);
    }
}
