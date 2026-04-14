<?php

use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\User;
use App\Models\Vehicle;
use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Build an open lot that's ready to accept bids. Kept in-file instead of
 * reusing the PaymentMethodTest helper because Pest lazy-loads test files
 * and the helper there is function-scoped, not autoloaded.
 */
function makeBidEligibilityLot(): array
{
    $seller  = User::factory()->create(['status' => 'active']);
    $creator = User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Bid Eligibility Test Auction',
        'location'   => 'Baltimore, MD',
        'starts_at'  => now()->subHour(),
        'status'     => AuctionStatus::Live,
        'created_by' => $creator->id,
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
        'dealer_only'              => false,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ]);

    return [$auction, $lot];
}

// ══ User::canBid() — unit matrix ══════════════════════════════════════════════

it('canBid returns true for active user with valid payment method', function () {
    $user = User::factory()->create(['status' => 'active']);
    $this->givePaymentMethod($user);

    expect($user->canBid())->toBeTrue()
        ->and($user->getBidIneligibilityReason())->toBeNull();
});

it('canBid returns false with missing_payment reason when no card', function () {
    $user = User::factory()->create(['status' => 'active']);

    expect($user->canBid())->toBeFalse()
        ->and($user->getBidIneligibilityReason())->toBe('missing_payment');
});

it('canBid returns false with inactive_account reason when status pending_activation', function () {
    $user = User::factory()->create(['status' => 'pending_activation']);
    $this->givePaymentMethod($user);

    expect($user->canBid())->toBeFalse()
        ->and($user->getBidIneligibilityReason())->toBe('inactive_account');
});

it('canBid returns false with suspended reason for suspended users', function () {
    $user = User::factory()->create(['status' => 'suspended']);
    $this->givePaymentMethod($user);

    expect($user->canBid())->toBeFalse()
        ->and($user->getBidIneligibilityReason())->toBe('suspended');
});

it('suspended takes priority over missing_payment in reason ordering', function () {
    // A suspended user without a card should surface the suspended reason
    // first — it's the most specific actionable state.
    $user = User::factory()->create(['status' => 'suspended']);

    expect($user->getBidIneligibilityReason())->toBe('suspended');
});

// ══ BiddingService enforcement ════════════════════════════════════════════════

it('bid succeeds for fully eligible user', function () {
    [$auction, $lot] = makeBidEligibilityLot();
    $buyer = User::factory()->create(['status' => 'active']);
    $this->givePaymentMethod($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", [
            'amount' => 1000,
        ]);

    $response->assertStatus(200);
});

it('inactive user is blocked with BID_NOT_ALLOWED/inactive_account', function () {
    [$auction, $lot] = makeBidEligibilityLot();

    // pending_activation but has a card — still blocked because status !== active
    $buyer = User::factory()->create(['status' => 'pending_activation']);
    $this->givePaymentMethod($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", [
            'amount' => 1000,
        ]);

    $response->assertStatus(403)
             ->assertJsonPath('code', 'BID_NOT_ALLOWED')
             ->assertJsonPath('reason', 'inactive_account')
             ->assertJsonPath('success', false);
});

it('suspended user is blocked with BID_NOT_ALLOWED/suspended', function () {
    [$auction, $lot] = makeBidEligibilityLot();

    $buyer = User::factory()->create(['status' => 'suspended']);
    $this->givePaymentMethod($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/bids", [
            'amount' => 1000,
        ]);

    $response->assertStatus(403)
             ->assertJsonPath('code', 'BID_NOT_ALLOWED')
             ->assertJsonPath('reason', 'suspended');
});

it('proxy bid by inactive user is blocked with inactive_account reason', function () {
    [$auction, $lot] = makeBidEligibilityLot();

    $buyer = User::factory()->create(['status' => 'pending_activation']);
    $this->givePaymentMethod($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/proxy-bid", [
            'max_amount' => 2000,
        ]);

    $response->assertStatus(403)
             ->assertJsonPath('code', 'BID_NOT_ALLOWED')
             ->assertJsonPath('reason', 'inactive_account');
});

it('proxy bid by suspended user is blocked with suspended reason', function () {
    [$auction, $lot] = makeBidEligibilityLot();

    $buyer = User::factory()->create(['status' => 'suspended']);
    $this->givePaymentMethod($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$auction->id}/lots/{$lot->id}/proxy-bid", [
            'max_amount' => 2000,
        ]);

    $response->assertStatus(403)
             ->assertJsonPath('code', 'BID_NOT_ALLOWED')
             ->assertJsonPath('reason', 'suspended');
});

// ══ UserResource exposure ═════════════════════════════════════════════════════

it('exposes can_bid=true via /auth/me for a fully eligible user', function () {
    $user = User::factory()->create(['status' => 'active']);
    $this->givePaymentMethod($user);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(200)
             ->assertJsonPath('data.can_bid', true)
             ->assertJsonPath('data.has_valid_payment_method', true)
             ->assertJsonPath('data.bid_ineligibility_reason', null);
});

it('exposes can_bid=false and reason=missing_payment when no card', function () {
    $user = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(200)
             ->assertJsonPath('data.can_bid', false)
             ->assertJsonPath('data.bid_ineligibility_reason', 'missing_payment');
});

it('exposes can_bid=false and reason=suspended for suspended accounts', function () {
    $user = User::factory()->create(['status' => 'suspended']);
    $this->givePaymentMethod($user);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(200)
             ->assertJsonPath('data.can_bid', false)
             ->assertJsonPath('data.bid_ineligibility_reason', 'suspended');
});
