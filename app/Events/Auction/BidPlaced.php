<?php

namespace App\Events\Auction;

use App\Models\AuctionLot;
use App\Models\Bid;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BidPlaced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly AuctionLot $lot,
        public readonly Bid $bid,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("auction-lot.{$this->lot->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'bid.placed';
    }

    public function broadcastWith(): array
    {
        return [
            'lot_id'      => $this->lot->id,
            'current_bid' => $this->lot->current_bid,
            'bid_count'   => $this->lot->bid_count,
            'bid_id'      => $this->bid->id,
            'bid_type'    => $this->bid->type->value,
            'placed_at'   => $this->bid->placed_at->toIso8601String(),
            // Do NOT expose winner identity to public channel
        ];
    }
}
