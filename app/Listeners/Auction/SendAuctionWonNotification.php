<?php

namespace App\Listeners\Auction;

use App\Events\Auction\UserWonLot;
use App\Notifications\AuctionWonNotification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendAuctionWonNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(UserWonLot $event): void
    {
        $winner = User::find($event->lot->buyer_id ?? $event->lot->current_winner_id);
        if (! $winner) {
            return;
        }

        $winner->notify(new AuctionWonNotification($event->lot));
    }
}
