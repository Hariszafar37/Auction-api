<?php

namespace App\Notifications;

use App\Models\AuctionLot;
use App\Notifications\Concerns\HasBroadcastPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the vehicle seller when the first bid is placed on their lot.
 * Subsequent bids on the same lot do NOT re-trigger this notification
 * to avoid noise — the seller is notified once per lot open.
 */
class BidPlacedNotification extends Notification implements ShouldQueue
{
    use Queueable, HasBroadcastPayload;

    public function __construct(
        private readonly AuctionLot $lot,
        private readonly int        $bidAmount,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
        $vehicle     = $this->lot->vehicle;
        $vehicleName = $vehicle
            ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}"
            : "Lot {$this->lot->lot_number}";

        return (new MailMessage)
            ->subject("A Bid Has Been Placed on {$vehicleName}")
            ->greeting('Hello ' . ($notifiable->first_name ?? $notifiable->name) . ',')
            ->line("A bid of **\$" . number_format($this->bidAmount) . "** has been placed on **{$vehicleName}** (Lot {$this->lot->lot_number}).")
            ->action('View Auction', "{$frontendUrl}/auctions/{$this->lot->auction_id}")
            ->line('You will be notified again when the auction concludes.');
    }

    public function toDatabase(mixed $notifiable): array
    {
        $vehicle     = $this->lot->vehicle;
        $vehicleName = $vehicle
            ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}"
            : "Lot {$this->lot->lot_number}";

        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'type'       => 'bid_placed',
            'title'      => 'A bid was placed on your vehicle',
            'message'    => "A bid of \$" . number_format($this->bidAmount) . " was placed on {$vehicleName}.",
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
