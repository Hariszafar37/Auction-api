<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

trait FormatsDates
{
    /**
     * Safely convert a date value to an ISO-8601 string.
     *
     * Handles three cases without throwing:
     *   - Carbon / CarbonInterface  → direct toIso8601String()
     *   - string / numeric          → Carbon::parse() then format
     *   - null                      → null
     *
     * This guards against legacy rows where a datetime column was stored
     * as a plain string before proper model casting was in place.
     */
    protected function safeIso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return Carbon::parse($value)->toIso8601String();
    }
}
