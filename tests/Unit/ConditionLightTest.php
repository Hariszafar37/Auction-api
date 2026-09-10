<?php

use App\Support\ConditionLight;

it('describes each light with the approved wording', function () {
    expect(ConditionLight::label('green'))->toBe('Runs & Drives')
        ->and(ConditionLight::label('yellow'))->toBe('Runs but has issues')
        ->and(ConditionLight::label('red'))->toBe('Non-Running');
});

it('pairs the colour name with the description for printed documents', function () {
    expect(ConditionLight::describe('green'))->toBe('Green — Runs & Drives')
        ->and(ConditionLight::describe('yellow'))->toBe('Yellow — Runs but has issues')
        ->and(ConditionLight::describe('red'))->toBe('Red — Non-Running');
});

it('falls back to a dash when no light is set', function () {
    expect(ConditionLight::label(null))->toBe('—')
        ->and(ConditionLight::describe(null))->toBe('—')
        ->and(ConditionLight::colorName(''))->toBe('—');
});

it('passes through an unrecognised value rather than losing it', function () {
    expect(ConditionLight::label('blue'))->toBe('blue')
        ->and(ConditionLight::describe('blue'))->toBe('Blue');
});

it('lists the lights best to worst', function () {
    expect(ConditionLight::ALL)->toBe(['green', 'yellow', 'red']);
});

it('accepts the pre-rename blue as yellow so older clients keep working', function () {
    expect(ConditionLight::normalize('blue'))->toBe('yellow')
        ->and(ConditionLight::normalize('green'))->toBe('green')
        ->and(ConditionLight::normalize('yellow'))->toBe('yellow')
        ->and(ConditionLight::normalize('red'))->toBe('red')
        ->and(ConditionLight::normalize(null))->toBeNull();
});
