<?php

use App\Models\AuctionLot;
use App\Models\Auction;
use App\Models\DealerProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function makeDealerOnlyLot(): array
{
    $seller = User::factory()->create(['status' => 'active']);
    $adminCreator = User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Dealer Auction',
        'location'   => 'Baltimore, MD',
        'starts_at'  => now()->subHour(),
        'status'     => AuctionStatus::Live,
        'created_by' => $adminCreator->id,
    ]);

    $vehicle = Vehicle::factory()->create([
        'seller_id' => $seller->id,
        'status'    => 'in_auction',
    ]);

    $lot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Open,
        'starting_bid'             => 1000,
        'current_bid'              => null,
        'dealer_only'              => true,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ]);

    return [$auction, $lot, $seller];
}

function makeApprovedDealer(): User
{
    $dealer = User::factory()->create([
        'status'       => 'active',
        'account_type' => 'dealer',
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

/**
 * Seed a valid payment method directly (helper function — function-scoped tests
 * can't reach $this-> protected methods from top-level closures).
 */
function givePayment(User $user): void
{
    $user->billingInformation()->updateOrCreate(
        ['user_id' => $user->id],
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
}

// Test 7: Dealer-only lot rejects bid from individual account
it('individual user cannot bid on a dealer-only lot', function () {
    [$auction, $lot] = makeDealerOnlyLot();

    $buyer = User::factory()->create(['status' => 'active']);
    givePayment($buyer); // isolate the dealer-only check from the payment gate

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", [
            'amount' => 1000,
        ]);

    $response->assertStatus(422);
    $errors = $response->json('errors');
    expect($errors)->toHaveKey('lot');
});

// Test 8: Dealer-only lot allows bid from eligible dealer
it('approved dealer can bid on a dealer-only lot', function () {
    [$auction, $lot] = makeDealerOnlyLot();

    $dealer = makeApprovedDealer();
    givePayment($dealer);

    $response = $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", [
            'amount' => 1000,
        ]);

    $response->assertStatus(200);
});

// Test: Individual buyer can bid on non-dealer-only lots
it('individual buyer can bid on a public (non-dealer-only) lot', function () {
    $seller = User::factory()->create(['status' => 'active']);
    $adminCreator = User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Public Auction',
        'location'   => 'Baltimore, MD',
        'starts_at'  => now()->subHour(),
        'status'     => AuctionStatus::Live,
        'created_by' => $adminCreator->id,
    ]);

    $vehicle = Vehicle::factory()->create([
        'seller_id' => $seller->id,
        'status'    => 'in_auction',
    ]);

    $lot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Open,
        'starting_bid'             => 1000,
        'current_bid'              => null,
        'dealer_only'              => false,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ]);

    $buyer = User::factory()->create(['status' => 'active']);
    givePayment($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", [
            'amount' => 1000,
        ]);

    // Test 2: individual buyer functionality (bidding) is NOT blocked on public lots
    $response->assertStatus(200);
});

// Proxy bid also blocked for individual on dealer-only lot
it('individual user cannot set proxy bid on dealer-only lot', function () {
    [$auction, $lot] = makeDealerOnlyLot();

    $buyer = User::factory()->create(['status' => 'active']);
    givePayment($buyer); // isolate dealer-only check from payment gate

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/proxy-bid", [
            'max_amount' => 2000,
        ]);

    $response->assertStatus(422);
    $errors = $response->json('errors');
    expect($errors)->toHaveKey('lot');
});
