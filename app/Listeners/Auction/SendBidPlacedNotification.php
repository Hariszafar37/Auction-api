<?php

namespace App\Listeners\Auction;

use App\Events\Auction\BidPlaced;
use App\Notifications\BidPlacedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;

class SendBidPlacedNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(BidPlaced $event): void
    {
        $lot     = $event->lot;
        $vehicle = $lot->vehicle;

        if (! $vehicle || ! $vehicle->seller) {
            return;
        }

        $seller = $vehicle->seller;

        // Do not notify the seller if they placed the bid themselves
        if ($seller->id === $event->bid->user_id) {
            return;
        }

        // Only notify on the very first bid to avoid inbox noise
        $cacheKey = "bid_placed_notified_{$seller->id}_{$lot->id}";
        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, now()->addHours(48));

        $seller->notify(new BidPlacedNotification($lot, $event->bid->amount));
    }
}
