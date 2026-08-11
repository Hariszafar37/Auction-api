<?php

namespace App\Jobs\Auction;

use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
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
 *
 * Ends a live auction once none of its lots are still in play. "In play" means
 * pending, open or counting down — a lot that has not been opened yet holds the
 * auction back, which is deliberate: it means something went wrong during
 * auto-open and an admin should look before the sale is finalised.
 *
 * Lots awaiting a seller decision (if_sale) do NOT hold the auction open. The
 * seller keeps their full 48 hours either way, tracked by ProcessIfSaleExpiry.
 *
 * Ending stamps auctions.ends_at with the real completion time, which is what
 * seller settlement release dates and invoice due dates are calculated from.
 */
class EndCompletedAuctions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Lot statuses that keep an auction open. */
    private const IN_PLAY = [
        LotStatus::Pending,
        LotStatus::Open,
        LotStatus::Countdown,
    ];

    public function handle(AuctionService $auctions): void
    {
        $inPlay = array_map(fn (LotStatus $s) => $s->value, self::IN_PLAY);

        Auction::query()
            ->where('status', AuctionStatus::Live)
            ->whereHas('lots')
            ->whereDoesntHave('lots', fn ($q) => $q->whereIn('status', $inPlay))
            ->each(function (Auction $auction) use ($auctions) {
                try {
                    $auctions->endAuction($auction);

                    Log::info("Auction #{$auction->id} ended automatically — all lots finished.");
                } catch (\Throwable $e) {
                    Log::error("Failed to auto-end auction #{$auction->id}: " . $e->getMessage());
                }
            });
    }
}
