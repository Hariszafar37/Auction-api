<?php

use App\Enums\BidType;
use App\Enums\LotStatus;
use App\Models\Bid;
use App\Models\ProxyBid;
use App\Models\User;
use App\Services\Auction\ProxyBidService;
use Database\Seeders\BidIncrementSeeder;

beforeEach(function () {
    $this->seed(BidIncrementSeeder::class);
});

/**
 * Helper: attach a winning Bid row so the lot's current_winner_id is backed by a
 * real Bid (createLot only sets the denormalised columns).
 */
function winningBidFor(\App\Models\AuctionLot $lot, User $user, int $amount): Bid
{
    return Bid::create([
        'auction_lot_id' => $lot->id,
        'user_id'        => $user->id,
        'amount'         => $amount,
        'type'           => BidType::Manual,
        'is_winning'     => true,
        'placed_at'      => now(),
    ]);
}

// ─── Self-outbid prevention (Issue #2) ────────────────────────────────────────

it('does not place a bid against itself when the current high bidder sets a proxy', function () {
    $alice = User::factory()->create(['status' => 'active']);
    $lot   = $this->createLot(winner: $alice, overrides: [
        'current_bid' => 1000,
        'bid_count'   => 1,
    ]);
    winningBidFor($lot, $alice, 1000);

    $service = new ProxyBidService();
    $bid     = $service->setProxyBid($lot, $alice, 5000);

    $lot->refresh();

    // Price must NOT move — Alice is already the high bidder and uncontested.
    expect($lot->current_bid)->toBe(1000)
        ->and($lot->current_winner_id)->toBe($alice->id)
        ->and($lot->bid_count)->toBe(1)               // no extra self-bid recorded
        ->and($bid->user_id)->toBe($alice->id);

    // Only the original winning bid exists for Alice on this lot.
    expect(Bid::where('auction_lot_id', $lot->id)->count())->toBe(1);

    // Her max IS recorded for future defense.
    expect(ProxyBid::where('auction_lot_id', $lot->id)->where('user_id', $alice->id)->first()->max_amount)
        ->toBe(5000);
});

// ─── Competing proxies increment, not jump to max (Issue #2) ──────────────────

it('escalates a competing proxy by one increment above the rival max, not to its own max', function () {
    $alice = User::factory()->create(['status' => 'active']);
    $bob   = User::factory()->create(['status' => 'active']);

    // Alice is the high bidder at 1000 with a proxy max of 5000.
    $lot = $this->createLot(winner: $alice, overrides: [
        'current_bid' => 1000,
        'bid_count'   => 1,
    ]);
    winningBidFor($lot, $alice, 1000);
    ProxyBid::create([
        'auction_lot_id' => $lot->id,
        'user_id'        => $alice->id,
        'max_amount'     => 5000,
        'is_active'      => true,
    ]);

    // Bob sets a lower proxy max of 3000.
    $service = new ProxyBidService();
    $service->setProxyBid($lot, $bob, 3000);

    $lot->refresh();

    // Alice stays winning at 3000 + one increment (100 in the 1000–4999 band) = 3100,
    // NOT her full 5000 maximum.
    expect($lot->current_winner_id)->toBe($alice->id)
        ->and($lot->current_bid)->toBe(3100);
});

// ─── A genuine higher proxy still wins (regression guard) ──────────────────────

it('lets a higher proxy take the lead just above the previous max', function () {
    $alice = User::factory()->create(['status' => 'active']);
    $bob   = User::factory()->create(['status' => 'active']);

    $lot = $this->createLot(winner: $alice, overrides: [
        'current_bid' => 1000,
        'bid_count'   => 1,
        'status'      => LotStatus::Open,
    ]);
    winningBidFor($lot, $alice, 1000);
    ProxyBid::create([
        'auction_lot_id' => $lot->id,
        'user_id'        => $alice->id,
        'max_amount'     => 3000,
        'is_active'      => true,
    ]);

    $service = new ProxyBidService();
    $service->setProxyBid($lot, $bob, 5000);

    $lot->refresh();

    // Bob wins at Alice's max (3000) + one increment (100) = 3100.
    expect($lot->current_winner_id)->toBe($bob->id)
        ->and($lot->current_bid)->toBe(3100);
});
