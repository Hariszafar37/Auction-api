<?php

use App\Enums\LotStatus;
use App\Models\User;
use Database\Seeders\BidIncrementSeeder;
use Database\Seeders\RolePermissionSeeder;

/**
 * Guards the rule that a bidder must never bid against themselves: the current
 * high bidder cannot place another standard (manual) bid — they may only raise
 * their maximum (proxy) bid. See BiddingService::validateBid().
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(BidIncrementSeeder::class);
});

it('blocks the current high bidder from placing another manual bid', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $this->givePaymentMethod($buyer);

    $lot = $this->createLot(overrides: [
        'status'            => LotStatus::Open,
        'starting_bid'      => 1000,
        'current_bid'       => null,
        'current_winner_id' => null,
        'bid_count'         => 0,
    ]);

    // First bid succeeds — buyer becomes the high bidder.
    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$lot->auction_id}/lots/{$lot->id}/bids", ['amount' => 1000])
        ->assertStatus(200);

    // Second manual bid by the same (now leading) buyer is rejected.
    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/auctions/{$lot->auction_id}/lots/{$lot->id}/bids", ['amount' => 1100])
        ->assertStatus(422)
        ->assertJsonPath('code', 'bid_invalid')
        ->assertJsonValidationErrors(['amount']);

    // Price did not move; only the original winning bid exists.
    $lot->refresh();
    expect($lot->current_bid)->toBe(1000)
        ->and($lot->current_winner_id)->toBe($buyer->id)
        ->and($lot->bid_count)->toBe(1);
});

it('still lets a different bidder outbid the current high bidder manually', function () {
    $alice = User::factory()->create(['status' => 'active']);
    $bob   = User::factory()->create(['status' => 'active']);
    $this->givePaymentMethod($alice);
    $this->givePaymentMethod($bob);

    $lot = $this->createLot(overrides: [
        'status'            => LotStatus::Open,
        'starting_bid'      => 1000,
        'current_bid'       => null,
        'current_winner_id' => null,
        'bid_count'         => 0,
    ]);

    $this->actingAs($alice, 'sanctum')
        ->postJson("/api/v1/auctions/{$lot->auction_id}/lots/{$lot->id}/bids", ['amount' => 1000])
        ->assertStatus(200);

    // Bob (not the leader) may outbid normally.
    $this->actingAs($bob, 'sanctum')
        ->postJson("/api/v1/auctions/{$lot->auction_id}/lots/{$lot->id}/bids", ['amount' => 1100])
        ->assertStatus(200);

    $lot->refresh();
    expect($lot->current_winner_id)->toBe($bob->id)
        ->and($lot->current_bid)->toBe(1100);
});
