<?php

use App\Support\AuctionTime;
use Carbon\CarbonImmutable;

/**
 * Wall-clock → UTC conversion.
 *
 * Fixed dates on purpose: these assert literal instants across a DST boundary,
 * which is only meaningful if the dates cannot move. The API-level wiring is
 * covered by Feature/Auction/AuctionTimezoneTest.php with relative dates.
 *
 * US DST in 2026: forward Mar 8, back Nov 1. So August is EDT (UTC−4) and
 * January is EST (UTC−5).
 */

// ── The core conversion ───────────────────────────────────────────────────────

it('interprets a naive wall clock in the given zone', function () {
    expect(AuctionTime::toUtc('2026-08-11T22:00', 'America/New_York'))
        ->toBe('2026-08-12T02:00:00+00:00');
});

it('derives the offset per date rather than using a fixed number', function () {
    // Same digits, same zone, six months apart — EDT is −4, EST is −5. A fix
    // that hardcoded an offset would pass the first and fail this one.
    expect(AuctionTime::toUtc('2026-08-11T22:00', 'America/New_York'))
        ->toBe('2026-08-12T02:00:00+00:00')
        ->and(AuctionTime::toUtc('2026-01-15T22:00', 'America/New_York'))
        ->toBe('2026-01-16T03:00:00+00:00');
});

it('honours a zone other than the platform default', function () {
    expect(AuctionTime::toUtc('2026-08-11T22:00', 'America/Los_Angeles'))
        ->toBe('2026-08-12T05:00:00+00:00');
});

it('falls back to the platform zone when none is given', function () {
    expect(AuctionTime::toUtc('2026-08-11T22:00'))
        ->toBe(AuctionTime::toUtc('2026-08-11T22:00', AuctionTime::PLATFORM_TIMEZONE));
});

// ── Idempotency: the edit form round-trips values back to the API ─────────────

it('leaves an offset-bearing value on its own instant', function () {
    // The string already identifies an instant, so the zone argument must not
    // shift it. This is what makes the conversion safe to re-apply.
    expect(AuctionTime::toUtc('2026-08-12T02:00:00+00:00', 'America/New_York'))
        ->toBe('2026-08-12T02:00:00+00:00')
        ->and(AuctionTime::toUtc('2026-08-12T02:00:00Z', 'America/Los_Angeles'))
        ->toBe('2026-08-12T02:00:00+00:00');
});

it('is stable when applied repeatedly', function () {
    $once   = AuctionTime::toUtc('2026-08-11T22:00', 'America/New_York');
    $twice  = AuctionTime::toUtc($once, 'America/New_York');
    $thrice = AuctionTime::toUtc($twice, 'America/New_York');

    expect($twice)->toBe($once)->and($thrice)->toBe($once);
});

// ── Failure modes must reach validation, not blow up ──────────────────────────

it('returns null for empty input', function () {
    expect(AuctionTime::toUtc(null, 'America/New_York'))->toBeNull()
        ->and(AuctionTime::toUtc('', 'America/New_York'))->toBeNull()
        ->and(AuctionTime::toUtc('   ', 'America/New_York'))->toBeNull();
});

it('hands back an unparseable value untouched so the date rule can reject it', function () {
    // Runs in prepareForValidation(), before the `date` rule. Throwing here
    // would turn a typo into a 500.
    expect(AuctionTime::toUtc('not-a-date', 'America/New_York'))->toBe('not-a-date');
});

it('falls back to the platform zone for an unknown timezone', function () {
    // Lets the request reach validation and fail on the `timezone` rule.
    expect(AuctionTime::zone('Mars/Olympus_Mons'))->toBe(AuctionTime::PLATFORM_TIMEZONE)
        ->and(AuctionTime::zone(null))->toBe(AuctionTime::PLATFORM_TIMEZONE)
        ->and(AuctionTime::zone(''))->toBe(AuctionTime::PLATFORM_TIMEZONE);
});

it('accepts every zone offered in the admin dropdown', function (string $tz) {
    expect(AuctionTime::zone($tz))->toBe($tz);
})->with([
    'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
    'America/Phoenix', 'America/Anchorage', 'Pacific/Honolulu', 'Europe/London',
    'Europe/Paris', 'Australia/Sydney', 'Australia/Melbourne', 'Pacific/Auckland',
]);

// ── Re-interpretation when the zone changes on its own ────────────────────────

it('keeps the wall clock and moves the instant when re-interpreted', function () {
    $stored = CarbonImmutable::parse('2026-08-12T02:00:00+00:00'); // 10 PM EDT

    // "10:00 PM Eastern" restated as "10:00 PM Pacific" — 3 hours later.
    expect(AuctionTime::reinterpret($stored, 'America/New_York', 'America/Los_Angeles'))
        ->toBe('2026-08-12T05:00:00+00:00');
});

it('is a no-op when re-interpreted into the same zone', function () {
    $stored = CarbonImmutable::parse('2026-08-12T02:00:00+00:00');

    expect(AuctionTime::reinterpret($stored, 'America/New_York', 'America/New_York'))
        ->toBe('2026-08-12T02:00:00+00:00');
});

it('does not mutate the instance it is given', function () {
    // Eloquent hands back a *mutable* Carbon; re-zoning it in place would
    // corrupt the model's own attribute.
    $stored = Carbon\Carbon::parse('2026-08-12T02:00:00+00:00');
    $before = $stored->toIso8601String();

    AuctionTime::reinterpret($stored, 'America/New_York', 'Australia/Sydney');

    expect($stored->toIso8601String())->toBe($before);
});

it('returns null when re-interpreting nothing', function () {
    expect(AuctionTime::reinterpret(null, 'America/New_York', 'America/Denver'))->toBeNull();
});
