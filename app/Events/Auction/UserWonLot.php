<?php

namespace App\Events\Auction;

use App\Models\AuctionLot;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserWonLot implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly AuctionLot $lot,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->lot->buyer_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'lot.won';
    }

    public function broadcastWith(): array
    {
        $vehicle = $this->lot->vehicle;

        return [
            'lot_id'       => $this->lot->id,
            'lot_number'   => $this->lot->lot_number,
            'auction_id'   => $this->lot->auction_id,
            'auction_title'=> $this->lot->auction->title ?? '',
            'sold_price'   => $this->lot->sold_price,
            'vehicle'      => [
                'year'  => $vehicle?->year,
                'make'  => $vehicle?->make,
                'model' => $vehicle?->model,
                'trim'  => $vehicle?->trim,
                'vin'   => $vehicle?->vin,
            ],
        ];
    }
}
