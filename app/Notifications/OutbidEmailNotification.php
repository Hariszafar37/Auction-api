<?php

namespace App\Notifications;

use App\Models\AuctionLot;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent via email when a user is outbid on an auction lot.
 * The real-time WebSocket event (OutbidNotification broadcast event) is dispatched
 * separately in BiddingService. This class handles the email + database channels.
 */
class OutbidEmailNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly AuctionLot $lot,
        private readonly int        $newBid,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
        $auctionUrl  = "{$frontendUrl}/auctions/{$this->lot->auction_id}";

        $vehicle     = $this->lot->vehicle;
        $vehicleName = $vehicle
            ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}"
            : "Lot {$this->lot->lot_number}";

        return (new MailMessage)
            ->subject("You've Been Outbid on {$vehicleName}")
            ->greeting('Hello ' . ($notifiable->first_name ?? $notifiable->name) . ',')
            ->line("You have been outbid on **{$vehicleName}** (Lot {$this->lot->lot_number}).")
            ->line('**New high bid: $' . number_format($this->newBid) . '**')
            ->line('The auction is still live — bid now to stay in the race.')
            ->action('Return to Auction', $auctionUrl)
            ->line('Bidding is binding. Good luck!');
    }

    public function toDatabase(mixed $notifiable): array
    {
        $vehicle     = $this->lot->vehicle;
        $vehicleName = $vehicle
            ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}"
            : "Lot {$this->lot->lot_number}";

        return [
            'type'       => 'outbid',
            'lot_id'     => $this->lot->id,
            'auction_id' => $this->lot->auction_id,
            'lot_number' => $this->lot->lot_number,
            'new_bid'    => $this->newBid,
            'message'    => "You were outbid on {$vehicleName} — new high bid is $" . number_format($this->newBid),
        ];
    }
}
