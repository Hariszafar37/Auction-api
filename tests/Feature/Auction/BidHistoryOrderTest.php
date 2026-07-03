<?php

use App\Enums\BidType;
use App\Enums\LotStatus;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\ProxyBid;
use App\Models\User;
use App\Services\Auction\ProxyBidService;
use Database\Seeders\BidIncrementSeeder;
use Illuminate\Support\Carbon;

/*
 * The per-lot bid history is a chronological record ordered by the DB
 * auto-increment id — a single-source, strictly increasing sequence assigned at
 * insert time. It must never fall back to the placed_at wall clock, which at
 * second precision and under clock skew between app workers can stamp bids out
 * of order and make a lower bid appear above a higher one. Because every bid is
 * recorded above the current price, id order is also the ascending-price order,
 * so the current leader always sits on top.
 */

beforeEach(function () {
    $this->seed(BidIncrementSeeder::class);
});

/** Fetch the bid-history endpoint. Returns [['id'=>, 'amount'=>], ...] top row first. */
function historyRows($test, int $auctionId, int $lotId): array
{
    return $test->getJson("/api/v1/auctions/{$auctionId}/lots/{$lotId}/bids")->json('data');
}

it('orders by insertion id, not placed_at, so clock skew cannot put a lower bid above a higher one', function () {
    // Regression for the reported screenshot: a $2,100 rung appeared above the
    // winning $2,300 bid. Faithful clock-skew repro — bids are RECORDED in true
    // ascending order (so ids are monotonic with amount), but the $2,100 rung was
    // written by a worker whose clock ran ~5s fast, giving it a LATER placed_at
    // than the higher $2,300 bid. placed_at ordering would surface $2,100 on top;
    // id ordering keeps the true recording order.
    $winner = User::factory()->create(['status' => 'active']); // #33
    $other  = User::factory()->create(['status' => 'active']); // #10021
    $lot    = $this->createLot(winner: $winner, overrides: [
        'current_bid' => 2300, 'bid_count' => 4, 'status' => LotStatus::Countdown,
    ]);
    $auction = Auction::find($lot->auction_id);

    $t = Carbon::parse('2026-06-23 19:36:00');
    // Insert in TRUE recording order (ids ascend with amount)...
    $b2000 = Bid::create(['auction_lot_id' => $lot->id, 'user_id' => $other->id,  'amount' => 2000, 'type' => BidType::Manual, 'is_winning' => false, 'placed_at' => $t->copy()->addSeconds(4)]);
    $b2100 = Bid::create(['auction_lot_id' => $lot->id, 'user_id' => $other->id,  'amount' => 2100, 'type' => BidType::Proxy,  'is_winning' => false, 'placed_at' => $t->copy()->addSeconds(25)]); // skewed LATE
    $b2200 = Bid::create(['auction_lot_id' => $lot->id, 'user_id' => $winner->id, 'amount' => 2200, 'type' => BidType::Auto,   'is_winning' => false, 'placed_at' => $t->copy()->addSeconds(18)]);
    $b2300 = Bid::create(['auction_lot_id' => $lot->id, 'user_id' => $winner->id, 'amount' => 2300, 'type' => BidType::Manual, 'is_winning' => true,  'placed_at' => $t->copy()->addSeconds(20)]);

    $rows = historyRows($this, $auction->id, $lot->id);
    $ids     = array_column($rows, 'id');
    $amounts = array_column($rows, 'amount');

    // True recording order, newest-first — immune to the skewed placed_at.
    expect($ids)->toBe([$b2300->id, $b2200->id, $b2100->id, $b2000->id]);
    // Emergent clean ladder: strictly descending, winner on top, no lower-above-higher.
    expect($amounts)->toBe([2300, 2200, 2100, 2000]);
    expect($rows[0]['is_winning'])->toBeTrue();
});

it('keeps the winning bid on top when two bids tie on amount (tie-reclaim)', function () {
    // A late manual bid ties an earlier proxy max; the proxy auto-bid reclaims at
    // the same amount. Both rows share the amount — the later-recorded (winning)
    // one has the higher id and must sort on top.
    $winner = User::factory()->create(['status' => 'active']);
    $manual = User::factory()->create(['status' => 'active']);
    $lot    = $this->createLot(winner: $winner, overrides: [
        'current_bid' => 2000, 'bid_count' => 2, 'status' => LotStatus::Countdown,
    ]);
    $auction = Auction::find($lot->auction_id);

    $t = Carbon::parse('2026-06-23 19:36:00');
    $manualBid = Bid::create(['auction_lot_id' => $lot->id, 'user_id' => $manual->id, 'amount' => 2000, 'type' => BidType::Manual, 'is_winning' => false, 'placed_at' => $t->copy()->addSeconds(10)]);
    $reclaim   = Bid::create(['auction_lot_id' => $lot->id, 'user_id' => $winner->id, 'amount' => 2000, 'type' => BidType::Auto,   'is_winning' => true,  'placed_at' => $t->copy()->addSeconds(10)]);

    $rows = historyRows($this, $auction->id, $lot->id);

    expect($rows[0]['id'])->toBe($reclaim->id)
        ->and($rows[0]['is_winning'])->toBeTrue()
        ->and($rows[1]['id'])->toBe($manualBid->id);
});

it('produces a clean chronological ladder for a real proxy duel', function () {
    // Guard against regression: the natural proxy flow must read cleanly, with
    // rows in strict id (recording) order and amounts strictly descending.
    $A = User::factory()->create(['status' => 'active']);
    $B = User::factory()->create(['status' => 'active']);
    $lot = $this->createLot(winner: $A, overrides: [
        'current_bid' => 2000, 'bid_count' => 1, 'status' => LotStatus::Countdown,
    ]);
    $auction = Auction::find($lot->auction_id);
    Bid::create(['auction_lot_id' => $lot->id, 'user_id' => $A->id, 'amount' => 2000, 'type' => BidType::Manual, 'is_winning' => true, 'placed_at' => now()]);
    ProxyBid::create(['auction_lot_id' => $lot->id, 'user_id' => $A->id, 'max_amount' => 2000, 'is_active' => true]);

    $proxy = new ProxyBidService();
    Carbon::setTestNow(now()->addSeconds(14));
    $proxy->setProxyBid($lot->fresh(), $B, 2300); // B wins at 2100
    Carbon::setTestNow(now()->addSeconds(7));
    $proxy->setProxyBid($lot->fresh(), $A, 2200); // A loses -> contested rung, B defends 2300
    Carbon::setTestNow();

    $rows    = historyRows($this, $auction->id, $lot->id);
    $ids     = array_column($rows, 'id');
    $amounts = array_column($rows, 'amount');

    $idsSortedDesc = $ids; rsort($idsSortedDesc);
    expect($ids)->toBe($idsSortedDesc, 'not in id order: ' . json_encode($ids));

    $amtSortedDesc = $amounts; rsort($amtSortedDesc);
    expect($amounts)->toBe($amtSortedDesc, 'duel not descending: ' . json_encode($amounts));
    expect($amounts[0])->toBe(max($amounts)); // leader on top
});
