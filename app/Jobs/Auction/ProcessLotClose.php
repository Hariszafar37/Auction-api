<?php

namespace App\Jobs\Auction;

use App\Enums\LotStatus;
use App\Models\AuctionLot;
use App\Services\Auction\AuctionLotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs every minute via the scheduler.
 * Closes lots whose countdown has expired.
 */
class ProcessLotClose implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AuctionLotService $service): void
    {
        AuctionLot::query()
            ->where('status', LotStatus::Countdown->value)
            ->where('countdown_ends_at', '<=', now())
            ->each(function (AuctionLot $lot) use ($service) {
                try {
                    $service->closeLot($lot);
                } catch (\Throwable $e) {
                    Log::error("Failed to close lot #{$lot->id}: " . $e->getMessage());
                }
            });
    }
}
