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

function makeDealerSeller(): User
{
    $dealer = User::factory()->create([
        'status'         => 'active',
        'account_type'   => 'dealer',
        'account_intent' => 'buyer_and_seller', // set automatically for dealers
    ]);
    $dealer->assignRole('dealer');
    DealerProfile::create([
        'user_id'         => $dealer->id,
        'company_name'    => 'Test Motors',
        'dealer_license'  => 'DLR-' . rand(100, 999),
        'approval_status' => 'approved',
    ]);
    return $dealer;
}

function makeBusinessSeller(): User
{
    // SQLite test DB only has individual/dealer in the account_type CHECK constraint.
    // hasSellIntent() never checks account_type — it checks roles + account_intent —
    // so individual+buyer_and_seller is the correct stand-in for a business seller.
    $user = User::factory()->create([
        'status'         => 'active',
        'account_type'   => 'individual',
        'account_intent' => 'buyer_and_seller',
    ]);
    // Buyer role is assigned at activation; inventory.create granted here
    // to simulate the future business-permission layer
    $user->assignRole('buyer');
    $user->givePermissionTo('inventory.create');
    return $user;
}

function makePoaAuction(): Auction
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

function grantApprovedPoa(User $user): PowerOfAttorney
{
    return PowerOfAttorney::create([
        'user_id'              => $user->id,
        'type'                 => 'esign',
        'status'               => 'approved',
        'signer_printed_name'  => $user->name,
    ]);
}

// ══ INDIVIDUAL SELLER ════════════════════════════════════════════════════

it('individual seller without POA cannot submit vehicle to auction', function () {
    $seller  = makeIndividualSeller();
    $auction = makePoaAuction();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available']);

    $this->actingAs($seller, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'   => $auction->id,
            'starting_bid' => 500,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'poa_required');
});

it('individual seller with only signed (pending) POA is still blocked', function () {
    $seller  = makeIndividualSeller();
    $auction = makePoaAuction();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available']);

    PowerOfAttorney::create([
        'user_id' => $seller->id,
        'type'    => 'esign',
        'status'  => 'signed',
        'signer_printed_name' => 'John Doe',
    ]);

    $this->actingAs($seller, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'   => $auction->id,
            'starting_bid' => 500,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'poa_required');
});

it('approved POA unlocks individual seller submission', function () {
    $seller  = makeIndividualSeller();
    $auction = makePoaAuction();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available']);

    grantApprovedPoa($seller);

    $this->actingAs($seller, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'   => $auction->id,
            'starting_bid' => 500,
        ])
        ->assertStatus(201);
});

// ══ DEALER SELLER ════════════════════════════════════════════════════════

it('dealer seller without approved POA cannot submit vehicle to auction', function () {
    $dealer  = makeDealerSeller();
    $auction = makePoaAuction();
    $vehicle = Vehicle::factory()->create(['seller_id' => $dealer->id, 'status' => 'available']);

    // No POA
    $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'   => $auction->id,
            'starting_bid' => 500,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'poa_required');
});

it('dealer seller with only signed POA is still blocked', function () {
    $dealer  = makeDealerSeller();
    $auction = makePoaAuction();
    $vehicle = Vehicle::factory()->create(['seller_id' => $dealer->id, 'status' => 'available']);

    PowerOfAttorney::create([
        'user_id' => $dealer->id,
        'type'    => 'upload',
        'status'  => 'signed',
        'file_path' => 'poa/test.pdf',
    ]);

    $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'   => $auction->id,
            'starting_bid' => 500,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'poa_required');
});

it('approved POA unlocks dealer seller submission', function () {
    $dealer  = makeDealerSeller();
    $auction = makePoaAuction();
    $vehicle = Vehicle::factory()->create(['seller_id' => $dealer->id, 'status' => 'available']);

    grantApprovedPoa($dealer);

    $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'   => $auction->id,
            'starting_bid' => 500,
        ])
        ->assertStatus(201);
});

// ══ BUSINESS SELLER ══════════════════════════════════════════════════════
// Business accounts use the buyer role today; inventory.create is granted
// directly in these tests to simulate the future business-permission layer.
// This ensures the POA gate is in place before that permission ships.

it('business seller without approved POA cannot submit vehicle to auction', function () {
    $business = makeBusinessSeller();
    $auction  = makePoaAuction();
    $vehicle  = Vehicle::factory()->create(['seller_id' => $business->id, 'status' => 'available']);

    // No POA
    $this->actingAs($business, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'   => $auction->id,
            'starting_bid' => 500,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'poa_required');
});

