<?php

use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function makeBuyerForLocation(): User
{
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $user->assignRole('buyer');
    return $user;
}

function makeActiveAuction(string $location, string $status = 'scheduled'): Auction
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    return Auction::create([
        'title'      => "Auction at {$location}",
        'location'   => $location,
        'timezone'   => 'America/New_York',
        'starts_at'  => now()->addDays(7),
        'status'     => $status,
        'created_by' => $admin->id,
    ]);
}

function makeVehicleInAuction(Auction $auction): Vehicle
{
    $seller  = User::factory()->create(['status' => 'active']);
    $vehicle = Vehicle::factory()->inAuction()->create(['seller_id' => $seller->id]);

    AuctionLot::create([
        'auction_id'                => $auction->id,
        'vehicle_id'                => $vehicle->id,
        'lot_number'                => 'LOT-' . rand(100, 999),
        'status'                    => 'pending',
        'starting_bid'              => 1000,
        'reserve_price'             => 2000,
        'countdown_seconds'         => 60,
        'requires_seller_approval'  => false,
    ]);

    return $vehicle;
}

// ── Locations endpoint ────────────────────────────────────────────────────

it('returns distinct auction locations for scheduled and live auctions', function () {
    makeActiveAuction('Los Angeles, CA', 'scheduled');
    makeActiveAuction('Dallas, TX', 'live');
    makeActiveAuction('Los Angeles, CA', 'scheduled'); // duplicate — should deduplicate

    $buyer    = makeBuyerForLocation();
    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/vehicles/locations');

    $response->assertStatus(200);
    $locations = $response->json('data');
    expect($locations)->toBeArray();
    expect(array_unique($locations))->toEqual($locations); // no duplicates
    expect($locations)->toContain('Los Angeles, CA');
    expect($locations)->toContain('Dallas, TX');
});

it('excludes ended or cancelled auctions from locations', function () {
    makeActiveAuction('Phoenix, AZ', 'ended');
    makeActiveAuction('Seattle, WA', 'cancelled');
    makeActiveAuction('Miami, FL', 'scheduled');

    $buyer     = makeBuyerForLocation();
    $locations = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/vehicles/locations')
        ->assertStatus(200)
        ->json('data');

    expect($locations)->toBeArray();
    expect($locations)->not->toContain('Phoenix, AZ');
    expect($locations)->not->toContain('Seattle, WA');
    expect($locations)->toContain('Miami, FL');
});

it('returns empty array when no active auctions have locations', function () {
    $buyer = makeBuyerForLocation();
    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/vehicles/locations')
        ->assertStatus(200)
        ->assertJsonPath('data', []);
});

it('returns 401 for unauthenticated locations request', function () {
    $this->getJson('/api/v1/vehicles/locations')
        ->assertStatus(401);
});

// ── Inventory location filter ─────────────────────────────────────────────

it('filters public inventory by auction location', function () {
    $auctionLA  = makeActiveAuction('Los Angeles, CA', 'live');
    $auctionDFW = makeActiveAuction('Dallas, TX', 'scheduled');

    $vehicleLA  = makeVehicleInAuction($auctionLA);
    $vehicleDFW = makeVehicleInAuction($auctionDFW);

    $buyer    = makeBuyerForLocation();
    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/vehicles?location=Los+Angeles%2C+CA');

    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($vehicleLA->id);
    expect($ids)->not->toContain($vehicleDFW->id);
});

it('returns all in-auction vehicles when no location filter applied', function () {
    $auctionLA  = makeActiveAuction('Los Angeles, CA', 'live');
    $auctionDFW = makeActiveAuction('Dallas, TX', 'scheduled');

    $vehicleLA  = makeVehicleInAuction($auctionLA);
    $vehicleDFW = makeVehicleInAuction($auctionDFW);

    $buyer    = makeBuyerForLocation();
    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/vehicles');

    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($vehicleLA->id);
    expect($ids)->toContain($vehicleDFW->id);
});

it('returns empty results for a location with no vehicles', function () {
    makeActiveAuction('Chicago, IL', 'scheduled');

    $buyer = makeBuyerForLocation();
    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/vehicles?location=Chicago%2C+IL');

    $response->assertStatus(200);
    expect($response->json('data'))->toBeEmpty();
});

it('location filter does not expose unavailable vehicles', function () {
    $auction   = makeActiveAuction('Houston, TX', 'live');
    $vehicleIn = makeVehicleInAuction($auction);

    // An available vehicle with no lot — should not appear in location-filtered results
    $seller       = User::factory()->create(['status' => 'active']);
    $vehicleAvail = Vehicle::factory()->available()->create(['seller_id' => $seller->id]);

    $buyer    = makeBuyerForLocation();
    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/vehicles?location=Houston%2C+TX');

    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($vehicleIn->id);
    expect($ids)->not->toContain($vehicleAvail->id);
});
