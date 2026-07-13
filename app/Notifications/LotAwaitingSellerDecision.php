<?php

namespace App\Notifications;

use App\Models\AuctionLot;
use App\Notifications\Concerns\HasBroadcastPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent to the vehicle seller when one of their lots closes into "If Sale" and now
 * needs an approve/reject decision within the 48-hour window.
 *
 * Database-only on purpose: AuctionLotService::triggerIfSale() already sends the
 * seller IfSaleNotificationMail, so adding the 'mail' channel here would double-email.
 * This exists so the pending decision also appears in the in-app notification bell.
 */
class LotAwaitingSellerDecision extends Notification implements ShouldQueue
{
    use Queueable, HasBroadcastPayload;

    public function __construct(
        private readonly AuctionLot $lot,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(mixed $notifiable): array
    {
        $vehicle     = $this->lot->vehicle;
        $vehicleName = $vehicle
            ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}"
            : "Lot {$this->lot->lot_number}";

        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
        $highestBid  = $this->lot->current_bid;

        return [
            'type'       => 'lot_awaiting_seller_decision',
            'title'      => 'Action required: bid awaiting your approval',
            'message'    => $highestBid !== null
                ? "{$vehicleName} closed at \$" . number_format($highestBid) . ". Approve or reject the bid before the deadline."
                : "{$vehicleName} needs your decision before the deadline.",
            'action_url' => "{$frontendUrl}/dealer/lots?status=if_sale",
            'meta'       => [
                'lot_id'                   => $this->lot->id,
                'lot_number'               => $this->lot->lot_number,
                'auction_id'               => $this->lot->auction_id,
                'current_bid'              => $highestBid,
                'seller_decision_deadline' => $this->lot->seller_decision_deadline?->toIso8601String(),
            ],
        ];
    }
}
