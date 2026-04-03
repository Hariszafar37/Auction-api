<?php

namespace App\Notifications\Concerns;

/**
 * Provides a toArray() implementation that re-uses toDatabase(),
 * enabling the 'broadcast' channel without requiring a separate toBroadcast().
 *
 * Laravel's BroadcastChannel looks for toBroadcast() then toArray(). Since our
 * notifications use toDatabase() as the canonical payload, this trait delegates
 * toArray() to toDatabase() so the broadcast channel gets the same shape.
 */
trait HasBroadcastPayload
{
    public function toArray(mixed $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
