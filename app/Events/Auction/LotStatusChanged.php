<?php

namespace App\Events\Auction;

use App\Models\AuctionLot;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LotStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly AuctionLot $lot,
        public readonly string $previousStatus,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("auction-lot.{$this->lot->id}"),
            new Channel("auction.{$this->lot->auction_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'lot.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'lot_id'           => $this->lot->id,
            'auction_id'       => $this->lot->auction_id,
            'lot_number'       => $this->lot->lot_number,
            'previous_status'  => $this->previousStatus,
            'status'           => $this->lot->status->value,
            'current_bid'      => $this->lot->current_bid,
            'countdown_ends_at'=> $this->lot->countdown_ends_at?->toIso8601String(),
        ];
    }
}
