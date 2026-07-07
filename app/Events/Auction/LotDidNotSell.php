<?php

namespace App\Events\Auction;

use App\Models\AuctionLot;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a lot reaches a terminal state without selling (no bids, reserve
 * not met, or an if-sale lot rejected/expired). Drives seller no-sale fee
 * settlement. Not broadcast — purely a server-side domain event.
 */
class LotDidNotSell
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AuctionLot $lot,
    ) {}
}
