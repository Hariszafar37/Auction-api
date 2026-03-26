<?php

namespace App\Jobs\Auction;

use App\Enums\AuctionStatus;
use App\Events\Auction\AuctionStarted;
use App\Models\Auction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs every minute via the scheduler.
 * Transitions scheduled auctions whose starts_at has passed → live.
 */
class StartScheduledAuctions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Auction::query()
            ->where('status', AuctionStatus::Scheduled)
            ->where('starts_at', '<=', now())
            ->each(function (Auction $auction) {
                $auction->update(['status' => AuctionStatus::Live]);

                broadcast(new AuctionStarted($auction))->toOthers();

                Log::info("Auction #{$auction->id} started automatically.");
            });
    }
}
