<?php

namespace App\Support;

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

    /**
     * Accepts the pre-rename `blue` and returns `yellow`.
     *
     * Transitional: a client built against the old enum keeps working while the
     * API and the web app are rolled out separately. Safe to drop once no
     * deployed client sends `blue`.
     */
    public static function normalize(?string $light): ?string
    {
        return $light === 'blue' ? self::YELLOW : $light;
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