it('business seller with only signed POA is still blocked', function () {
    $business = makeBusinessSeller();
    $auction  = makePoaAuction();
    $vehicle  = Vehicle::factory()->create(['seller_id' => $business->id, 'status' => 'available']);

    PowerOfAttorney::create([
        'user_id' => $business->id,
        'type'    => 'esign',
        'status'  => 'signed',
        'signer_printed_name' => 'Acme Corp',
    ]);

    $this->actingAs($business, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'   => $auction->id,
            'starting_bid' => 500,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'poa_required');
});

it('approved POA unlocks business seller submission', function () {
    $business = makeBusinessSeller();
    $auction  = makePoaAuction();
    $vehicle  = Vehicle::factory()->create(['seller_id' => $business->id, 'status' => 'available']);

    grantApprovedPoa($business);

    $this->actingAs($business, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'   => $auction->id,
            'starting_bid' => 500,
        ])
        ->assertStatus(201);
});

// ══ ACCOUNT STATUS ENFORCEMENT ══════════════════════════════════════════════

it('pending_activation dealer cannot submit vehicle to auction', function () {
    $dealer = User::factory()->create([
        'status'         => 'pending_activation',
        'account_type'   => 'dealer',
        'account_intent' => 'buyer_and_seller',
    ]);
    $dealer->assignRole('dealer');
    DealerProfile::create([
        'user_id'         => $dealer->id,
        'company_name'    => 'Test Motors',
        'dealer_license'  => 'DLR-' . rand(100, 999),
        'approval_status' => 'approved',
    ]);
    // POA is approved so the POA gate does not fire first — status check must fire instead
    grantApprovedPoa($dealer);

    $auction = makePoaAuction();
    $vehicle = Vehicle::factory()->create(['seller_id' => $dealer->id, 'status' => 'available']);

    $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'   => $auction->id,
            'starting_bid' => 500,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'seller_inactive');
});

it('suspended dealer cannot submit vehicle to auction', function () {
    $dealer = User::factory()->create([
        'status'         => 'suspended',
        'account_type'   => 'dealer',
        'account_intent' => 'buyer_and_seller',
    ]);
    $dealer->assignRole('dealer');
    DealerProfile::create([
        'user_id'         => $dealer->id,
        'company_name'    => 'Test Motors',
        'dealer_license'  => 'DLR-' . rand(100, 999),
        'approval_status' => 'approved',
    ]);
    grantApprovedPoa($dealer);

    $auction = makePoaAuction();
    $vehicle = Vehicle::factory()->create(['seller_id' => $dealer->id, 'status' => 'available']);

    $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'   => $auction->id,
            'starting_bid' => 500,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'seller_inactive');
});

// ══ BUYER ACCESS UNAFFECTED ═══════════════════════════════════════════════

it('pure buyer can bid without any POA — buyer flow is not blocked', function () {
    $adminCreator = User::factory()->create(['status' => 'active']);
    $seller       = User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Buyer Test',
        'location'   => 'Baltimore',
        'starts_at'  => now()->subHour(),
        'status'     => AuctionStatus::Live,
        'created_by' => $adminCreator->id,
    ]);

    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'in_auction']);

    $lot = \App\Models\AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => \App\Enums\LotStatus::Open,
        'starting_bid'             => 1000,
        'dealer_only'              => false,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ]);

    $buyer = User::factory()->create([
        'status'         => 'active',
        'account_type'   => 'individual',
        'account_intent' => 'buyer',
    ]);
    $buyer->assignRole('buyer');

    // Seed a valid payment method so the POA-less bid is not blocked by
    // the unrelated payment gate.
    $buyer->billingInformation()->updateOrCreate(
        ['user_id' => $buyer->id],
        [
            'billing_address'         => '123 Test St',
            'billing_country'         => 'US',
            'billing_city'            => 'Baltimore',
            'billing_zip_postal_code' => '21201',
            'payment_method_added'    => true,
            'cardholder_name'         => 'Test User',
            'card_brand'              => 'visa',
            'card_last_four'          => '4242',
            'card_expiry_month'       => 12,
            'card_expiry_year'        => (int) now()->year + 5,
        ]
    );

    // Buyer has no POA — bidding must still work
    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", [
            'amount' => 1000,
        ])
        ->assertStatus(200);
});
