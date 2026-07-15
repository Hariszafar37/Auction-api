<?php

namespace App\Notifications;

use App\Models\AuctionLot;
use App\Notifications\Concerns\DescribesLot;
use App\Notifications\Concerns\RendersFromTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent to the winning bidder when an auction lot is closed.
 *
 * The template's supported channels exclude 'mail' on purpose: the
 * NotifyAuctionWinner job already sends AuctionWonMail, so enabling email here
 * would double-email the winner. The admin UI greys the email toggle out for it.
 */
class AuctionWonNotification extends Notification implements ShouldQueue
{
    use Queueable, RendersFromTemplate, DescribesLot;

    public function __construct(
        private readonly AuctionLot $lot,
    ) {}

    protected function templateKey(): string
    {
        return 'auction_won';
    }

    protected function templateVariables(mixed $notifiable): array
    {
        return [
            'vehicle_name' => $this->vehicleName($this->lot),
            'lot_number'   => $this->lot->lot_number,
            'amount'       => $this->money($this->lot->sold_price ?? $this->lot->current_bid),
        ];
    }

    protected function actionPayload(): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'action_url' => "{$frontendUrl}/won",
            'meta'       => [
                'lot_id'     => $this->lot->id,
                'lot_number' => $this->lot->lot_number,
                'auction_id' => $this->lot->auction_id,
                'sold_price' => $this->lot->sold_price ?? $this->lot->current_bid,
            ],
        ];
    }
}
