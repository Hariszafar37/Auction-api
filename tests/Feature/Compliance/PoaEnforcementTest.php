<?php

use App\Models\DealerProfile;
use App\Models\PowerOfAttorney;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Auction;
use App\Enums\AuctionStatus;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function makeIndividualSeller(): User
{
    $user = User::factory()->create([
        'status'         => 'active',
        'account_type'   => 'individual',
        'account_intent' => 'seller',
    ]);
    $user->assignRole('seller');
    return $user;
}

function makeScheduledAuction(): Auction
{
    $admin = User::factory()->create(['status' => 'active']);
    return Auction::create([
        'title'      => 'Test Auction',
        'location'   => 'Baltimore, MD',
        'starts_at'  => now()->addDay(),
        'status'     => AuctionStatus::Scheduled,
        'created_by' => $admin->id,
    ]);
}

// Test 1: Individual seller without approved POA cannot submit vehicle to auction
it('individual seller without approved POA cannot submit vehicle to auction', function () {
    $seller  = makeIndividualSeller();
    $auction = makeScheduledAuction();
    $vehicle = Vehicle::factory()->create([
        'seller_id' => $seller->id,
        'status'    => 'available',
    ]);

    // No POA at all
    $response = $this->actingAs($seller, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'    => $auction->id,
            'starting_bid'  => 500,
            'reserve_price' => null,
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('code', 'poa_required');
});

// Test 2: Individual seller with signed (not yet approved) POA cannot submit
it('individual seller with only signed POA cannot submit vehicle to auction', function () {
    $seller  = makeIndividualSeller();
    $auction = makeScheduledAuction();
    $vehicle = Vehicle::factory()->create([
        'seller_id' => $seller->id,
        'status'    => 'available',
    ]);

    PowerOfAttorney::create([
        'user_id' => $seller->id,
        'type'    => 'esign',
        'status'  => 'signed',
        'signer_printed_name' => 'John Doe',
    ]);

    $response = $this->actingAs($seller, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'    => $auction->id,
            'starting_bid'  => 500,
            'reserve_price' => null,
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('code', 'poa_required');
});

// Test 10: Approved POA unlocks seller restriction
it('individual seller with approved POA can submit vehicle to auction', function () {
    $seller  = makeIndividualSeller();
    $auction = makeScheduledAuction();
    $vehicle = Vehicle::factory()->create([
        'seller_id' => $seller->id,
        'status'    => 'available',
    ]);

    PowerOfAttorney::create([
        'user_id' => $seller->id,
        'type'    => 'esign',
        'status'  => 'approved',
        'signer_printed_name' => 'John Doe',
    ]);

    $response = $this->actingAs($seller, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'    => $auction->id,
            'starting_bid'  => 500,
            'reserve_price' => null,
        ]);

    $response->assertStatus(201);
});

// Test: Dealer (not individual seller) can submit without POA
it('dealer can submit vehicle without POA', function () {
    $dealer = User::factory()->create([
        'status'       => 'active',
        'account_type' => 'dealer',
    ]);
    $dealer->assignRole('dealer');
    DealerProfile::create([
        'user_id'         => $dealer->id,
        'company_name'    => 'Test Motors',
        'dealer_license'  => 'DLR-001',
        'approval_status' => 'approved',
    ]);

    $auction = makeScheduledAuction();
    $vehicle = Vehicle::factory()->create([
        'seller_id' => $dealer->id,
        'status'    => 'available',
    ]);

    $response = $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'    => $auction->id,
            'starting_bid'  => 500,
            'reserve_price' => null,
        ]);

    // Should succeed (no POA required for dealers)
    $response->assertStatus(201);
});
