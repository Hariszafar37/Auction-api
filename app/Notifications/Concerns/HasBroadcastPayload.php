<?php

namespace App\Notifications\Concerns;

/**
 * Provides toArray() and broadcastType() implementations for notifications
 * that use the 'broadcast' channel.
 *
 * toArray(): Laravel's BroadcastChannel calls this when no toBroadcast() is defined.
 *            We delegate to toDatabase() so the canonical payload is used.
 *
 * broadcastType(): BroadcastNotificationCreated::broadcastWith() merges the payload
 *                  with ['type' => $this->broadcastType()], which defaults to the FQCN.
 *                  Overriding it here returns the short type string from toDatabase(),
 *                  preventing the FQCN from overwriting our 'type' key in the payload
 *                  and ensuring frontend icon/color mapping works for realtime events.
 *                  All toDatabase() implementations in this codebase do not use $notifiable,
 *                  so passing null is safe.
 */
trait HasBroadcastPayload
{
    public function toArray(mixed $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    public function broadcastType(): string
    {
        return $this->toDatabase(null)['type'] ?? static::class;
    }
}
