<?php

use App\Enums\LotStatus;
use App\Models\ProxyBid;
use App\Models\User;
use Database\Seeders\BidIncrementSeeder;

/**
 * Guards the per-lot "your current max bid" feature on the PUBLIC (optional.auth)
 * auction read endpoints.
 *
 * These tests deliberately use a REAL bearer token (withToken) instead of
 * actingAs(). actingAs($user, 'sanctum') sets the default guard via shouldUse(),
 * which masks the production behaviour of the OptionalSanctumAuth middleware.
 * Sending an actual token exercises the middleware exactly as the browser does,
 * so $request->user() in AuctionLotResource must resolve the token user for
 * my_proxy_max / is_winner to be populated.
 */
beforeEach(function () {
    $this->seed(BidIncrementSeeder::class);
});

// NOTE: each scenario is its own test so it gets a fresh application instance.
// A single test method reuses the booted app across requests, which would let
// the sanctum guard's resolved user leak between simulated requests — something
// that never happens in production (one bootstrap per HTTP request).

it('exposes my_proxy_max on the single lot endpoint for the token user', function () {
    $bidder = User::factory()->create(['status' => 'active']);
    $lot    = $this->createLot(overrides: ['status' => LotStatus::Open]);

    ProxyBid::create([
        'auction_lot_id' => $lot->id,
        'user_id'        => $bidder->id,
        'max_amount'     => 15000,
        'is_active'      => true,
    ]);

    $token = $bidder->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson("/api/v1/auctions/{$lot->auction_id}/lots/{$lot->id}")
        ->assertOk()
        ->assertJsonPath('data.my_proxy_max', 15000);
});

it('does not expose my_proxy_max to a guest', function () {
    $bidder = User::factory()->create(['status' => 'active']);
    $lot    = $this->createLot(overrides: ['status' => LotStatus::Open]);

    ProxyBid::create([
        'auction_lot_id' => $lot->id,
        'user_id'        => $bidder->id,
        'max_amount'     => 15000,
        'is_active'      => true,
    ]);

    $this->getJson("/api/v1/auctions/{$lot->auction_id}/lots/{$lot->id}")
        ->assertOk()
        ->assertJsonPath('data.my_proxy_max', null);
});

it('does not expose another user\'s max bid', function () {
    $bidder = User::factory()->create(['status' => 'active']);
    $lot    = $this->createLot(overrides: ['status' => LotStatus::Open]);

    ProxyBid::create([
        'auction_lot_id' => $lot->id,
        'user_id'        => $bidder->id,
        'max_amount'     => 15000,
        'is_active'      => true,
    ]);

    $other      = User::factory()->create(['status' => 'active']);
    $otherToken = $other->createToken('test')->plainTextToken;

    $this->withToken($otherToken)
        ->getJson("/api/v1/auctions/{$lot->auction_id}/lots/{$lot->id}")
        ->assertOk()
        ->assertJsonPath('data.my_proxy_max', null);
});

it('exposes my_proxy_max per-lot on the lot listing endpoint', function () {
    $bidder  = User::factory()->create(['status' => 'active']);
    $auction = $this->createAuction();

    // Lot A — bidder has a max set; Lot B — nothing set.
    $lotA = $this->createLot(overrides: [
        'auction_id' => $auction->id,
        'lot_number' => 1,
        'status'     => LotStatus::Open,
    ]);
    $lotB = $this->createLot(overrides: [
        'auction_id' => $auction->id,
        'lot_number' => 2,
        'status'     => LotStatus::Open,
    ]);

    ProxyBid::create([
        'auction_lot_id' => $lotA->id,
        'user_id'        => $bidder->id,
        'max_amount'     => 8000,
        'is_active'      => true,
    ]);

    $token = $bidder->createToken('test')->plainTextToken;

    $response = $this->withToken($token)
        ->getJson("/api/v1/auctions/{$auction->id}/lots")
        ->assertOk();

    $byId = collect($response->json('data'))->keyBy('id');

    expect($byId[$lotA->id]['my_proxy_max'])->toBe(8000)
        ->and($byId[$lotB->id]['my_proxy_max'])->toBeNull();
});

it('does not expose a cancelled (inactive) proxy as a current max', function () {
    $bidder = User::factory()->create(['status' => 'active']);
    $lot    = $this->createLot(overrides: ['status' => LotStatus::Open]);

    ProxyBid::create([
        'auction_lot_id' => $lot->id,
        'user_id'        => $bidder->id,
        'max_amount'     => 9000,
        'is_active'      => false,
        'cancelled_at'   => now(),
    ]);

    $token = $bidder->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson("/api/v1/auctions/{$lot->auction_id}/lots/{$lot->id}")
        ->assertOk()
        ->assertJsonPath('data.my_proxy_max', null);
});
