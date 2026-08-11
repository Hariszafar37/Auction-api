<?php

namespace App\Jobs\Auction;

use App\Enums\AuctionStatus;
use App\Events\Auction\AuctionStarted;
use App\Models\Auction;
use App\Services\Auction\AuctionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs every minute via the scheduler.
 * Transitions scheduled auctions whose starts_at has passed → live, and opens
 * every lot so bidding can begin without an admin clicking through them.
 */
class StartScheduledAuctions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AuctionService $auctions): void
    {
        Auction::query()
            ->where('status', AuctionStatus::Scheduled)
            ->where('starts_at', '<=', now())
            ->each(function (Auction $auction) use ($auctions) {
                $auction->update(['status' => AuctionStatus::Live]);

                $opened = $auctions->openPendingLots($auction);

                broadcast(new AuctionStarted($auction))->toOthers();

                Log::info("Auction #{$auction->id} started automatically ({$opened} lots opened).");
            });
    }
}
