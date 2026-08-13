<?php

use App\Enums\AuctionStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * A draft auction is invisible to StartScheduledAuctions, so its start time can
 * quietly slip into the past while the admin is still adding lots. When that
 * happens the auction must stay editable: the edit form re-submits every field
 * on save, so an unconditional `after:now` on starts_at rejected an edit to the
 * *title* because of an untouched time — and the only way to fix the time was
 * through the form it had just blocked.
 *
 * The rule therefore guards a *changed* start time only.
 */
function draftEditAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    return $admin;
}

/**
 * The wall clock the edit form would put in the datetime-local input for a
 * given instant — the auction's own zone, minute precision.
 *
 * Building this in UTC instead is the trap these tests exist to avoid: the
 * request re-interprets whatever digits arrive in the auction's zone, so a UTC
 * wall clock silently lands four hours into the future and every assertion
 * passes for the wrong reason.
 */
function draftWallClock(CarbonInterface $instant, string $tz = 'America/New_York'): string
{
    return $instant->copy()->setTimezone($tz)->format('Y-m-d\TH:i');
}

it('lets an admin edit a draft whose start time has already passed', function () {
    $auction = $this->createAuction([
        'status'    => AuctionStatus::Draft,
        'timezone'  => 'America/New_York',
        'starts_at' => now()->subHours(2),
    ]);

    $this->actingAs(draftEditAdmin(), 'sanctum')
        ->patchJson("/api/v1/admin/auctions/{$auction->id}", [
            'title'     => 'Renamed While Overdue',
            'timezone'  => 'America/New_York',
            'starts_at' => draftWallClock($auction->starts_at),
        ])
        ->assertOk();

    $this->assertDatabaseHas('auctions', [
        'id'    => $auction->id,
        'title' => 'Renamed While Overdue',
    ]);
});

it('still rejects a start time changed to the past', function () {
    $auction = $this->createAuction([
        'status'    => AuctionStatus::Draft,
        'timezone'  => 'America/New_York',
        'starts_at' => now()->addDay(),
    ]);

    $this->actingAs(draftEditAdmin(), 'sanctum')
        ->patchJson("/api/v1/admin/auctions/{$auction->id}", [
            'timezone'  => 'America/New_York',
            'starts_at' => draftWallClock(now()->subHour()),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('starts_at');
});

it('accepts a start time moved further into the future', function () {
    $auction = $this->createAuction([
        'status'    => AuctionStatus::Draft,
        'timezone'  => 'America/New_York',
        'starts_at' => now()->subHours(2),
    ]);

    $newStart = now()->addDays(3)->startOfMinute();

    $this->actingAs(draftEditAdmin(), 'sanctum')
        ->patchJson("/api/v1/admin/auctions/{$auction->id}", [
            'timezone'  => 'America/New_York',
            'starts_at' => draftWallClock($newStart),
        ])
        ->assertOk();

    expect($auction->fresh()->starts_at->equalTo($newStart))->toBeTrue();
});

it('does not treat stored seconds as a changed start time', function () {
    // Seeded with a non-zero second, so the wall clock the form round-trips
    // (minute precision) differs from the stored instant by 30s. That must not
    // read as "changed" and re-arm the after:now rule.
    $auction = $this->createAuction([
        'status'    => AuctionStatus::Draft,
        'timezone'  => 'America/New_York',
        'starts_at' => now()->subHours(2)->startOfMinute()->addSeconds(30),
    ]);

    $this->actingAs(draftEditAdmin(), 'sanctum')
        ->patchJson("/api/v1/admin/auctions/{$auction->id}", [
            'timezone'  => 'America/New_York',
            'starts_at' => draftWallClock($auction->starts_at),
        ])
        ->assertOk();
});

it('leaves a changed start time on a scheduled auction under the same rule', function () {
    $auction = $this->createAuction([
        'status'    => AuctionStatus::Scheduled,
        'timezone'  => 'America/New_York',
        'starts_at' => now()->addDay(),
    ]);

    // Untouched value on a scheduled auction: allowed.
    $this->actingAs(draftEditAdmin(), 'sanctum')
        ->patchJson("/api/v1/admin/auctions/{$auction->id}", [
            'title'     => 'Still Scheduled',
            'timezone'  => 'America/New_York',
            'starts_at' => draftWallClock($auction->starts_at),
        ])
        ->assertOk();

    // Moved into the past: still rejected.
    $this->actingAs(draftEditAdmin(), 'sanctum')
        ->patchJson("/api/v1/admin/auctions/{$auction->id}", [
            'timezone'  => 'America/New_York',
            'starts_at' => draftWallClock(now()->subHour()),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('starts_at');
});
