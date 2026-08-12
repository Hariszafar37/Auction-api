<?php

use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use App\Jobs\Auction\EndCompletedAuctions;
use App\Jobs\Auction\ProcessScheduledLotCountdowns;
use App\Jobs\Auction\StartScheduledAuctions;
use App\Models\AuctionLot;
use App\Models\Vehicle;
use App\Models\User;
use App\Services\Auction\AuctionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;

/**
 * Auto-close scheduling.
 *
 * Covers the three transitions that used to need an admin click:
 * opening each lot, starting each countdown, and ending the auction.
 */

// ── Helpers ───────────────────────────────────────────────────────────────────

/** A lot in the given status, wired to the given auction. */
function autoCloseLot(int $auctionId, LotStatus $status, array $overrides = []): AuctionLot
{
    $vehicle = Vehicle::create([
        'seller_id' => User::factory()->create(['status' => 'active'])->id,
        'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'      => 2020,
        'make'      => 'Toyota',
        'model'     => 'Camry',
        'status'    => 'in_auction',
    ]);

    return AuctionLot::create(array_merge([
        'auction_id'               => $auctionId,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => fake()->unique()->numberBetween(1, 100000),
        'status'                   => $status,
        'starting_bid'             => 500,
        'reserve_price'            => null,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ], $overrides));
}

// ── Q1: lots open automatically when the auction goes live ────────────────────

it('opens every pending lot when the scheduler takes an auction live', function () {
    $auction = $this->createAuction([
        'status'    => AuctionStatus::Scheduled,
        'starts_at' => now()->subMinute(),
    ]);

    $lotA = autoCloseLot($auction->id, LotStatus::Pending);
    $lotB = autoCloseLot($auction->id, LotStatus::Pending);

    (new StartScheduledAuctions)->handle(app(AuctionService::class));

    expect($auction->fresh()->status)->toBe(AuctionStatus::Live)
        ->and($lotA->fresh()->status)->toBe(LotStatus::Open)
        ->and($lotB->fresh()->status)->toBe(LotStatus::Open)
        ->and($lotA->fresh()->opened_at)->not->toBeNull();
});

it('opens every pending lot when an admin starts the auction manually', function () {
    $auction = $this->createAuction([
        'status'    => AuctionStatus::Scheduled,
        'starts_at' => now()->subMinute(),
    ]);

    $lot = autoCloseLot($auction->id, LotStatus::Pending);

    app(AuctionService::class)->startAuction($auction);

    expect($lot->fresh()->status)->toBe(LotStatus::Open);
});

// ── Q2: scheduled per-lot closing ─────────────────────────────────────────────

it('starts the countdown on a lot whose own scheduled close time has passed', function () {
    $auction = $this->createAuction(['status' => AuctionStatus::Live]);

    $lot = autoCloseLot($auction->id, LotStatus::Open, [
        'scheduled_close_at' => now()->subMinute(),
    ]);

    (new ProcessScheduledLotCountdowns)->handle(app(\App\Services\Auction\BiddingService::class));

    $fresh = $lot->fresh();

    expect($fresh->status)->toBe(LotStatus::Countdown)
        ->and($fresh->countdown_ends_at)->not->toBeNull()
        ->and($fresh->countdown_ends_at->isFuture())->toBeTrue();
});

it('falls back to the auction-wide closing time when the lot has none of its own', function () {
    $auction = $this->createAuction([
        'status'           => AuctionStatus::Live,
        'scheduled_end_at' => now()->subMinute(),
    ]);

    $lot = autoCloseLot($auction->id, LotStatus::Open);

    (new ProcessScheduledLotCountdowns)->handle(app(\App\Services\Auction\BiddingService::class));

    expect($lot->fresh()->status)->toBe(LotStatus::Countdown);
});

it('lets a per-lot closing time override the auction-wide one', function () {
    // Auction-wide time has passed, but this lot is scheduled later in the ring.
    $auction = $this->createAuction([
        'status'           => AuctionStatus::Live,
        'scheduled_end_at' => now()->subMinute(),
    ]);

    $later = autoCloseLot($auction->id, LotStatus::Open, [
        'scheduled_close_at' => now()->addHour(),
    ]);

    (new ProcessScheduledLotCountdowns)->handle(app(\App\Services\Auction\BiddingService::class));

    expect($later->fresh()->status)->toBe(LotStatus::Open);
});

it('leaves lots with no schedule at either level untouched', function () {
    $auction = $this->createAuction(['status' => AuctionStatus::Live]);

    $lot = autoCloseLot($auction->id, LotStatus::Open);

    (new ProcessScheduledLotCountdowns)->handle(app(\App\Services\Auction\BiddingService::class));

    expect($lot->fresh()->status)->toBe(LotStatus::Open);
});

