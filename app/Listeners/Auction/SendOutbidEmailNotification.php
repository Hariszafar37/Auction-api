<?php

namespace App\Listeners\Auction;

use App\Events\Auction\OutbidNotification;
use App\Notifications\OutbidEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;

class SendOutbidEmailNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(OutbidNotification $event): void
    {
        $user = $event->outbidUser;
        $lot  = $event->lot;

        // Phase G — deduplication: suppress duplicate outbid notifications
        // within a 90-second window for the same user + lot combination.
        $cacheKey = "outbid_notified_{$user->id}_{$lot->id}";
        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, 90);

        $user->notify(new OutbidEmailNotification($lot, $lot->current_bid));
    }
}
