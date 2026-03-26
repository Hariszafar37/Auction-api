<?php

namespace App\Jobs\Auction;

use App\Models\AuctionLot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched when a lot is marked as sold.
 * Sends winner notification email and any post-auction processing.
 */
class NotifyAuctionWinner implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly AuctionLot $lot,
    ) {}

    public function handle(): void
    {
        $lot   = $this->lot->load(['buyer', 'vehicle.seller', 'auction']);
        $buyer = $lot->buyer;

        if (! $buyer) {
            Log::warning("NotifyAuctionWinner: lot #{$lot->id} has no buyer.");
            return;
        }

        Mail::to($buyer->email)->send(new \App\Mail\AuctionWonMail($lot));

        Log::info(
            "Winner notification sent for lot #{$lot->id}, " .
            "buyer: {$buyer->email}, price: \${$lot->sold_price}"
        );
    }
}
