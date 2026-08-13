<?php

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\User;
use App\Support\AuctionTime;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;

/**
 * Timezone correctness across the API boundary.
 *
 * The admin form posts a naive wall clock ("2027-02-11T22:00") plus the zone it
 * was typed in. Those two together identify an instant; neither does alone.
 * These tests assert the pairing actually happens, and keeps happening.
 *
 * Dates are relative so the suite cannot expire. Literal DST assertions live in
 * tests/Unit/AuctionTimeTest.php, where fixed dates make them meaningful.
 */

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function tzAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    return $admin;
}

/** A wall clock safely in the future, as the form would submit it. */
function futureWallClock(int $monthsAhead = 6, int $hour = 22): string
{
    return now()->addMonths($monthsAhead)->setTime($hour, 0)->format('Y-m-d\TH:i');
}

/** Create an auction through the API exactly as the admin form does. */
function postAuction(User $admin, string $wallClock, string $tz, array $extra = [])
{
    return test()->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/auctions', array_merge([
        'title'     => 'Timezone Test Auction',
        'location'  => 'New York, NY',
        'timezone'  => $tz,
        'starts_at' => $wallClock,
    ], $extra));
}

// ── The original bug ──────────────────────────────────────────────────────────

it('does not store the wall clock as though it were already UTC', function () {
    $wallClock = futureWallClock();

    $response = postAuction(tzAdmin(), $wallClock, 'America/New_York')->assertCreated();

    $auction = Auction::find($response->json('data.id'));

    // This is the regression. The old code stored the digits verbatim, so
    // "22:00 Eastern" became 22:00 UTC — four hours early.
    expect($auction->starts_at->toIso8601String())
        ->not->toBe(CarbonImmutable::parse($wallClock, 'UTC')->toIso8601String())
        ->and($auction->starts_at->toIso8601String())
        ->toBe(CarbonImmutable::parse($wallClock, 'America/New_York')->utc()->toIso8601String());
});

it('resolves the same digits to different instants in different zones', function () {
    $wallClock = futureWallClock();

    $eastern = Auction::find(
        postAuction(tzAdmin(), $wallClock, 'America/New_York')->assertCreated()->json('data.id')
    );
    $pacific = Auction::find(
        postAuction(tzAdmin(), $wallClock, 'America/Los_Angeles')->assertCreated()->json('data.id')
    );

    // Three hours apart, whichever side of DST the date falls on.
    expect($pacific->starts_at->timestamp - $eastern->starts_at->timestamp)
        ->toBe(3 * 3600);
});

it('converts the auction-wide closing time in the same zone', function () {
    $starts = futureWallClock(6, 22);
    $closes = futureWallClock(6, 23);

    $response = postAuction(tzAdmin(), $starts, 'America/Denver', [
        'scheduled_end_at' => $closes,
    ])->assertCreated();

    $auction = Auction::find($response->json('data.id'));

    expect($auction->scheduled_end_at->toIso8601String())
        ->toBe(CarbonImmutable::parse($closes, 'America/Denver')->utc()->toIso8601String());
});

// ── Round trip: the form reads values back and posts them again ───────────────

it('does not drift when a returned value is submitted back unchanged', function () {
    $admin = tzAdmin();

    $created = postAuction($admin, futureWallClock(), 'America/New_York')->assertCreated();
    $id      = $created->json('data.id');
    $first   = $created->json('data.starts_at');

    // Two further saves, each echoing back exactly what the API just returned.
    foreach (range(1, 2) as $ignored) {
        $latest = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/auctions/{$id}", [
                'timezone'  => 'America/New_York',
                'starts_at' => $first,
            ])
            ->assertOk()
            ->json('data.starts_at');

        expect($latest)->toBe($first);
    }
});

// ── Changing the zone on its own ──────────────────────────────────────────────

it('keeps the wall clock when only the timezone changes', function () {
    $admin     = tzAdmin();
    $wallClock = futureWallClock();

    $id = postAuction($admin, $wallClock, 'America/New_York')->assertCreated()->json('data.id');

    // The form still displays 22:00, so 22:00 is what the admin means — now in
    // Pacific. The instant moves; the digits do not.
    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/auctions/{$id}", ['timezone' => 'America/Los_Angeles'])
        ->assertOk();

    $auction = Auction::find($id);

    expect($auction->timezone)->toBe('America/Los_Angeles')
        ->and($auction->starts_at->toIso8601String())
        ->toBe(CarbonImmutable::parse($wallClock, 'America/Los_Angeles')->utc()->toIso8601String());
});

