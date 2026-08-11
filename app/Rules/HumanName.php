<?php

namespace App\Rules;

use App\Support\BotSignals;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

/**
 * Rejects name fields that look machine-generated.
 *
 * Honours config('bot_guard.name_heuristic'): 'reject' fails validation, 'log'
 * records the hit and lets the request continue, 'off' short-circuits.
 *
 * Every hit is logged either way. If a real customer is ever turned away, the
 * log line carries the exact value and score needed to retune the threshold.
 */
class HumanName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! config('bot_guard.enabled', true)) {
            return;
        }

        $mode = config('bot_guard.name_heuristic', 'reject');

        if ($mode === 'off' || ! is_string($value) || $value === '') {
            return;
        }

        if (! BotSignals::looksMachineGenerated($value)) {
            return;
        }

        Log::warning('bot_guard: machine-generated name detected', [
            'attribute' => $attribute,
            'value'     => $value,
            'score'     => BotSignals::nameScore($value),
            'mode'      => $mode,
            'ip'        => request()->ip(),
        ]);

        if ($mode !== 'reject') {
            return;
        }

        $fail('Please enter your real :attribute as it appears on your government-issued ID.');
    }
}
