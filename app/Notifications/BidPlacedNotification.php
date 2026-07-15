<?php

namespace App\Notifications;

use App\Models\AuctionLot;
use App\Notifications\Concerns\DescribesLot;
use App\Notifications\Concerns\RendersFromTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent to the vehicle seller when the first bid is placed on their lot.
 * Subsequent bids on the same lot do NOT re-trigger this notification
 * to avoid noise — the seller is notified once per lot open.
 */
class BidPlacedNotification extends Notification implements ShouldQueue
{
    use Queueable, RendersFromTemplate, DescribesLot;

    public function __construct(
        private readonly AuctionLot $lot,
        private readonly int        $bidAmount,
    ) {}

    protected function templateKey(): string
    {
        return 'bid_placed';
    }

    protected function templateVariables(mixed $notifiable): array
    {
        return [
            'vehicle_name' => $this->vehicleName($this->lot),
            'lot_number'   => $this->lot->lot_number,
            'amount'       => $this->money($this->bidAmount),
        ];
    }

    protected function actionPayload(): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'action_url' => "{$frontendUrl}/auctions/{$this->lot->auction_id}",
            'meta'       => [
                'lot_id'     => $this->lot->id,
                'lot_number' => $this->lot->lot_number,
                'auction_id' => $this->lot->auction_id,
                'bid_amount' => $this->bidAmount,
            ],
        ];
    }
}
