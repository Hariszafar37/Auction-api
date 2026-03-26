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
 * If seller has not responded within the decision deadline, auto-reject (reserve_not_met).
 */
class ProcessIfSaleExpiry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AuctionLotService $service): void
    {
        AuctionLot::query()
            ->where('status', LotStatus::IfSale->value)
            ->where('seller_decision_deadline', '<=', now())
            ->each(function (AuctionLot $lot) use ($service) {
                try {
                    $service->expireIfSale($lot);
                } catch (\Throwable $e) {
                    Log::error("Failed to expire if_sale for lot #{$lot->id}: " . $e->getMessage());
                }
            });
    }
}
