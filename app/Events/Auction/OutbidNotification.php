<?php

namespace App\Events\Auction;

use App\Models\AuctionLot;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OutbidNotification implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly AuctionLot $lot,
        public readonly User $outbidUser,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->outbidUser->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'bid.outbid';
    }

    public function broadcastWith(): array
    {
        return [
            'lot_id'      => $this->lot->id,
            'lot_number'  => $this->lot->lot_number,
            'auction_id'  => $this->lot->auction_id,
            'current_bid' => $this->lot->current_bid,
            'message'     => 'You have been outbid on lot #' . $this->lot->lot_number,
        ];
    }
}
