<?php

use App\Models\AuctionLot;
use App\Models\DealerProfile;
use App\Models\PowerOfAttorney;
use App\Models\Auction;
use App\Models\User;
use App\Models\Vehicle;
use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function makeWholesaleDealer(): User
{
    $dealer = User::factory()->create([
        'status'       => 'active',
        'account_type' => 'dealer',
    ]);
    $dealer->assignRole('dealer');
    DealerProfile::create([
        'user_id'               => $dealer->id,
        'company_name'          => 'Wholesale Motors',
        'dealer_license'        => 'DLR-WHL-' . rand(100, 999),
        'approval_status'       => 'approved',
        'dealer_classification' => 'maryland_wholesale',
        'can_sell_to_public'    => false,
    ]);
    return $dealer;
}

function makeRetailDealer(): User
{
    $dealer = User::factory()->create([
        'status'       => 'active',
        'account_type' => 'dealer',
    ]);
    $dealer->assignRole('dealer');
    DealerProfile::create([
        'user_id'               => $dealer->id,
        'company_name'          => 'Retail Motors',
        'dealer_license'        => 'DLR-RTL-' . rand(100, 999),
        'approval_status'       => 'approved',
        'dealer_classification' => 'maryland_retail',
        'can_sell_to_public'    => true,
        'inspection_passed'     => true,
    ]);
    return $dealer;
}

// Test 5: Maryland wholesale dealer inventory not visible publicly
it('wholesale dealer vehicle is hidden from individual buyer on public inventory', function () {
    $wholesaleDealer = makeWholesaleDealer();
    $vehicle = Vehicle::factory()->create([
        'seller_id' => $wholesaleDealer->id,
        'status'    => 'available',
    ]);

    $buyer = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/vehicles');

    $response->assertStatus(200);
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->not->toContain($vehicle->id);
});

it('wholesale dealer vehicle is NOT hidden from dealer users', function () {
    $wholesaleDealer = makeWholesaleDealer();
    $vehicle = Vehicle::factory()->create([
        'seller_id' => $wholesaleDealer->id,
        'status'    => 'available',
    ]);

    $buyerDealer = makeRetailDealer();

    $response = $this->actingAs($buyerDealer, 'sanctum')
        ->getJson('/api/v1/vehicles');

    $response->assertStatus(200);
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->toContain($vehicle->id);
});

it('retail dealer vehicle IS visible to individual buyer', function () {
    $retailDealer = makeRetailDealer();
    $vehicle = Vehicle::factory()->create([
        'seller_id' => $retailDealer->id,
        'status'    => 'available',
    ]);

    $buyer = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/vehicles');

    $response->assertStatus(200);
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->toContain($vehicle->id);
});

it('wholesale dealer vehicle detail returns 404 for individual buyer', function () {
    $wholesaleDealer = makeWholesaleDealer();
    $vehicle = Vehicle::factory()->create([
        'seller_id' => $wholesaleDealer->id,
        'status'    => 'available',
    ]);

    $buyer = User::factory()->create(['status' => 'active']);

    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/vehicles/{$vehicle->id}")
        ->assertStatus(404);
});

it('dealer_only lot is hidden from non-dealer in auction lot listing', function () {
    $adminCreator = User::factory()->create(['status' => 'active']);
    $auction = Auction::create([
        'title'      => 'Test',
        'location'   => 'Baltimore',
        'starts_at'  => now()->subHour(),
        'status'     => AuctionStatus::Live,
        'created_by' => $adminCreator->id,
    ]);

    $wholesaleDealer = makeWholesaleDealer();
    $vehicle = Vehicle::factory()->create([
        'seller_id' => $wholesaleDealer->id,
        'status'    => 'in_auction',
    ]);

    $dealerOnlyLot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Open,
        'starting_bid'             => 1000,
        'dealer_only'              => true,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ]);

    $buyer = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/auctions/{$auction->id}/lots");

    $response->assertStatus(200);
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->not->toContain($dealerOnlyLot->id);
});

it('dealer_only lot IS visible to dealer in auction lot listing', function () {
    $adminCreator = User::factory()->create(['status' => 'active']);
    $auction = Auction::create([
        'title'      => 'Test',
        'location'   => 'Baltimore',
        'starts_at'  => now()->subHour(),
        'status'     => AuctionStatus::Live,
        'created_by' => $adminCreator->id,
    ]);

    $wholesaleDealer = makeWholesaleDealer();
    $vehicle = Vehicle::factory()->create([
        'seller_id' => $wholesaleDealer->id,
        'status'    => 'in_auction',
    ]);

    $dealerOnlyLot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Open,
        'starting_bid'             => 1000,
        'dealer_only'              => true,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ]);

    $buyerDealer = makeRetailDealer();

    $response = $this->actingAs($buyerDealer, 'sanctum')
        ->getJson("/api/v1/auctions/{$auction->id}/lots");

    $response->assertStatus(200);
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->toContain($dealerOnlyLot->id);
});

it('addLot auto-sets dealer_only=true for wholesale dealer vehicle', function () {
    $wholesaleDealer = makeWholesaleDealer();
    $auction = Auction::create([
        'title'      => 'Test',
        'location'   => 'Baltimore',
        'starts_at'  => now()->addDay(),
        'status'     => AuctionStatus::Draft,
        'created_by' => $wholesaleDealer->id,
    ]);
    $vehicle = Vehicle::factory()->create([
        'seller_id' => $wholesaleDealer->id,
        'status'    => 'available',
    ]);

    // Phase 6: all dealers need an approved POA before submitting
    PowerOfAttorney::create([
        'user_id'             => $wholesaleDealer->id,
        'type'                => 'esign',
        'status'              => 'approved',
        'signer_printed_name' => $wholesaleDealer->name,
    ]);

    $response = $this->actingAs($wholesaleDealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'    => $auction->id,
            'starting_bid'  => 500,
            'reserve_price' => null,
        ]);

    $response->assertStatus(201);
    $this->assertDatabaseHas('auction_lots', [
        'vehicle_id'  => $vehicle->id,
        'dealer_only' => true,
    ]);
});
