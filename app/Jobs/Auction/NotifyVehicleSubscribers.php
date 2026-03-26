<?php

namespace App\Jobs\Auction;

use App\Models\Auction;
use App\Models\Vehicle;
use App\Models\VehicleNotificationSubscription;
use App\Notifications\VehicleGoingToAuction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a vehicle is assigned to an auction lot (status → in_auction).
 *
 * Sends a VehicleGoingToAuction notification email to every subscriber who
 * has not yet been notified. Marks each subscription as notified to prevent
 * duplicate sends if the job is retried.
 */
class NotifyVehicleSubscribers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $vehicleId,
        private readonly int $auctionId,
    ) {}

    public function handle(): void
    {
        $vehicle = Vehicle::find($this->vehicleId);
        $auction = Auction::find($this->auctionId);

        // If either model was deleted after dispatch, skip silently.
        if (! $vehicle || ! $auction) {
            return;
        }

        VehicleNotificationSubscription::where('vehicle_id', $this->vehicleId)
            ->whereNull('notified_at')
            ->with('user')
            ->each(function (VehicleNotificationSubscription $subscription) use ($vehicle, $auction) {
                // Guard: skip if user was deleted
                if (! $subscription->user) {
                    return;
                }

                $subscription->user->notify(new VehicleGoingToAuction($vehicle, $auction));
                $subscription->markNotified();
            });
    }
}