it('does not start countdowns for auctions that are not live', function () {
    $auction = $this->createAuction([
        'status'           => AuctionStatus::Scheduled,
        'starts_at'        => now()->addDay(),
        'scheduled_end_at' => now()->subMinute(),
    ]);

    $lot = autoCloseLot($auction->id, LotStatus::Open);

    (new ProcessScheduledLotCountdowns)->handle(app(\App\Services\Auction\BiddingService::class));

    expect($lot->fresh()->status)->toBe(LotStatus::Open);
});

// ── Q4: the auction ends itself ───────────────────────────────────────────────

it('ends a live auction once every lot has finished', function () {
    $auction = $this->createAuction(['status' => AuctionStatus::Live]);

    autoCloseLot($auction->id, LotStatus::Sold, ['sold_price' => 1000]);
    autoCloseLot($auction->id, LotStatus::NoSale);

    (new EndCompletedAuctions)->handle(app(AuctionService::class));

    expect($auction->fresh()->status)->toBe(AuctionStatus::Ended);
});

it('keeps the auction live while any lot is still open or counting down', function () {
    $auction = $this->createAuction(['status' => AuctionStatus::Live]);

    autoCloseLot($auction->id, LotStatus::Sold, ['sold_price' => 1000]);
    autoCloseLot($auction->id, LotStatus::Countdown, [
        'countdown_ends_at' => now()->addMinute(),
    ]);

    (new EndCompletedAuctions)->handle(app(AuctionService::class));

    expect($auction->fresh()->status)->toBe(AuctionStatus::Live);
});

it('keeps the auction live while a lot is still pending', function () {
    $auction = $this->createAuction(['status' => AuctionStatus::Live]);

    autoCloseLot($auction->id, LotStatus::Sold, ['sold_price' => 1000]);
    autoCloseLot($auction->id, LotStatus::Pending);

    (new EndCompletedAuctions)->handle(app(AuctionService::class));

    expect($auction->fresh()->status)->toBe(AuctionStatus::Live);
});

it('does not let a lot awaiting a seller decision hold the auction open', function () {
    Mail::fake();

    $auction = $this->createAuction(['status' => AuctionStatus::Live]);
    $winner  = User::factory()->create(['status' => 'active']);

    autoCloseLot($auction->id, LotStatus::IfSale, [
        'reserve_price'            => 5000,
        'current_bid'              => 1000,
        'current_winner_id'        => $winner->id,
        'requires_seller_approval' => true,
        'seller_decision_deadline' => now()->addHours(48),
    ]);

    (new EndCompletedAuctions)->handle(app(AuctionService::class));

    expect($auction->fresh()->status)->toBe(AuctionStatus::Ended);
});

it('stamps ends_at with the real completion time, not the scheduled one', function () {
    $scheduled = now()->subHours(3);

    $auction = $this->createAuction([
        'status'           => AuctionStatus::Live,
        'scheduled_end_at' => $scheduled,
    ]);

    autoCloseLot($auction->id, LotStatus::NoSale);

    (new EndCompletedAuctions)->handle(app(AuctionService::class));

    $fresh = $auction->fresh();

    // ends_at drives settlement release + invoice due dates — it must never be
    // back-dated to the planned closing time.
    expect($fresh->ends_at->diffInMinutes(now(), absolute: true))->toBeLessThan(2)
        ->and($fresh->scheduled_end_at->timestamp)->toBe($scheduled->timestamp);
});

it('ignores auctions that have no lots at all', function () {
    $auction = $this->createAuction(['status' => AuctionStatus::Live]);

    (new EndCompletedAuctions)->handle(app(AuctionService::class));

    expect($auction->fresh()->status)->toBe(AuctionStatus::Live);
});

// ── Q6: the closing time stays editable ───────────────────────────────────────

it('allows the closing time to be moved while the auction is live', function () {
    $auction = $this->createAuction(['status' => AuctionStatus::Live]);

    $newTime = now()->addHours(2);

    $updated = app(AuctionService::class)->updateAuction($auction, [
        'scheduled_end_at' => $newTime->toDateTimeString(),
    ]);

    expect($updated->scheduled_end_at->timestamp)->toBe($newTime->timestamp);
});

it('freezes everything but the closing time once the auction is live', function () {
    $auction = $this->createAuction([
        'status' => AuctionStatus::Live,
        'title'  => 'Original Title',
    ]);

    $updated = app(AuctionService::class)->updateAuction($auction, [
        'title'            => 'Renamed Mid-Sale',
        'scheduled_end_at' => now()->addHour()->toDateTimeString(),
    ]);

    expect($updated->title)->toBe('Original Title')
        ->and($updated->scheduled_end_at)->not->toBeNull();
});

