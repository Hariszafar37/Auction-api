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

function makeDealerWithClassification(string $classification): array
{
    $dealer = User::factory()->create([
        'status'       => 'active',
        'account_type' => 'dealer',
    ]);
    $dealer->assignRole('dealer');
    $profile = DealerProfile::create([
        'user_id'                => $dealer->id,
        'company_name'           => 'Test Motors',
        'dealer_license'         => 'DLR-' . rand(100, 999),
        'approval_status'        => 'approved',
        'dealer_classification'  => $classification,
        'can_sell_to_public'     => in_array($classification, ['maryland_retail', 'out_of_state_retail']),
        'inspection_passed'      => null,
        'bill_of_sale_received'  => false,
    ]);
    // Phase 6: all dealers need an approved POA before submitting vehicles
    PowerOfAttorney::create([
        'user_id'             => $dealer->id,
        'type'                => 'esign',
        'status'              => 'approved',
        'signer_printed_name' => $dealer->name,
    ]);
    return [$dealer, $profile];
}

function makeVehicleForDealer(User $dealer, array $overrides = []): Vehicle
{
    return Vehicle::factory()->create(array_merge([
        'seller_id' => $dealer->id,
        'status'    => 'available',
    ], $overrides));
}

function makeDraftAuction(): Auction
{
    $admin = User::factory()->create(['status' => 'active']);
    return Auction::create([
        'title'      => 'Title Test Auction',
        'location'   => 'Baltimore, MD',
        'starts_at'  => now()->addDay(),
        'status'     => AuctionStatus::Draft,
        'created_by' => $admin->id,
    ]);
}

// Test 3: Maryland retail dealer blocked without title_received
it('maryland retail dealer cannot list without title_received', function () {
    [$dealer, $profile] = makeDealerWithClassification('maryland_retail');
    $profile->update(['inspection_passed' => true]);

    $auction = makeDraftAuction();
    $vehicle = makeVehicleForDealer($dealer, ['title_received' => false]);

    $response = $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'    => $auction->id,
            'starting_bid'  => 500,
            'reserve_price' => null,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['vehicle']);
});

// Test 4: Maryland retail dealer blocked without inspection_passed
it('maryland retail dealer cannot list without inspection_passed', function () {
    [$dealer, $profile] = makeDealerWithClassification('maryland_retail');
    $profile->update(['inspection_passed' => false]);

    $auction = makeDraftAuction();
    $vehicle = makeVehicleForDealer($dealer, ['title_received' => true]);

    $response = $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'    => $auction->id,
            'starting_bid'  => 500,
            'reserve_price' => null,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['vehicle']);
});

// Maryland retail dealer CAN list when both title_received and inspection_passed
it('maryland retail dealer can list when title_received and inspection_passed', function () {
    [$dealer, $profile] = makeDealerWithClassification('maryland_retail');
    $profile->update(['inspection_passed' => true]);

    $auction = makeDraftAuction();
    $vehicle = makeVehicleForDealer($dealer, ['title_received' => true]);

    $response = $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'    => $auction->id,
            'starting_bid'  => 500,
            'reserve_price' => null,
        ]);

    $response->assertStatus(201);
});

// Test 6: Out-of-state retail dealer blocked without bill_of_sale_received
it('out_of_state retail dealer cannot list without bill_of_sale_received', function () {
    [$dealer, $profile] = makeDealerWithClassification('out_of_state_retail');
    $profile->update(['bill_of_sale_received' => false]);

    $auction = makeDraftAuction();
    $vehicle = makeVehicleForDealer($dealer, ['title_received' => true]);

    $response = $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'    => $auction->id,
            'starting_bid'  => 500,
            'reserve_price' => null,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['vehicle']);
});

// Out-of-state retail dealer blocked without title_received
it('out_of_state retail dealer cannot list without title_received', function () {
    [$dealer, $profile] = makeDealerWithClassification('out_of_state_retail');
    $profile->update(['bill_of_sale_received' => true]);

    $auction = makeDraftAuction();
    $vehicle = makeVehicleForDealer($dealer, ['title_received' => false]);

    $response = $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'    => $auction->id,
            'starting_bid'  => 500,
            'reserve_price' => null,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['vehicle']);
});

// Test 9: Admin can add a lot bypassing title/compliance checks
it('admin can add vehicle lot without title_received (compliance bypass)', function () {
    $this->seed(Database\Seeders\RolePermissionSeeder::class); // already seeded but idempotent

    [$dealer, $profile] = makeDealerWithClassification('maryland_retail');
    $profile->update(['inspection_passed' => false]);

    $auction = makeDraftAuction();
    $vehicle = makeVehicleForDealer($dealer, ['title_received' => false]);

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/auctions/{$auction->id}/lots", [
            'vehicle_id'               => $vehicle->id,
            'starting_bid'             => 500,
            'reserve_price'            => 1000,
            'countdown_seconds'        => 30,
            'requires_seller_approval' => false,
        ]);

    $response->assertStatus(201);
});

// Wholesale dealer is NOT subject to title checks
it('wholesale dealer can list without title_received (no title check for wholesale)', function () {
    [$dealer] = makeDealerWithClassification('maryland_wholesale');

    $auction = makeDraftAuction();
    $vehicle = makeVehicleForDealer($dealer, ['title_received' => false]);

    $response = $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'    => $auction->id,
            'starting_bid'  => 500,
            'reserve_price' => null,
        ]);

    // Wholesale: no title requirement → should pass (201)
    $response->assertStatus(201);
});
