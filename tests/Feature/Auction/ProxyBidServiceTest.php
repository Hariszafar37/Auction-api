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

// ─── Case 2 self-raise guard (Issue #2 — bidding against yourself) ─────────────

it('does not place a duplicate self-bid when the current winner raises their own max above a beaten rival', function () {
    $alice = User::factory()->create(['status' => 'active']);
    $bob   = User::factory()->create(['status' => 'active']);

    // Alice is winning at 3100 (defending against Bob's 3000 max).
    $lot = $this->createLot(winner: $alice, overrides: [
        'current_bid' => 3100,
        'bid_count'   => 2,
        'status'      => LotStatus::Open,
    ]);
    winningBidFor($lot, $alice, 3100);
    ProxyBid::create([
        'auction_lot_id' => $lot->id, 'user_id' => $alice->id, 'max_amount' => 5000, 'is_active' => true,
    ]);
    ProxyBid::create([
        'auction_lot_id' => $lot->id, 'user_id' => $bob->id, 'max_amount' => 3000, 'is_active' => true,
    ]);

    $service = new ProxyBidService();
    $service->setProxyBid($lot, $alice, 8000); // Alice just raises her ceiling

    $lot->refresh();

    // Price must NOT move and no extra bid recorded — Alice already leads and
    // Bob is already beaten. Only her stored max changes.
    expect($lot->current_bid)->toBe(3100)
        ->and($lot->current_winner_id)->toBe($alice->id)
        ->and($lot->bid_count)->toBe(2)
        ->and(Bid::where('auction_lot_id', $lot->id)->count())->toBe(1);

    expect(ProxyBid::where('auction_lot_id', $lot->id)->where('user_id', $alice->id)->first()->max_amount)
        ->toBe(8000);
});

// ─── Visible progression in the bid history (Issue #5) ────────────────────────

it('records a contested rung for the losing proxy so the duel is visible in history', function () {
    $alice = User::factory()->create(['status' => 'active']);
    $bob   = User::factory()->create(['status' => 'active']);

    // Alice winning at 1000 with max 5000.
    $lot = $this->createLot(winner: $alice, overrides: [
        'current_bid' => 1000,
        'bid_count'   => 1,
        'status'      => LotStatus::Open,
    ]);
    winningBidFor($lot, $alice, 1000);
    ProxyBid::create([
        'auction_lot_id' => $lot->id, 'user_id' => $alice->id, 'max_amount' => 5000, 'is_active' => true,
    ]);

    // Bob sets a lower max of 3000 and loses.
    (new ProxyBidService())->setProxyBid($lot, $bob, 3000);

    $lot->refresh();

    // History now shows: Alice 1000 (initial), Bob 3000 (contested — his max),
    // Alice 3100 (winning). Bob's losing rung is visible but Alice's 5000 max
    // stays private.
    $bobRung = Bid::where('auction_lot_id', $lot->id)->where('user_id', $bob->id)->first();
    expect($bobRung)->not->toBeNull()
        ->and($bobRung->amount)->toBe(3000)
        ->and($bobRung->type)->toBe(BidType::Proxy)
        ->and($bobRung->is_winning)->toBeFalse();

    $winning = Bid::where('auction_lot_id', $lot->id)->where('is_winning', true)->first();
    expect($winning->user_id)->toBe($alice->id)
        ->and($winning->amount)->toBe(3100);

    // No Alice bid was ever recorded at her 5000 max.
    expect(Bid::where('auction_lot_id', $lot->id)->where('amount', 5000)->exists())->toBeFalse();
});

// ─── First-max-wins on a manual-bid tie (Issue #8) ────────────────────────────

it('gives an earlier proxy priority when a later manual bid ties its maximum', function () {
    $alice = User::factory()->create(['status' => 'active']);
    $bob   = User::factory()->create(['status' => 'active']);

    // Bob currently leads with a manual bid of 5000; Alice registered a proxy
    // max of exactly 5000 BEFORE Bob's bid landed.
    $lot = $this->createLot(winner: $bob, overrides: [
        'current_bid' => 5000,
        'bid_count'   => 1,
        'status'      => LotStatus::Open,
    ]);
    winningBidFor($lot, $bob, 5000);
    ProxyBid::create([
        'auction_lot_id' => $lot->id, 'user_id' => $alice->id, 'max_amount' => 5000, 'is_active' => true,
    ]);

    (new ProxyBidService())->resolveAfterManualBid($lot->fresh(), $bob);

    $lot->refresh();

    // Tie on maximum → the first-registered proxy (Alice) reclaims the lead at 5000.
    expect($lot->current_winner_id)->toBe($alice->id)
        ->and($lot->current_bid)->toBe(5000);
});

it('does not let a proxy whose max is below the current bid reclaim the lead', function () {
    $alice = User::factory()->create(['status' => 'active']);
    $bob   = User::factory()->create(['status' => 'active']);

    $lot = $this->createLot(winner: $bob, overrides: [
        'current_bid' => 5000,
        'bid_count'   => 1,
        'status'      => LotStatus::Open,
    ]);
    winningBidFor($lot, $bob, 5000);
    ProxyBid::create([
        'auction_lot_id' => $lot->id, 'user_id' => $alice->id, 'max_amount' => 4000, 'is_active' => true,
    ]);

    (new ProxyBidService())->resolveAfterManualBid($lot->fresh(), $bob);

    $lot->refresh();

    // Alice's ceiling is below the current bid, so Bob keeps the lead.
    expect($lot->current_winner_id)->toBe($bob->id)
        ->and($lot->current_bid)->toBe(5000);
});
