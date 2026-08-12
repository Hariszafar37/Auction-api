<?php

use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use App\Models\AuctionLot;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;

/**
 * Every payload that carries an auction time must also carry the zone to read
 * it in.
 *
 * Without the zone the client has only two options: render on the reader's
 * clock (the bug this whole effort exists to remove) or guess. These
 * assertions exist so a future resource change cannot quietly drop the field
 * and push a surface back onto the browser's timezone.
 */

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function tzExposureAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    return $admin;
}

it('exposes the timezone on the auction resource', function () {
    $auction = $this->createAuction([
        'status'   => AuctionStatus::Scheduled,
        'timezone' => 'America/Denver',
    ]);

    $this->actingAs(tzExposureAdmin(), 'sanctum')
        ->getJson("/api/v1/admin/auctions/{$auction->id}")
        ->assertOk()
        ->assertJsonPath('data.timezone', 'America/Denver');
});

it('exposes the auction timezone on a lot when the relation is loaded', function () {
    $auction = $this->createAuction([
        'status'   => AuctionStatus::Live,
        'timezone' => 'America/Los_Angeles',
    ]);

    $lot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $this->createVehicle()->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Open,
        'starting_bid'             => 500,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ]);

    $this->actingAs(tzExposureAdmin(), 'sanctum')
        ->getJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}")
        ->assertOk()
        ->assertJsonPath('data.auction_timezone', 'America/Los_Angeles');
});

it('degrades to null rather than erroring when the auction is not loaded', function () {
    // Some endpoints load only `vehicle`. Null is a safe answer: the client
    // falls back to the platform zone, which is still not the browser's.
    $auction = $this->createAuction(['timezone' => 'America/Chicago']);

    $lot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $this->createVehicle()->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Open,
        'starting_bid'             => 500,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ]);

    $resource = (new App\Http\Resources\Auction\AuctionLotResource($lot))
        ->toArray(request());

    expect($resource)->toHaveKey('auction_timezone')
        ->and($resource['auction_timezone'])->toBeNull();
});

it('exposes the auction timezone on a won lot', function () {
    $winner  = User::factory()->create(['status' => 'active']);
    $auction = $this->createAuction([
        'status'   => AuctionStatus::Ended,
        'timezone' => 'America/Phoenix',
    ]);

    AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $this->createVehicle()->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Sold,
        'starting_bid'             => 500,
        'current_bid'              => 1000,
        'sold_price'               => 1000,
        'current_winner_id'        => $winner->id,
        // WonLotsController filters on buyer_id, which closeLot() sets.
        'buyer_id'                 => $winner->id,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
        'closed_at'                => now(),
    ]);

    $this->actingAs($winner, 'sanctum')
        ->getJson('/api/v1/my/won')
        ->assertOk()
        ->assertJsonPath('data.0.auction.timezone', 'America/Phoenix');
});

it('serialises won-lot times as ISO strings with an explicit offset', function () {
    $winner  = User::factory()->create(['status' => 'active']);
    $auction = $this->createAuction(['status' => AuctionStatus::Ended]);

    AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $this->createVehicle()->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Sold,
        'starting_bid'             => 500,
        'current_bid'              => 1000,
        'sold_price'               => 1000,
        'current_winner_id'        => $winner->id,
        // WonLotsController filters on buyer_id, which closeLot() sets.
        'buyer_id'                 => $winner->id,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
        'closed_at'                => now(),
    ]);

    $response = $this->actingAs($winner, 'sanctum')->getJson('/api/v1/my/won')->assertOk();

    expect($response->json('data.0.closed_at'))->toEndWith('+00:00')
        ->and($response->json('data.0.auction.starts_at'))->toEndWith('+00:00');
});

it('exposes the auction timezone on a public vehicle with an active lot', function () {
    $auction = $this->createAuction([
        'status'   => AuctionStatus::Live,
        'timezone' => 'America/Anchorage',
    ]);

    $vehicle = Vehicle::create([
        'seller_id' => User::factory()->create(['status' => 'active'])->id,
        'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'      => 2020,
        'make'      => 'Toyota',
        'model'     => 'Camry',
        'status'    => 'in_auction',
    ]);

    AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Open,
        'starting_bid'             => 500,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ]);

    $this->getJson("/api/v1/vehicles/{$vehicle->id}")
        ->assertOk()
        ->assertJsonPath('data.active_lot.auction_timezone', 'America/Anchorage');
});