it('carries the closing time along when the timezone changes', function () {
    $admin  = tzAdmin();
    $starts = futureWallClock(6, 22);
    $closes = futureWallClock(6, 23);

    $id = postAuction($admin, $starts, 'America/New_York', [
        'scheduled_end_at' => $closes,
    ])->assertCreated()->json('data.id');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/auctions/{$id}", ['timezone' => 'America/Los_Angeles'])
        ->assertOk();

    expect(Auction::find($id)->scheduled_end_at->toIso8601String())
        ->toBe(CarbonImmutable::parse($closes, 'America/Los_Angeles')->utc()->toIso8601String());
});

it('leaves the times alone when the timezone is resubmitted unchanged', function () {
    $admin     = tzAdmin();
    $wallClock = futureWallClock();

    $id     = postAuction($admin, $wallClock, 'America/New_York')->assertCreated()->json('data.id');
    $before = Auction::find($id)->starts_at->toIso8601String();

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/auctions/{$id}", ['timezone' => 'America/New_York', 'title' => 'Renamed'])
        ->assertOk();

    expect(Auction::find($id)->starts_at->toIso8601String())->toBe($before);
});

// ── Per-lot closing times inherit the auction's zone ──────────────────────────

it('converts a lot closing time in the parent auction zone', function () {
    $admin   = tzAdmin();
    $auction = $this->createAuction([
        'status'    => AuctionStatus::Scheduled,
        'timezone'  => 'America/Los_Angeles',
        'starts_at' => now()->addMonths(6),
    ]);

    $closes = futureWallClock(7, 14);

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/auctions/{$auction->id}/lots", [
            'vehicle_id'   => $this->createVehicle(null, ['status' => 'available'])->id,
            'starting_bid' => 500,
            'scheduled_close_at' => $closes,
        ])
        ->assertCreated();

    expect($response->json('data.scheduled_close_at'))
        ->toBe(CarbonImmutable::parse($closes, 'America/Los_Angeles')->utc()->toIso8601String());
});

it('still lets a lot clear its closing time with an explicit null', function () {
    $admin   = tzAdmin();
    $auction = $this->createAuction([
        'status'    => AuctionStatus::Scheduled,
        'timezone'  => 'America/New_York',
        'starts_at' => now()->addMonths(6),
    ]);

    $lotId = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/auctions/{$auction->id}/lots", [
            'vehicle_id'         => $this->createVehicle(null, ['status' => 'available'])->id,
            'starting_bid'       => 500,
            'scheduled_close_at' => futureWallClock(7, 14),
        ])->assertCreated()->json('data.id');

    // Null must survive prepareForValidation() as a present key, or the lot
    // could never fall back to the auction-wide time.
    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/auctions/{$auction->id}/lots/{$lotId}", [
            'scheduled_close_at' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.scheduled_close_at', null);
});

// ── Invalid input still fails as a field error, not a crash ───────────────────

it('rejects an unparseable start time with a 422', function () {
    postAuction(tzAdmin(), 'not-a-date', 'America/New_York')
        ->assertStatus(422)
        ->assertJsonValidationErrors('starts_at');
});

it('rejects an unknown timezone with a 422', function () {
    postAuction(tzAdmin(), futureWallClock(), 'Mars/Olympus_Mons')
        ->assertStatus(422)
        ->assertJsonValidationErrors('timezone');
});

it('still rejects a start time in the past', function () {
    postAuction(tzAdmin(), now()->subMonth()->format('Y-m-d\TH:i'), 'America/New_York')
        ->assertStatus(422)
        ->assertJsonValidationErrors('starts_at');
});

// ── The resource contract ─────────────────────────────────────────────────────

it('returns updated_at so the detail header can render it', function () {
    $id = postAuction(tzAdmin(), futureWallClock(), 'America/New_York')
        ->assertCreated()->json('data.id');

    $response = $this->actingAs(tzAdmin(), 'sanctum')
        ->getJson("/api/v1/admin/auctions/{$id}")
        ->assertOk();

    // Was absent entirely, which rendered as "Last updated Invalid Date".
    expect($response->json('data.updated_at'))->not->toBeNull()
        ->and(CarbonImmutable::parse($response->json('data.updated_at')))
        ->toBeInstanceOf(CarbonImmutable::class);
});

it('serialises times as UTC instants with an explicit offset', function () {
    $response = postAuction(tzAdmin(), futureWallClock(), 'America/New_York')->assertCreated();

    expect($response->json('data.starts_at'))->toEndWith('+00:00');
});

// ── The platform zone is a code constant, not configuration ───────────────────

it('pins the platform timezone in code', function () {
    expect(AuctionTime::PLATFORM_TIMEZONE)->toBe('America/New_York');
});
