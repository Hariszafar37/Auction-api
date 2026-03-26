<?php

use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use App\Events\Auction\BidPlaced;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Bid;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Auction\AuctionLotService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeIfSaleAuction(): Auction
{
    $creator = User::factory()->create(['status' => 'active']);

    return Auction::create([
        'title'      => 'If-Sale Test Auction',
        'location'   => 'Houston, TX',
        'starts_at'  => now()->subHour(),
        'status'     => AuctionStatus::Live,
        'created_by' => $creator->id,
    ]);
}

function makeIfSaleVehicle(?User $seller = null): Vehicle
{
    $seller ??= User::factory()->create(['status' => 'active']);

    return Vehicle::create([
        'seller_id' => $seller->id,
        'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'      => 2022,
        'make'      => 'Toyota',
        'model'     => 'Camry',
        'status'    => 'in_auction',
    ]);
}

function makeIfSaleLot(User $winner, array $overrides = []): AuctionLot
{
    return AuctionLot::create(array_merge([
        'auction_id'               => makeIfSaleAuction()->id,
        'vehicle_id'               => makeIfSaleVehicle()->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::IfSale,
        'starting_bid'             => 500,
        'reserve_price'            => 5000,
        'current_bid'              => 1000,
        'current_winner_id'        => $winner->id,
        'bid_count'                => 1,
        'requires_seller_approval' => true,
        'closed_at'                => now(),
        'seller_notified_at'       => now(),
        'seller_decision_deadline' => now()->addHours(48),
    ], $overrides));
}

// ── Service tests ─────────────────────────────────────────────────────────────

test('winner can increase their if_sale bid', function () {
    $winner  = User::factory()->create(['status' => 'active']);
    $lot     = makeIfSaleLot($winner, ['current_bid' => 1000]);
    $service = app(AuctionLotService::class);

    $minNext = $lot->nextMinimumBid(); // must be >= this

    $updatedLot = $service->increaseIfSaleBid($lot, $winner, $minNext);

    expect($updatedLot->current_bid)->toBe($minNext)
        ->and($updatedLot->bid_count)->toBe(2)
        ->and($updatedLot->current_winner_id)->toBe($winner->id)
        ->and($updatedLot->status)->toBe(LotStatus::IfSale) // status unchanged
        ->and($updatedLot->seller_notified_at->greaterThanOrEqualTo(now()->subSecond()))->toBeTrue()
        ->and($updatedLot->seller_decision_deadline->greaterThan(now()->addHours(47)))->toBeTrue();
});

test('winner bid creates a Bid record and deactivates the previous winning bid', function () {
    $winner = User::factory()->create(['status' => 'active']);
    $lot    = makeIfSaleLot($winner, ['current_bid' => 1000]);

    // Seed an existing winning bid
    Bid::create([
        'auction_lot_id' => $lot->id,
        'user_id'        => $winner->id,
        'amount'         => 1000,
        'type'           => 'manual',
        'is_winning'     => true,
        'placed_at'      => now(),
    ]);

    $service = app(AuctionLotService::class);
    $service->increaseIfSaleBid($lot, $winner, $lot->nextMinimumBid());

    // Old bid deactivated
    expect(Bid::where('auction_lot_id', $lot->id)->where('amount', 1000)->first()->is_winning)->toBeFalse();

    // New winning bid exists
    $newBid = Bid::where('auction_lot_id', $lot->id)->where('is_winning', true)->first();
    expect($newBid)->not->toBeNull()
        ->and($newBid->user_id)->toBe($winner->id)
        ->and($newBid->amount)->toBe($lot->fresh()->current_bid);
});

test('BidPlaced event is broadcast after bid increase', function () {
    Event::fake([BidPlaced::class]);

    $winner  = User::factory()->create(['status' => 'active']);
    $lot     = makeIfSaleLot($winner);
    $service = app(AuctionLotService::class);

    $service->increaseIfSaleBid($lot, $winner, $lot->nextMinimumBid());

    Event::assertDispatched(BidPlaced::class);
});

