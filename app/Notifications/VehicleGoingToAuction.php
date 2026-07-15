<?php

namespace App\Notifications;

use App\Models\Auction;
use App\Models\Vehicle;
use App\Notifications\Concerns\RendersFromTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to users who subscribed to a "Notify Me" alert on a vehicle.
 * Fires when the vehicle is submitted to an auction (status → in_auction).
 *
 * Mail-only. The VIN and Location lines drop out automatically when those values
 * are empty — see NotificationTemplateRenderer::renderBodyLines().
 */
class VehicleGoingToAuction extends Notification
{
    use Queueable, RendersFromTemplate;

    public function __construct(
        private readonly Vehicle $vehicle,
        private readonly Auction $auction,
    ) {}

    protected function templateKey(): string
    {
        return 'vehicle_going_to_auction';
    }

    protected function templateVariables(mixed $notifiable): array
    {
        return [
            'vehicle_name'     => "{$this->vehicle->year} {$this->vehicle->make} {$this->vehicle->model}",
            'vin'              => $this->vehicle->vin,
            'auction_title'    => $this->auction->title,
            'auction_location' => $this->auction->location,
            'auction_date'     => $this->auction->starts_at?->format('F j, Y \a\t g:i A T') ?? 'TBD',
        ];
    }

    protected function actionPayload(): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'action_url' => "{$frontendUrl}/inventory/{$this->vehicle->id}",
            'meta'       => [
                'vehicle_id' => $this->vehicle->id,
                'auction_id' => $this->auction->id,
            ],
        ];
    }
}
