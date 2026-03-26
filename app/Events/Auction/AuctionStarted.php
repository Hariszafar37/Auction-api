<?php

namespace App\Events\Auction;

use App\Models\Auction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AuctionStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Auction $auction,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("auction.{$this->auction->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'auction.started';
    }

    public function broadcastWith(): array
    {
        return [
            'auction_id' => $this->auction->id,
            'title'      => $this->auction->title,
            'started_at' => now()->toIso8601String(),
            'lot_count'  => $this->auction->lots()->count(),
        ];
    }
}
