<?php

namespace App\Events\Auction;

use App\Models\Auction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AuctionEnded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Auction $auction,
        public readonly array $summary,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("auction.{$this->auction->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'auction.ended';
    }

    public function broadcastWith(): array
    {
        return [
            'auction_id' => $this->auction->id,
            'ended_at'   => $this->auction->ends_at->toIso8601String(),
            'summary'    => $this->summary,
        ];
    }
}
