<?php

use App\Support\ConditionLight;
use Illuminate\Support\Facades\Log;

/**
 * The transitional `blue` → `yellow` alias, and the logging that tells us when
 * it is finally safe to remove.
 *
 * These live under Feature rather than Unit because normalize() reaches for the
 * request and the logger, which only exist once the application is booted.
 */

it('accepts the pre-rename blue as yellow so older clients keep working', function () {
    expect(ConditionLight::normalize('blue'))->toBe('yellow');
});

it('leaves the current values untouched', function () {
    expect(ConditionLight::normalize('green'))->toBe('green')
        ->and(ConditionLight::normalize('yellow'))->toBe('yellow')
        ->and(ConditionLight::normalize('red'))->toBe('red')
        ->and(ConditionLight::normalize(null))->toBeNull();
});

it('logs every legacy conversion so the alias can be retired on evidence', function () {
    Log::shouldReceive('info')
        ->once()
        ->with(ConditionLight::LEGACY_LOG_MESSAGE, Mockery::on(
            fn ($ctx) => $ctx['received'] === 'blue' && $ctx['stored_as'] === 'yellow'
        ));

    expect(ConditionLight::normalize('blue'))->toBe('yellow');
});

it('stays quiet for values that need no conversion', function () {
    Log::shouldReceive('info')->never();

    expect(ConditionLight::normalize('yellow'))->toBe('yellow')
        ->and(ConditionLight::normalize('green'))->toBe('green')
        ->and(ConditionLight::normalize(null))->toBeNull();
});

it('records the endpoint that sent the legacy value', function () {
    Log::shouldReceive('info')
        ->once()
        ->with(ConditionLight::LEGACY_LOG_MESSAGE, Mockery::on(
            fn ($ctx) => $ctx['path'] === 'api/v1/vehicles' && $ctx['method'] === 'GET'
        ));

    $this->getJson('/api/v1/vehicles?condition_light=blue')->assertOk();
});
