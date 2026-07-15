<?php

namespace App\Notifications;

use App\Models\AuctionLot;
use App\Notifications\Concerns\DescribesLot;
use App\Notifications\Concerns\RendersFromTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent to the vehicle seller when one of their lots closes into "If Sale" and now
 * needs an approve/reject decision within the 48-hour window.
 *
 * The template's supported channels exclude 'mail' on purpose:
 * AuctionLotService::triggerIfSale() already sends the seller IfSaleNotificationMail,
 * so enabling email here would double-email them.
 */
class LotAwaitingSellerDecision extends Notification implements ShouldQueue
{
    use Queueable, RendersFromTemplate, DescribesLot;

    public function __construct(
        private readonly AuctionLot $lot,
    ) {}

    protected function templateKey(): string
    {
        return 'lot_awaiting_seller_decision';
    }

    protected function templateVariables(mixed $notifiable): array
    {
        return [
            'vehicle_name' => $this->vehicleName($this->lot),
            'lot_number'   => $this->lot->lot_number,
            'amount'       => $this->money($this->lot->current_bid),
        ];
    }

    protected function actionPayload(): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'action_url' => "{$frontendUrl}/my/lots?status=if_sale",
            'meta'       => [
                'lot_id'                   => $this->lot->id,
                'lot_number'               => $this->lot->lot_number,
                'auction_id'               => $this->lot->auction_id,
                'current_bid'              => $this->lot->current_bid,
                'seller_decision_deadline' => $this->lot->seller_decision_deadline?->toIso8601String(),
            ],
        ];
    }

    /**
     * When the lot has no bid, {{amount}} renders empty and the templated message
     * would read oddly. Fall back to the deadline-only wording the class used before.
     */
    public function toDatabase(mixed $notifiable): array
    {
        $payload = $this->renderDatabasePayload($notifiable);

        if ($this->lot->current_bid === null) {
            $payload['message'] = "{$this->vehicleName($this->lot)} needs your decision before the deadline.";
        }

        return $payload;
    }
}
