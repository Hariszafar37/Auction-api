<?php

namespace Tests\Helpers;

use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\User;
use App\Models\Vehicle;

/**
 * Shared factory helpers for auction-related Feature tests.
 *
 * Register via: pest()->use(Tests\Helpers\CreatesAuctionData::class)->in('Feature/Auction');
 */
trait CreatesAuctionData
{
    /**
     * Create a live auction owned by a throw-away user.
     */
    protected function createAuction(array $overrides = []): Auction
    {
        $creator = User::factory()->create(['status' => 'active']);

        return Auction::create(array_merge([
            'title'      => 'Test Auction',
            'location'   => 'New York, NY',
            'starts_at'  => now()->subHour(),
            'status'     => AuctionStatus::Live,
            'created_by' => $creator->id,
        ], $overrides));
    }

    /**
     * Create a vehicle belonging to an optional seller.
     * If $seller is null, a new active user is created.
     */
    protected function createVehicle(?User $seller = null, array $overrides = []): Vehicle
    {
        $seller ??= User::factory()->create(['status' => 'active']);

        return Vehicle::create(array_merge([
            'seller_id' => $seller->id,
            'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
            'year'      => 2020,
            'make'      => 'Toyota',
            'model'     => 'Camry',
            'status'    => 'in_auction',
        ], $overrides));
    }

    /**
     * Create a vehicle and return [$vehicle, $seller].
     */
    protected function createVehicleWithSeller(array $vehicleOverrides = []): array
    {
        $seller  = User::factory()->create(['status' => 'active']);
        $vehicle = $this->createVehicle($seller, $vehicleOverrides);

        return [$vehicle, $seller];
    }

    /**
     * Create a Countdown lot (default status).
     * Reserve is null by default — pass reserve_price override to set one.
     */
    protected function createLot(?User $winner = null, array $overrides = []): AuctionLot
    {
        $winner ??= User::factory()->create(['status' => 'active']);

        return AuctionLot::create(array_merge([
            'auction_id'               => $this->createAuction()->id,
            'vehicle_id'               => $this->createVehicle()->id,
            'lot_number'               => 1,
            'status'                   => LotStatus::Countdown,
            'starting_bid'             => 500,
            'reserve_price'            => null,
            'current_bid'              => 1000,
            'current_winner_id'        => $winner->id,
            'countdown_seconds'        => 30,
            'requires_seller_approval' => false,
        ], $overrides));
    }

    /**
     * Create an IfSale lot (reserve not met, awaiting seller decision).
     * Defaults match the standard if_sale scenario.
     */
    protected function createIfSaleLot(User $winner, array $overrides = []): AuctionLot
    {
        return AuctionLot::create(array_merge([
            'auction_id'               => $this->createAuction()->id,
            'vehicle_id'               => $this->createVehicle()->id,
            'lot_number'               => 1,
            'status'                   => LotStatus::IfSale,
            'starting_bid'             => 500,
            'reserve_price'            => 5000,
            'current_bid'              => 1000,
            'current_winner_id'        => $winner->id,
            'bid_count'                => 1,
            'requires_seller_approval' => true,
            'closed_at'                => now(),
            'seller_notified_at'       => now(),
            'seller_decision_deadline' => now()->addHours(48),
        ], $overrides));
    }
}
