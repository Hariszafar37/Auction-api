<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * The vehicle condition light system, as it is described to bidders.
 *
 * These are the only approved descriptions, and they must read identically on
 * every surface — the web app, the gate pass PDF and the inventory export.
 * The frontend keeps the matching copy in
 * `src/features/vehicles/constants.ts`; change both together.
 */
final class ConditionLight
{
    public const GREEN  = 'green';
    public const YELLOW = 'yellow';
    public const RED    = 'red';

    /** Display order is always best → worst. */
    public const ALL = [self::GREEN, self::YELLOW, self::RED];

    private const LABELS = [
        self::GREEN  => 'Runs & Drives',
        self::YELLOW => 'Runs but has issues',
        self::RED    => 'Non-Running',
    ];

    private const COLOR_NAMES = [
        self::GREEN  => 'Green',
        self::YELLOW => 'Yellow',
        self::RED    => 'Red',
    ];

    /** Marker for the legacy-value log line, so it can be grepped for directly. */
    public const LEGACY_LOG_MESSAGE = 'condition_light legacy value received';

    /**
     * Accepts the pre-rename `blue` and returns `yellow`.
     *
     * Transitional: a client built against the old enum keeps working while the
     * API and the web app are rolled out separately.
     *
     * Every conversion is logged so the decision to remove this is evidence-based
     * rather than a guess. Before dropping it, check for the marker:
     *
     *     grep "condition_light legacy value received" storage/logs/laravel*.log
     *
     * Silence over a full release cycle means nothing still sends `blue`. The
     * write-side callers (vehicle create/update) can then go; keeping the read
     * side on the public filter is harmless and protects bookmarked links, which
     * outlive any deployment.
     *
     * Logging lives here rather than at the call sites because this is the one
     * choke point every caller passes through — a call site cannot forget it.
     */
    public static function normalize(?string $light): ?string
    {
        if ($light !== 'blue') {
            return $light;
        }

        Log::info(self::LEGACY_LOG_MESSAGE, [
            'received'  => 'blue',
            'stored_as' => self::YELLOW,
            'method'    => request()?->method(),
            'path'      => request()?->path(),
            'user_id'   => auth()->id(),
        ]);

        return self::YELLOW;
    }

    /** "Runs but has issues" — the description on its own. */
    public static function label(?string $light): string
    {
        if ($light === null || $light === '') {
            return '—';
        }

        return self::LABELS[$light] ?? $light;
    }

    /** "Yellow" — the colour name on its own. */
    public static function colorName(?string $light): string
    {
        if ($light === null || $light === '') {
            return '—';
        }

        return self::COLOR_NAMES[$light] ?? ucfirst($light);
    }

    /** "Yellow — Runs but has issues" — for exports and printed documents. */
    public static function describe(?string $light): string
    {
        if ($light === null || $light === '') {
            return '—';
        }

        if (! isset(self::LABELS[$light])) {
            return ucfirst($light);
        }

        return self::COLOR_NAMES[$light] . ' — ' . self::LABELS[$light];
    }
}
