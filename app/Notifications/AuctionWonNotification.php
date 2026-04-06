<?php

namespace App\Notifications;

use App\Models\AuctionLot;
use App\Notifications\Concerns\HasBroadcastPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the winning bidder when an auction lot is closed.
 */
class AuctionWonNotification extends Notification implements ShouldQueue
{
    use Queueable, HasBroadcastPayload;

    public function __construct(
        private readonly AuctionLot $lot,
    ) {}

    public function via(mixed $notifiable): array
    {
        // 'mail' intentionally excluded: NotifyAuctionWinner job (AuctionWonMail) already
        // handles the winner email. This notification covers only database + realtime broadcast.
        return ['database', 'broadcast'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
        $vehicle     = $this->lot->vehicle;
        $vehicleName = $vehicle
            ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}"
            : "Lot {$this->lot->lot_number}";

        return (new MailMessage)
            ->subject("Congratulations! You Won {$vehicleName}")
            ->greeting('Hello ' . ($notifiable->first_name ?? $notifiable->name) . ',')
            ->line("You won the auction for **{$vehicleName}** (Lot {$this->lot->lot_number}).")
            ->line('**Winning bid: $' . number_format((int) $this->lot->sold_price ?? $this->lot->current_bid) . '**')
            ->line('Our team will be in touch shortly with next steps for payment and vehicle pickup.')
            ->action('View Won Items', "{$frontendUrl}/won")
            ->line('Thank you for participating in the auction!');
    }

    public function toDatabase(mixed $notifiable): array
    {
        $vehicle     = $this->lot->vehicle;
        $vehicleName = $vehicle
            ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}"
            : "Lot {$this->lot->lot_number}";

        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'type'       => 'auction_won',
            'title'      => 'You won the auction!',
            'message'    => "Congratulations! You won {$vehicleName} — Lot {$this->lot->lot_number}.",
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