it('rejects a closing time that is not after the auction start', function () {
    $auction = $this->createAuction([
        'status'    => AuctionStatus::Scheduled,
        'starts_at' => now()->addDay(),
    ]);

    app(AuctionService::class)->updateAuction($auction, [
        'scheduled_end_at' => now()->addHour()->toDateTimeString(),
    ]);
})->throws(Illuminate\Validation\ValidationException::class);

it('clears the schedule when the closing time is sent as null', function () {
    $auction = $this->createAuction([
        'status'           => AuctionStatus::Live,
        'scheduled_end_at' => now()->addHour(),
    ]);

    $updated = app(AuctionService::class)->updateAuction($auction, [
        'scheduled_end_at' => null,
    ]);

    expect($updated->scheduled_end_at)->toBeNull();
});

// ── Q5: the manual controls still work ────────────────────────────────────────

it('still lets an admin close a scheduled lot early by hand', function () {
    $auction = $this->createAuction(['status' => AuctionStatus::Live]);
    $winner  = User::factory()->create(['status' => 'active']);

    // Scheduled to close in an hour — the admin closes it now instead.
    $lot = autoCloseLot($auction->id, LotStatus::Open, [
        'scheduled_close_at' => now()->addHour(),
        'current_bid'        => 1000,
        'current_winner_id'  => $winner->id,
    ]);

    app(\App\Services\Auction\AuctionLotService::class)->closeLot($lot);

    expect($lot->fresh()->status)->toBe(LotStatus::Sold);
});

it('keeps a per-lot closing time editable while the lot is open', function () {
    $auction = $this->createAuction(['status' => AuctionStatus::Live]);

    $lot = autoCloseLot($auction->id, LotStatus::Open, [
        'scheduled_close_at' => now()->addHour(),
    ]);

    $newTime = now()->addHours(3);

    $updated = app(AuctionService::class)->updateLot($lot, [
        'scheduled_close_at' => $newTime->toDateTimeString(),
    ]);

    expect($updated->scheduled_close_at->timestamp)->toBe($newTime->timestamp);
});

it('refuses to update a lot that has already closed', function () {
    $auction = $this->createAuction(['status' => AuctionStatus::Live]);

    $lot = autoCloseLot($auction->id, LotStatus::Sold, ['sold_price' => 1000]);

    app(AuctionService::class)->updateLot($lot, [
        'scheduled_close_at' => now()->addHour()->toDateTimeString(),
    ]);
})->throws(Illuminate\Validation\ValidationException::class);

// ── Guard: a per-lot time before the auction start would no-sale a vehicle ────

it('rejects a per-lot closing time that lands before the auction starts', function () {
    $this->seed(RolePermissionSeeder::class);

    $auction = $this->createAuction([
        'status'    => AuctionStatus::Scheduled,
        'starts_at' => now()->addDays(2),
    ]);

    [$vehicle] = $this->createVehicleWithSeller(['status' => 'available']);

    $response = $this->actingAsAdmin()
        ->postJson("/api/v1/admin/auctions/{$auction->id}/lots", [
            'vehicle_id'         => $vehicle->id,
            'starting_bid'       => 500,
            // A full day before the auction even opens.
            'scheduled_close_at' => now()->addDay()->toDateTimeString(),
        ]);

    $this->assertValidationError($response, 'scheduled_close_at');
});

it('accepts a per-lot closing time after the auction starts', function () {
    $this->seed(RolePermissionSeeder::class);

    $auction = $this->createAuction([
        'status'    => AuctionStatus::Scheduled,
        'timezone'  => 'America/New_York',
        'starts_at' => now()->addDays(2),
    ]);

    [$vehicle] = $this->createVehicleWithSeller(['status' => 'available']);

    // The admin form posts a naive wall clock. It means that time *in the
    // auction's zone* — a lot has no zone of its own, it runs inside its
    // auction. See Feature/Auction/AuctionTimezoneTest.php.
    $wallClock = now()->addDays(2)->addHours(3)->format('Y-m-d\TH:i');

    $response = $this->actingAsAdmin()
        ->postJson("/api/v1/admin/auctions/{$auction->id}/lots", [
            'vehicle_id'         => $vehicle->id,
            'starting_bid'       => 500,
            'scheduled_close_at' => $wallClock,
        ]);

    $response->assertStatus(201);

    expect(AuctionLot::latest('id')->first()->scheduled_close_at->timestamp)
        ->toBe(Carbon\CarbonImmutable::parse($wallClock, 'America/New_York')->timestamp);
});
