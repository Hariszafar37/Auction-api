<?php

use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use App\Events\Auction\UserWonLot;
use App\Jobs\Auction\NotifyAuctionWinner;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Auction\AuctionLotService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

// ── Helpers (prefixed to avoid collisions with AuctionLotServiceTest) ─────────

function makeWinAuction(): Auction
{
    $creator = User::factory()->create(['status' => 'active']);

    return Auction::create([
        'title'      => 'Win-Test Auction',
        'location'   => 'Dallas, TX',
        'starts_at'  => now()->subHour(),
        'status'     => AuctionStatus::Live,
        'created_by' => $creator->id,
    ]);
}

function makeWinVehicle(): Vehicle
{
    $seller = User::factory()->create(['status' => 'active']);

    return Vehicle::create([
        'seller_id' => $seller->id,
        'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'      => 2021,
        'make'      => 'Honda',
        'model'     => 'Accord',
        'status'    => 'in_auction',
    ]);
}

/**
 * Build a countdown lot ready for closeLot().
 */
function makeWinLot(User $buyer, array $overrides = []): AuctionLot
{
    return AuctionLot::create(array_merge([
        'auction_id'               => makeWinAuction()->id,
        'vehicle_id'               => makeWinVehicle()->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Countdown,
        'starting_bid'             => 500,
        'reserve_price'            => 500,   // reserve met at 1 000
        'current_bid'              => 1000,
        'current_winner_id'        => $buyer->id,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ], $overrides));
}

// ── Service-layer: broadcast tests ────────────────────────────────────────────

it('broadcasts UserWonLot to buyer private channel when closeLot results in a direct sale', function () {
    Bus::fake();
    Event::fake([UserWonLot::class]);

    $buyer = User::factory()->create(['status' => 'active']);
    $lot   = makeWinLot($buyer);

    $service = new AuctionLotService();
    $result  = $service->closeLot($lot);

    expect($result->status)->toBe(LotStatus::Sold)
        ->and($result->buyer_id)->toBe($buyer->id);

    Event::assertDispatched(
        UserWonLot::class,
        fn ($e) => $e->lot->id === $lot->id && $e->lot->buyer_id === $buyer->id
    );
});

it('broadcasts UserWonLot when approveIfSale is called', function () {
    Bus::fake();
    Event::fake([UserWonLot::class]);

    $buyer = User::factory()->create(['status' => 'active']);
    $lot   = makeWinLot($buyer, [
        'status'                   => LotStatus::IfSale,
        'closed_at'                => now(),
        'seller_decision_deadline' => now()->addHours(48),
        'requires_seller_approval' => true,
    ]);

    $service = new AuctionLotService();
    $result  = $service->approveIfSale($lot);

    expect($result->status)->toBe(LotStatus::Sold);

    Event::assertDispatched(
        UserWonLot::class,
        fn ($e) => $e->lot->id === $lot->id
    );
});

it('does NOT broadcast UserWonLot when reserve is not met and lot goes to reserve_not_met', function () {
    Event::fake([UserWonLot::class]);

    $buyer = User::factory()->create(['status' => 'active']);
    $lot   = makeWinLot($buyer, [
        'reserve_price'            => 5000,  // current_bid (1 000) < reserve (5 000)
        'requires_seller_approval' => false, // goes straight to reserve_not_met
    ]);

    $service = new AuctionLotService();
    $result  = $service->closeLot($lot);

    expect($result->status)->toBe(LotStatus::ReserveNotMet);

    Event::assertNotDispatched(UserWonLot::class);
});

it('does NOT broadcast UserWonLot when rejectIfSale is called', function () {
    Event::fake([UserWonLot::class]);

    $buyer = User::factory()->create(['status' => 'active']);
    $lot   = makeWinLot($buyer, [
        'status'                   => LotStatus::IfSale,
        'closed_at'                => now(),
        'seller_decision_deadline' => now()->addHours(48),
    ]);

    $service = new AuctionLotService();
    $result  = $service->rejectIfSale($lot);

    expect($result->status)->toBe(LotStatus::ReserveNotMet);

    Event::assertNotDispatched(UserWonLot::class);
});

it('UserWonLot broadcastWith returns correct payload shape', function () {
    Bus::fake();

    $buyer = User::factory()->create(['status' => 'active']);
    $lot   = makeWinLot($buyer);

    $service = new AuctionLotService();
    $result  = $service->closeLot($lot);

    $soldLot = $result->load(['vehicle', 'auction']);
    $event   = new UserWonLot($soldLot);
    $payload = $event->broadcastWith();

    expect($payload)
        ->toHaveKey('lot_id', $soldLot->id)
        ->toHaveKey('lot_number', $soldLot->lot_number)
        ->toHaveKey('auction_id', $soldLot->auction_id)
        ->toHaveKey('sold_price', 1000)
        ->toHaveKey('vehicle')
        ->and($payload['vehicle'])
        ->toHaveKey('vin')
        ->toHaveKey('year')
        ->toHaveKey('make');
});

// ── HTTP: GET /my/won ─────────────────────────────────────────────────────────

it('GET /my/won returns only the authenticated user\'s sold lots', function () {
    Bus::fake();

    $buyer = User::factory()->create(['status' => 'active']);
    $other = User::factory()->create(['status' => 'active']);

    // Buyer has one won lot
    $buyerLot = makeWinLot($buyer);
    (new AuctionLotService())->closeLot($buyerLot);

    // Another user has a won lot — must NOT appear
    $otherLot = makeWinLot($other);
    (new AuctionLotService())->closeLot($otherLot);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/my/won');

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($buyerLot->id);
});

it('GET /my/won returns empty array when user has no won lots', function () {
    $buyer = User::factory()->create(['status' => 'active']);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/my/won');

    $response->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonCount(0, 'data');
});

it('GET /my/won requires authentication', function () {
    $this->getJson('/api/v1/my/won')->assertUnauthorized();
});

it('GET /my/won response includes auction, vehicle, and sold_price', function () {
    Bus::fake();

    $buyer = User::factory()->create(['status' => 'active']);
    $lot   = makeWinLot($buyer);
    (new AuctionLotService())->closeLot($lot);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/my/won');

    $response->assertOk();
    $item = $response->json('data.0');

    expect($item)
        ->toHaveKey('sold_price', 1000)
        ->toHaveKey('lot_number')
        ->toHaveKey('auction')
        ->toHaveKey('vehicle')
        ->and($item['auction'])->toHaveKey('title')
        ->and($item['vehicle'])->toHaveKey('vin');
});

it('GET /my/won is paginated', function () {
    Bus::fake();

    $buyer = User::factory()->create(['status' => 'active']);

    // Create 3 won lots
    foreach (range(1, 3) as $i) {
        $lot = AuctionLot::create([
            'auction_id'               => makeWinAuction()->id,
            'vehicle_id'               => makeWinVehicle()->id,
            'lot_number'               => $i,
            'status'                   => LotStatus::Countdown,
            'starting_bid'             => 500,
            'reserve_price'            => 500,
            'current_bid'              => 1000,
            'current_winner_id'        => $buyer->id,
            'countdown_seconds'        => 30,
            'requires_seller_approval' => false,
        ]);
        (new AuctionLotService())->closeLot($lot);
    }

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/my/won?per_page=2');

    $response->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonCount(2, 'data');
});