test('non-winner cannot increase the bid', function () {
    $winner   = User::factory()->create(['status' => 'active']);
    $outsider = User::factory()->create(['status' => 'active']);
    $lot      = makeIfSaleLot($winner);
    $service  = app(AuctionLotService::class);

    expect(fn () => $service->increaseIfSaleBid($lot, $outsider, $lot->nextMinimumBid()))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('bid below minimum is rejected', function () {
    $winner  = User::factory()->create(['status' => 'active']);
    $lot     = makeIfSaleLot($winner, ['current_bid' => 1000]);
    $service = app(AuctionLotService::class);

    expect(fn () => $service->increaseIfSaleBid($lot, $winner, 1)) // way below minimum
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('lot not in if_sale status is rejected', function () {
    $winner  = User::factory()->create(['status' => 'active']);
    $lot     = makeIfSaleLot($winner, ['status' => LotStatus::Open]);
    $service = app(AuctionLotService::class);

    expect(fn () => $service->increaseIfSaleBid($lot, $winner, $lot->nextMinimumBid()))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

// ── HTTP endpoint tests ───────────────────────────────────────────────────────

test('POST if-sale/increase-bid returns 200 with updated lot', function () {
    $winner  = User::factory()->create(['status' => 'active']);
    $lot     = makeIfSaleLot($winner);
    $auction = Auction::find($lot->auction_id);

    $response = $this->actingAs($winner)->postJson(
        "/api/v1/auctions/{$auction->id}/lots/{$lot->id}/if-sale/increase-bid",
        ['amount' => $lot->nextMinimumBid()]
    );

    $response->assertOk()
        ->assertJsonPath('data.lot.status', 'if_sale')
        ->assertJsonPath('data.lot.current_bid', $lot->nextMinimumBid())
        ->assertJsonPath('data.lot.is_winner', true);
});

test('non-winner gets 422', function () {
    $winner   = User::factory()->create(['status' => 'active']);
    $outsider = User::factory()->create(['status' => 'active']);
    $lot      = makeIfSaleLot($winner);
    $auction  = Auction::find($lot->auction_id);

    $response = $this->actingAs($outsider)->postJson(
        "/api/v1/auctions/{$auction->id}/lots/{$lot->id}/if-sale/increase-bid",
        ['amount' => $lot->nextMinimumBid()]
    );

    $response->assertStatus(422);
});

test('unauthenticated request gets 401', function () {
    $winner  = User::factory()->create(['status' => 'active']);
    $lot     = makeIfSaleLot($winner);
    $auction = Auction::find($lot->auction_id);

    $this->postJson(
        "/api/v1/auctions/{$auction->id}/lots/{$lot->id}/if-sale/increase-bid",
        ['amount' => $lot->nextMinimumBid()]
    )->assertStatus(401);
});

test('inactive user account gets 403', function () {
    $winner  = User::factory()->create(['status' => 'pending_activation']);
    $lot     = makeIfSaleLot($winner);
    $auction = Auction::find($lot->auction_id);

    $this->actingAs($winner)->postJson(
        "/api/v1/auctions/{$auction->id}/lots/{$lot->id}/if-sale/increase-bid",
        ['amount' => $lot->nextMinimumBid()]
    )->assertStatus(403);
});

test('seller is re-notified by mail after bid increase', function () {
    Mail::fake();

    $seller  = User::factory()->create(['status' => 'active', 'email' => 'seller@example.com']);
    $vehicle = makeIfSaleVehicle($seller);
    $winner  = User::factory()->create(['status' => 'active']);

    $lot = AuctionLot::create([
        'auction_id'               => makeIfSaleAuction()->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::IfSale,
        'starting_bid'             => 500,
        'reserve_price'            => 5000,
        'current_bid'              => 1000,
        'current_winner_id'        => $winner->id,
        'bid_count'                => 1,
        'requires_seller_approval' => true,
        'closed_at'                => now(),
        'seller_notified_at'       => now(),
        'seller_decision_deadline' => now()->addHours(48),
    ]);

    $service = app(AuctionLotService::class);
    $service->increaseIfSaleBid($lot, $winner, $lot->nextMinimumBid());

    Mail::assertQueued(\App\Mail\IfSaleNotificationMail::class, function ($mail) {
        return $mail->hasTo('seller@example.com');
    });
});
