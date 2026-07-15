<?php

namespace App\Notifications;

use App\Models\AuctionLot;
use App\Notifications\Concerns\RendersFromTemplate;
use App\Notifications\Concerns\DescribesLot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent when a user is outbid on an auction lot.
 * The realtime WebSocket event (OutbidNotification broadcast event) is dispatched
 * separately in BiddingService; this class covers mail + database + broadcast.
 */
class OutbidEmailNotification extends Notification implements ShouldQueue
{
    use Queueable, RendersFromTemplate, DescribesLot;

    public function __construct(
        private readonly AuctionLot $lot,
        private readonly int        $newBid,
    ) {}

    protected function templateKey(): string
    {
        return 'outbid';
    }

    protected function templateVariables(mixed $notifiable): array
    {
        return [
            'vehicle_name' => $this->vehicleName($this->lot),
            'lot_number'   => $this->lot->lot_number,
            'amount'       => $this->money($this->newBid),
        ];
    }

    protected function actionPayload(): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'action_url' => "{$frontendUrl}/auctions/{$this->lot->auction_id}",
            'meta'       => [
                'lot_id'     => $this->lot->id,
                'auction_id' => $this->lot->auction_id,
                'lot_number' => $this->lot->lot_number,
                'new_bid'    => $this->newBid,
            ],
        ];
    }
}
