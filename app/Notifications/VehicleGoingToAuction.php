<?php

namespace App\Notifications;

use App\Models\Auction;
use App\Models\Vehicle;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to users who subscribed to a "Notify Me" alert on a vehicle.
 * Fires when the vehicle is submitted to an auction (status → in_auction).
 */
class VehicleGoingToAuction extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Vehicle $vehicle,
        private readonly Auction $auction,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $vehicleTitle   = "{$this->vehicle->year} {$this->vehicle->make} {$this->vehicle->model}";
        $auctionDate    = $this->auction->starts_at?->format('F j, Y \a\t g:i A T') ?? 'TBD';
        $frontendUrl    = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/');
        $inventoryUrl   = "{$frontendUrl}/inventory/{$this->vehicle->id}";

        return (new MailMessage)
            ->subject("Vehicle Going to Auction: {$vehicleTitle}")
            ->greeting('Good news!')
            ->line("A vehicle you're watching has been listed in an upcoming auction.")
            ->line("**{$vehicleTitle}**")
            ->when($this->vehicle->vin, fn ($m) => $m->line("VIN: {$this->vehicle->vin}"))
            ->line("**Auction:** {$this->auction->title}")
            ->when($this->auction->location, fn ($m) => $m->line("**Location:** {$this->auction->location}"))
            ->line("**Date:** {$auctionDate}")
            ->action('View Vehicle', $inventoryUrl)
            ->line('You received this email because you subscribed to notifications for this vehicle.');
    }
}
