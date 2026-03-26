<?php

use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use App\Jobs\Auction\NotifyAuctionWinner;
use App\Mail\AuctionWonMail;
use App\Mail\IfSaleNotificationMail;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Auction\AuctionLotService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Create a live test auction owned by a throw-away user.
 */
function makeTestAuction(): Auction
{
    $creator = User::factory()->create(['status' => 'active']);

    return Auction::create([
        'title'      => 'Test Auction',
        'location'   => 'New York, NY',
        'starts_at'  => now()->subHour(),
        'status'     => AuctionStatus::Live,
        'created_by' => $creator->id,
    ]);
}

/**
 * Create a vehicle with its seller user.
 * Returns [$vehicle, $seller].
 */
function makeTestVehicleWithSeller(): array
{
    $seller  = User::factory()->create(['status' => 'active']);
    $vehicle = Vehicle::create([
        'seller_id' => $seller->id,
        'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'      => 2020,
        'make'      => 'Toyota',
        'model'     => 'Camry',
        'status'    => 'in_auction',
    ]);

    return [$vehicle, $seller];
}

/**
 * Create a ready-to-close countdown lot.
 * Defaults: no reserve, $1 000 current bid, no seller approval required.
 * Pass $overrides to customise any field.
 */
function makeTestLot(array $overrides = []): AuctionLot
{
    $auction   = makeTestAuction();
    [$vehicle] = makeTestVehicleWithSeller();
    $buyer     = User::factory()->create(['status' => 'active']);

    return AuctionLot::create(array_merge([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Countdown,
        'starting_bid'             => 500,
        'reserve_price'            => null,
        'current_bid'              => 1000,
        'current_winner_id'        => $buyer->id,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ], $overrides));
}

// ── Tests ─────────────────────────────────────────────────────────────────────

/**
 * 1. confirmSale path: closeLot with reserve met → status=Sold, job dispatched.
 */
it('closeLot marks lot as sold when reserve is met and dispatches NotifyAuctionWinner', function () {
    Bus::fake();

    $lot = makeTestLot([
        'reserve_price'            => 500,   // current_bid (1 000) exceeds reserve (500)
        'requires_seller_approval' => false,
    ]);

    $service = new AuctionLotService();
    $result  = $service->closeLot($lot);

    expect($result->status)->toBe(LotStatus::Sold)
        ->and($result->sold_price)->toBe(1000)
        ->and($result->buyer_id)->toBe($lot->current_winner_id);

    Bus::assertDispatched(
        NotifyAuctionWinner::class,
        fn ($job) => $job->lot->id === $lot->id
    );
});

/**
 * 2. triggerIfSale path: closeLot with reserve not met + approval required
 *    → status=IfSale, IfSaleNotificationMail queued to seller.
 */
it('closeLot triggers if_sale and queues IfSaleNotificationMail to seller when reserve not met', function () {
    Mail::fake();

    $auction          = makeTestAuction();
    [$vehicle, $seller] = makeTestVehicleWithSeller();
    $buyer            = User::factory()->create(['status' => 'active']);

    $lot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Countdown,
        'starting_bid'             => 500,
        'reserve_price'            => 5000,   // current_bid (1 000) is below reserve (5 000)
        'current_bid'              => 1000,
        'current_winner_id'        => $buyer->id,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => true,
    ]);

    $service = new AuctionLotService();
    $result  = $service->closeLot($lot);

    expect($result->status)->toBe(LotStatus::IfSale)
        ->and($result->seller_decision_deadline)->not->toBeNull();

    Mail::assertQueued(
        IfSaleNotificationMail::class,
        fn ($mail) => $mail->hasTo($seller->email)
    );
});

/**
 * 3. approveIfSale: if_sale → sold + winner job dispatched.
 */
it('approveIfSale transitions lot from if_sale to sold and dispatches NotifyAuctionWinner', function () {
    Bus::fake();

    $lot = makeTestLot([
        'status'    => LotStatus::IfSale,
        'closed_at' => now(),
    ]);

    $service = new AuctionLotService();
    $result  = $service->approveIfSale($lot);

    expect($result->status)->toBe(LotStatus::Sold)
        ->and($result->sold_price)->toBe(1000)
        ->and($result->buyer_id)->toBe($lot->current_winner_id);

    Bus::assertDispatched(NotifyAuctionWinner::class);
});

/**
 * 4. rejectIfSale: if_sale → reserve_not_met, no email of any kind.
 */
it('rejectIfSale transitions lot to reserve_not_met and sends no email', function () {
    Mail::fake();

    $lot = makeTestLot([
        'status'    => LotStatus::IfSale,
        'closed_at' => now(),
    ]);

    $service = new AuctionLotService();
    $result  = $service->rejectIfSale($lot);

    expect($result->status)->toBe(LotStatus::ReserveNotMet);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

/**
 * 5. expireIfSale: auto-reject via reject flow → reserve_not_met.
 */
it('expireIfSale auto-rejects an if_sale lot past its deadline', function () {
    $lot = makeTestLot([
        'status'                   => LotStatus::IfSale,
        'closed_at'                => now()->subHours(49),
        'seller_decision_deadline' => now()->subMinutes(5),
    ]);

    $service = new AuctionLotService();
    $result  = $service->expireIfSale($lot);

    expect($result->status)->toBe(LotStatus::ReserveNotMet);
});

/**
 * 6. Idempotency: closeLot on an already-sold lot returns early without re-dispatching.
 */
it('closeLot is idempotent for already-terminal lots', function () {
    Bus::fake();

    $lot = makeTestLot([
        'status'     => LotStatus::Sold,
        'sold_price' => 1000,
        'buyer_id'   => User::factory()->create(['status' => 'active'])->id,
        'closed_at'  => now()->subMinutes(5),
    ]);

    $service = new AuctionLotService();
    $result  = $service->closeLot($lot);

    expect($result->status)->toBe(LotStatus::Sold);

    Bus::assertNotDispatched(NotifyAuctionWinner::class);
});

/**
 * 7. NotifyAuctionWinner job sends AuctionWonMail to the winning buyer's email.
 */
it('NotifyAuctionWinner job sends AuctionWonMail to the winning buyer', function () {
    Mail::fake();

    $buyer = User::factory()->create(['status' => 'active', 'name' => 'Test Buyer']);
    $lot   = makeTestLot([
        'status'     => LotStatus::Sold,
        'sold_price' => 1000,
        'buyer_id'   => $buyer->id,
        'closed_at'  => now(),
    ]);

    $job = new NotifyAuctionWinner($lot);
    $job->handle();

    Mail::assertSent(
        AuctionWonMail::class,
        fn ($mail) => $mail->hasTo($buyer->email)
    );
});

/**
 * 8. No duplicate sold transition: second closeLot call on an already-sold lot
 *    must not dispatch NotifyAuctionWinner a second time.
 */
it('does not dispatch NotifyAuctionWinner twice on repeated closeLot calls', function () {
    Bus::fake();

    $lot = makeTestLot([
        'reserve_price'            => null,
        'requires_seller_approval' => false,
    ]);

    $service = new AuctionLotService();

    // First call: countdown → sold
    $service->closeLot($lot);
    Bus::assertDispatchedTimes(NotifyAuctionWinner::class, 1);

    // Second call: lot is now terminal (sold) → no-op
    $service->closeLot($lot->fresh());
    Bus::assertDispatchedTimes(NotifyAuctionWinner::class, 1);
});
