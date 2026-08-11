<?php

namespace App\Jobs\Auction;

use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use App\Models\AuctionLot;
use App\Services\Auction\BiddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs every minute via the scheduler.
 *
 * Starts the final countdown on lots whose scheduled closing time has arrived.
 * A lot's own scheduled_close_at wins; otherwise it inherits the auction-wide
 * scheduled_end_at. Everything after the countdown starts is already automatic:
 * a late bid extends the timer (BiddingService::extendCountdown) and
 * ProcessLotClose finalises the outcome when it expires.
 *
 * Lots with no schedule on either level are left alone — an admin closes those
 * by hand.
 */
class ProcessScheduledLotCountdowns implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(BiddingService $bidding): void
    {
        AuctionLot::query()
            ->with('auction')
            ->where('status', LotStatus::Open->value)
            ->whereHas('auction', fn ($q) => $q->where('status', AuctionStatus::Live->value))
            // Cheap pre-filter: a lot can only be due if it has a time of its
            // own or its auction has one. The exact comparison happens below,
            // where the per-lot override takes precedence.
            ->where(function ($q) {
                $q->whereNotNull('scheduled_close_at')
                  ->orWhereHas('auction', fn ($a) => $a->whereNotNull('scheduled_end_at'));
            })
            ->each(function (AuctionLot $lot) use ($bidding) {
                $closeAt = $lot->effectiveCloseAt();

                if (! $closeAt || $closeAt->isFuture()) {
                    return;
                }

                try {
                    $bidding->startCountdown($lot);

                    Log::info("Lot #{$lot->id} entered countdown automatically at its scheduled close time.");
                } catch (\Throwable $e) {
                    Log::error("Failed to auto-start countdown on lot #{$lot->id}: " . $e->getMessage());
                }
            });
    }
}
