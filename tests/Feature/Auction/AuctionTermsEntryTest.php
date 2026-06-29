<?php

use App\Enums\LotStatus;
use App\Models\AuctionTerm;
use App\Models\AuctionTermAcceptance;
use App\Models\User;
use Database\Seeders\BidIncrementSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Eligibility: visitors ───────────────────────────────────────────────────

it('blocks unauthenticated visitors from entering with reason unauthenticated', function () {
    $auction = $this->createAuction();

    // entry-eligibility is an authenticated route; an unauthenticated request
    // is rejected by sanctum (401). Visitors are gated entirely on the client,
    // but the public terms endpoint must still be reachable.
    $this->getJson("/api/v1/auctions/{$auction->id}/entry-eligibility")
        ->assertUnauthorized();

    $this->getJson('/api/v1/auction-terms/current')
        ->assertOk()
        ->assertJsonPath('data.version', '1.0')
        ->assertJsonPath('data.header', 'Enter Auction');
});

// ── Eligibility: logged-in buyer flow ───────────────────────────────────────

it('reports terms_not_accepted for a buyer who has not accepted', function () {
    $auction = $this->createAuction();
    $this->actingAsBuyer();

    $this->getJson("/api/v1/auctions/{$auction->id}/entry-eligibility")
        ->assertOk()
        ->assertJsonPath('data.authenticated', true)
        ->assertJsonPath('data.terms_accepted', false)
        ->assertJsonPath('data.can_enter', false)
        ->assertJsonPath('data.reason', 'terms_not_accepted');
});

it('requires the agree checkbox to accept terms', function () {
    $auction = $this->createAuction();
    $this->actingAsBuyer();

    $this->assertValidationError(
        $this->postJson("/api/v1/auctions/{$auction->id}/accept-terms", ['agree' => false]),
        'agree'
    );
});

it('records an acceptance with user, auction, version and ip', function () {
    $auction = $this->createAuction();
    $user    = User::factory()->create(['status' => 'active']);
    $this->actingAs($user, 'sanctum');

    $this->postJson("/api/v1/auctions/{$auction->id}/accept-terms", ['agree' => true])
        ->assertOk()
        ->assertJsonPath('data.terms_accepted', true);

    $row = AuctionTermAcceptance::first();
    expect($row)->not->toBeNull()
        ->and($row->user_id)->toBe($user->id)
        ->and($row->auction_id)->toBe($auction->id)
        ->and($row->terms_version)->toBe('1.0')
        ->and($row->ip_address)->not->toBeNull()
        ->and($row->accepted_at)->not->toBeNull();
});

it('is idempotent — accepting twice creates one row', function () {
    $auction = $this->createAuction();
    $this->actingAsBuyer();

    $this->postJson("/api/v1/auctions/{$auction->id}/accept-terms", ['agree' => true])->assertOk();
    $this->postJson("/api/v1/auctions/{$auction->id}/accept-terms", ['agree' => true])->assertOk();

    expect(AuctionTermAcceptance::count())->toBe(1);
});

// ── Payment-method layer ────────────────────────────────────────────────────

it('after accepting, a buyer without a payment method is blocked with missing_payment', function () {
    $auction = $this->createAuction();
    $this->actingAsBuyer();

    $this->postJson("/api/v1/auctions/{$auction->id}/accept-terms", ['agree' => true]);

    $this->getJson("/api/v1/auctions/{$auction->id}/entry-eligibility")
        ->assertOk()
        ->assertJsonPath('data.terms_accepted', true)
        ->assertJsonPath('data.can_enter', false)
        ->assertJsonPath('data.reason', 'missing_payment');
});

it('a buyer who accepted terms and has a valid card can enter', function () {
    $auction = $this->createAuction();
    $user    = $this->givePaymentMethod(User::factory()->create(['status' => 'active']));
    $this->actingAs($user, 'sanctum');

    $this->postJson("/api/v1/auctions/{$auction->id}/accept-terms", ['agree' => true]);

    $this->getJson("/api/v1/auctions/{$auction->id}/entry-eligibility")
        ->assertOk()
        ->assertJsonPath('data.can_enter', true)
        ->assertJsonPath('data.reason', null);
});

