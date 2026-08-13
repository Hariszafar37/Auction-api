<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;

/**
 * Single source of truth for turning admin-entered wall-clock times into UTC
 * instants.
 *
 * The platform stores every datetime as a UTC instant. A `<input
 * type="datetime-local">` produces a *naive* wall clock ("2026-08-11T22:00")
 * that carries no offset, so it means nothing until it is paired with a
 * timezone. That pairing happens here, on the way in, and nowhere else.
 *
 * Two zones exist in this system and they are not interchangeable:
 *
 *   - `auctions.timezone` — governs anything scheduled *for* an auction
 *     (starts_at, scheduled_end_at, lot scheduled_close_at). A California sale
 *     runs on Pacific; the zone is a property of the sale.
 *
 *   - self::PLATFORM_TIMEZONE — governs records with no auction to belong to
 *     (invoices, settlements, audit entries). Colonial keeps its books on
 *     Eastern.
 *
 * Neither is `config('app.timezone')`. That one is the *storage* zone, must
 * stay UTC, and is read only by the framework — never treat it as a business
 * value. Anchoring the platform zone in code rather than .env follows the same
 * reasoning as App\Support\Brand: a mis-set environment variable should not be
 * able to change an outward-facing business fact.
 */
final class AuctionTime
{
    /**
     * The zone Colonial does business in.
     *
     * Deliberately a constant, not config: staging and production are the same
     * auction house in the same state. Keep in sync with PLATFORM_TIMEZONE in
     * the frontend's src/lib/utils/datetime.ts.
     */
    public const PLATFORM_TIMEZONE = 'America/New_York';

    /**
     * Interpret a wall-clock string in $tz and return the equivalent UTC
     * instant as an ISO-8601 string.
     *
     * Offset-bearing input ("2026-08-12T02:00:00Z", "...+05:00") already
     * identifies an instant, so $tz is ignored for it — PHP's DateTime
     * semantics. That makes the conversion safe to re-apply to a value that has
     * already been through it, which matters because the edit form round-trips
     * values back to the API.
     *
     * Both failure modes hand the original value back untouched rather than
     * throwing, because this runs in prepareForValidation() — *before* the
     * `date` and `timezone` rules get a chance to reject the input. Throwing
     * here would turn a user's typo into a 500 instead of a 422.
     */
    public static function toUtc(?string $wallClock, ?string $tz = null): ?string
    {
        if ($wallClock === null || trim($wallClock) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($wallClock, self::zone($tz))
                ->setTimezone('UTC')
                ->toIso8601String();
        } catch (Throwable) {
            return $wallClock;
        }
    }

    /**
     * Re-interpret an already-stored UTC instant as though its wall clock had
     * been typed in a different zone.
     *
     * Used when an admin changes an auction's timezone without retyping the
     * times: "10:00 PM Eastern" becomes "10:00 PM Pacific", so the wall clock
     * is preserved and the instant moves. The alternative — holding the instant
     * and letting the displayed time change — would contradict the form, which
     * still shows the original digits.
     */
    public static function reinterpret(?CarbonInterface $instant, ?string $fromTz, ?string $toTz): ?string
    {
        if ($instant === null) {
            return null;
        }

        // CarbonImmutable::instance() rather than the value as given: Eloquent
        // hands back a *mutable* Carbon, and setTimezone() on it would silently
        // re-zone the model's own attribute as a side effect.
        $wallClock = CarbonImmutable::instance($instant)
            ->setTimezone(self::zone($fromTz))
            ->format('Y-m-d\TH:i:s');

        return self::toUtc($wallClock, $toTz);
    }

    /**
     * Resolve a usable IANA zone, falling back to the platform zone.
     *
     * An unknown zone falls back rather than throwing so the request can reach
     * validation and fail on the `timezone` rule with a proper field error.
     */
    public static function zone(?string $tz): string
    {
        if ($tz === null || trim($tz) === '') {
            return self::PLATFORM_TIMEZONE;
        }

        try {
            new DateTimeZone($tz);

            return $tz;
        } catch (Throwable) {
            return self::PLATFORM_TIMEZONE;
        }
    }
}
