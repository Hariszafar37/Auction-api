<?php

namespace App\Events\Auction;

use App\Models\AuctionLot;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CountdownExtended implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly AuctionLot $lot,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("auction-lot.{$this->lot->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'lot.countdown_extended';
    }

    public function broadcastWith(): array
    {
        return [
            'lot_id'             => $this->lot->id,
            'countdown_ends_at'  => $this->lot->countdown_ends_at->toIso8601String(),
            'countdown_seconds'  => $this->lot->countdown_seconds,
            'extensions'         => $this->lot->countdown_extensions,
        ];
    }
}