// ── Role exemptions ─────────────────────────────────────────────────────────

it('exempts sellers from the payment-method requirement', function () {
    $auction = $this->createAuction();
    $seller  = User::factory()->create(['status' => 'active']);
    $seller->assignRole('seller');
    $this->actingAs($seller, 'sanctum');

    $this->postJson("/api/v1/auctions/{$auction->id}/accept-terms", ['agree' => true]);

    $this->getJson("/api/v1/auctions/{$auction->id}/entry-eligibility")
        ->assertOk()
        ->assertJsonPath('data.requires_payment', false)
        ->assertJsonPath('data.can_enter', true);
});

it('exempts admins from the terms gate entirely', function () {
    $auction = $this->createAuction();
    $this->actingAsAdmin();

    $this->getJson("/api/v1/auctions/{$auction->id}/entry-eligibility")
        ->assertOk()
        ->assertJsonPath('data.requires_terms', false)
        ->assertJsonPath('data.requires_payment', false)
        ->assertJsonPath('data.can_enter', true);
});

// ── Version-bump re-acceptance ──────────────────────────────────────────────

it('requires re-acceptance after the terms version is bumped', function () {
    $auction = $this->createAuction();
    $user    = $this->givePaymentMethod(User::factory()->create(['status' => 'active']));
    $this->actingAs($user, 'sanctum');

    $this->postJson("/api/v1/auctions/{$auction->id}/accept-terms", ['agree' => true]);

    // Admin publishes an update → version 1.0 becomes 1.1.
    AuctionTerm::publishUpdate(['intro' => 'Updated intro.']);
    expect(AuctionTerm::current()->version)->toBe('1.1');

    $this->getJson("/api/v1/auctions/{$auction->id}/entry-eligibility")
        ->assertOk()
        ->assertJsonPath('data.current_version', '1.1')
        ->assertJsonPath('data.accepted_version', '1.0')
        ->assertJsonPath('data.terms_accepted', false)
        ->assertJsonPath('data.reason', 'terms_not_accepted');
});

// ── Server-side enforcement at the bid endpoint (bypass-proof) ───────────────

it('rejects a bid when terms are not accepted, even with a valid card', function () {
    $this->seed(BidIncrementSeeder::class);

    $auction = $this->createAuction();
    [$vehicle] = $this->createVehicleWithSeller();
    $lot = $this->createLot(overrides: [
        'auction_id'  => $auction->id,
        'vehicle_id'  => $vehicle->id,
        'status'      => LotStatus::Open,
        'current_bid' => 1000,
    ]);

    $user = $this->givePaymentMethod(User::factory()->create(['status' => 'active']));
    $this->actingAs($user, 'sanctum');

    $this->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", ['amount' => 1000000])
        ->assertStatus(403)
        ->assertJsonPath('code', 'BID_NOT_ALLOWED')
        ->assertJsonPath('reason', 'terms_not_accepted');
});

it('allows a bid once terms are accepted and a card is on file', function () {
    $this->seed(BidIncrementSeeder::class);

    $auction = $this->createAuction();
    [$vehicle] = $this->createVehicleWithSeller();
    $lot = $this->createLot(overrides: [
        'auction_id'  => $auction->id,
        'vehicle_id'  => $vehicle->id,
        'status'      => LotStatus::Open,
        'current_bid' => 1000,
    ]);

    $user = $this->givePaymentMethod(User::factory()->create(['status' => 'active']));
    $this->actingAs($user, 'sanctum');
    $this->postJson("/api/v1/auctions/{$auction->id}/accept-terms", ['agree' => true]);

    $this->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", ['amount' => 1000000])
        ->assertOk()
        ->assertJsonPath('data.bid.amount', 1000000);
});
