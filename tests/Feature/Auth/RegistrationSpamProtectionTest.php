<?php

use App\Support\BotSignals;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    RateLimiter::clear('register:ip:127.0.0.1');
});

/**
 * A valid, human-looking registration payload.
 */
function validRegistration(array $overrides = []): array
{
    return array_merge([
        'email'                    => 'human@example.com',
        'email_confirmation'       => 'human@example.com',
        'first_name'               => 'Jane',
        'last_name'                => 'Doe',
        'primary_phone'            => '555-123-4567',
        'agree_terms'              => true,
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ], botGuardFields(), $overrides);
}

// ── Honeypot / form-signal ────────────────────────────────────────────────────

it('accepts a registration that carries the form signal', function () {
    Event::fake([Registered::class]);

    $this->postJson('/api/v1/auth/register', validRegistration())
        ->assertStatus(201);
});

it('rejects a submission that omits the form fields entirely', function () {
    // This is the live attack shape: a script POSTing the API host directly,
    // which never rendered our form and so cannot know these fields exist.
    $payload = validRegistration();
    unset($payload['website'], $payload['form_elapsed_ms']);

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertStatus(422)
        ->assertJsonPath('errors.website', fn ($v) => count($v) > 0)
        ->assertJsonPath('errors.form_elapsed_ms', fn ($v) => count($v) > 0);

    $this->assertDatabaseMissing('users', ['email' => 'human@example.com']);
});

it('rejects a submission that fills the honeypot', function () {
    $this->postJson('/api/v1/auth/register', validRegistration(['website' => 'http://spam.example']))
        ->assertStatus(422)
        ->assertJsonPath('errors.website', fn ($v) => count($v) > 0);
});

it('rejects a submission completed faster than a human could type', function () {
    $this->postJson('/api/v1/auth/register', validRegistration(['form_elapsed_ms' => 120]))
        ->assertStatus(422)
        ->assertJsonPath('errors.form_elapsed_ms', fn ($v) => count($v) > 0);
});

it('rejects a null fill duration', function () {
    // 'present' + 'nullable' would accept an explicit null and skip the min/max
    // checks entirely — a one-field bypass of the whole timing guard.
    $this->postJson('/api/v1/auth/register', validRegistration(['form_elapsed_ms' => null]))
        ->assertStatus(422)
        ->assertJsonPath('errors.form_elapsed_ms', fn ($v) => count($v) > 0);

    $this->assertDatabaseMissing('users', ['email' => 'human@example.com']);
});

it('accepts a null honeypot, which is simply an untouched decoy', function () {
    Event::fake([Registered::class]);

    $this->postJson('/api/v1/auth/register', validRegistration(['website' => null]))
        ->assertStatus(201);
});

it('rejects a stale form beyond the maximum age', function () {
    $tooOld = (config('bot_guard.form_signal.max_form_age_seconds') * 1000) + 1;

    $this->postJson('/api/v1/auth/register', validRegistration(['form_elapsed_ms' => $tooOld]))
        ->assertStatus(422)
        ->assertJsonPath('errors.form_elapsed_ms', fn ($v) => count($v) > 0);
});

it('does not reveal why the form signal failed', function () {
    $payload = validRegistration();
    unset($payload['website']);

    $message = $this->postJson('/api/v1/auth/register', $payload)
        ->json('errors.website.0');

    // The wording must not name the mechanism, or it becomes a tuning signal.
    expect(strtolower($message))
        ->not->toContain('honeypot')
        ->not->toContain('bot');
});

it('falls back to a conventional honeypot when presence is not required', function () {
    config()->set('bot_guard.form_signal.require_presence', false);
    Event::fake([Registered::class]);

    $payload = validRegistration();
    unset($payload['website'], $payload['form_elapsed_ms']);

    // Absent is tolerated during the deploy window …
    $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);

    // … but a filled decoy is still rejected.
    $this->postJson('/api/v1/auth/register', validRegistration([
        'email'              => 'other@example.com',
        'email_confirmation' => 'other@example.com',
        'website'            => 'http://spam.example',
    ]))->assertStatus(422);
});

it('skips every check when the guard is disabled', function () {
    config()->set('bot_guard.enabled', false);
    Event::fake([Registered::class]);

    $payload = validRegistration(['first_name' => 'hKhPmploaIgTZdDuvcXul']);
    unset($payload['website'], $payload['form_elapsed_ms']);

    $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);
});

// ── Machine-generated names ───────────────────────────────────────────────────

it('rejects the machine-generated names used in the live attack', function (string $name) {
    $this->postJson('/api/v1/auth/register', validRegistration(['first_name' => $name]))
        ->assertStatus(422)
        ->assertJsonPath('errors.first_name', fn ($v) => count($v) > 0);
})->with([
    'hKhPmploaIgTZdDuvcXul',
    'rbXOYBmDXngiwKbATIckP',
    'ZzHOtUTpoIbqEacJFISpAF',
    'KLXKnZFgHDKDkETRcdOwvdta',
    'NbFfZFIUAAgPLQnDOAvLzzwe',
    'ykFLXEhmduLBMzMee',
]);

it('rejects a machine-generated surname', function () {
    $this->postJson('/api/v1/auth/register', validRegistration(['last_name' => 'jSkpobTOWeatHUvfBGokz']))
        ->assertStatus(422)
        ->assertJsonPath('errors.last_name', fn ($v) => count($v) > 0);
});

it('lets awkward but genuine names through', function (string $name) {
    expect(BotSignals::looksMachineGenerated($name))->toBeFalse();
})->with([
    'Schwarzenegger', 'Papadopoulos', 'Anastasopoulos', 'Featherstonehaugh',
    'McDonald', 'MacArthur', 'DeAngelo', 'FitzGerald', 'McGillicuddy',
    "O'Brien", "McDonald-O'Brien", 'Jean-Pierre', 'JEAN-PIERRE', 'Mary-Kate',
    'Krzysztof', 'Brzezinski', 'Szczepanski', 'Wojciech', 'Zbigniew',
    'Nguyen', 'Ng', 'Xu', 'Li', 'Muhammad', 'Abdul-Rahman',
    'Bjørn', 'Renée', 'José', 'Müller',
    'Mary Jane Elizabeth', 'Jan van der Berg', 'Hernandez Garcia',
    'VANDERBERG', 'MCDONALD',
    '李', '王小明', 'Иванов', 'محمد',
]);

it('accepts an awkward genuine name end to end', function () {
    Event::fake([Registered::class]);

    $this->postJson('/api/v1/auth/register', validRegistration([
        'first_name' => 'Mary Jane Elizabeth',
        'last_name'  => "McDonald-O'Brien",
    ]))->assertStatus(201);
});

it('only logs machine-generated names in log mode', function () {
    config()->set('bot_guard.name_heuristic', 'log');
    Event::fake([Registered::class]);

    $this->postJson('/api/v1/auth/register', validRegistration(['first_name' => 'hKhPmploaIgTZdDuvcXul']))
        ->assertStatus(201);
});

it('separates attack payloads from real names by a clear margin', function () {
    // Guards the calibration itself: if a future tweak narrows this gap, the
    // threshold is no longer safe to leave at its current value.
    $attack = max(array_map(
        [BotSignals::class, 'nameScore'],
        ['hKhPmploaIgTZdDuvcXul', 'ykFLXEhmduLBMzMee', 'RbGacXUTAxSzIWVGT'],
    ));
    $human = max(array_map(
        [BotSignals::class, 'nameScore'],
        ['Featherstonehaugh', "McDonald-O'Brien", 'Mary Jane Elizabeth', 'Szczepanski'],
    ));

    expect($human)->toBeLessThan(5)
        ->and($attack)->toBeGreaterThanOrEqual(5);
});

// ── Rate limiting ─────────────────────────────────────────────────────────────

it('throttles repeated registrations for one address', function () {
    Event::fake([Registered::class]);

    $attempt = fn () => $this->postJson('/api/v1/auth/register', validRegistration([
        'email'              => 'repeat@example.com',
        'email_confirmation' => 'repeat@example.com',
    ]));

    // Three attempts per hour per email address, whatever the source IP.
    $attempt()->assertStatus(201);  // created
    $attempt()->assertStatus(422);  // duplicate — still consumes an attempt
    $attempt()->assertStatus(422);

    $attempt()->assertStatus(429);  // limit reached
});

it('throttles the mail-sending endpoints per address', function () {
    $attempt = fn () => $this->postJson('/api/v1/auth/password/forgot', ['email' => 'victim@example.com']);

    // Five per hour per address caps how hard one victim can be mailed.
    for ($i = 0; $i < 5; $i++) {
        $attempt()->assertStatus(200);
    }

    $attempt()->assertStatus(429);
});

it('does not let one malformed burst exhaust the limit for everyone', function () {
    // Requests carrying no email must not share a single rate-limit bucket,
    // otherwise a flood of junk locks out legitimate registrations.
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/auth/register', [])->assertStatus(422);
    }

    Event::fake([Registered::class]);
    $this->postJson('/api/v1/auth/register', validRegistration())->assertStatus(201);
});

// ── Account enumeration ───────────────────────────────────────────────────────

it('gives the same answer for known and unknown addresses on resend-verification', function () {
    $known = \App\Models\User::factory()->create([
        'email'             => 'known@example.com',
        'email_verified_at' => null,
        'status'            => 'pending_email_verification',
    ]);

    $a = $this->postJson('/api/v1/auth/resend-verification', ['email' => $known->email]);
    $b = $this->postJson('/api/v1/auth/resend-verification', ['email' => 'nobody@example.com']);

    $a->assertStatus(200);
    $b->assertStatus(200);
    expect($a->json('message'))->toBe($b->json('message'));
});

it('gives the same answer for known and unknown addresses on forgot-password', function () {
    $known = \App\Models\User::factory()->create(['email' => 'known2@example.com']);

    $a = $this->postJson('/api/v1/auth/password/forgot', ['email' => $known->email]);
    $b = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody2@example.com']);

    $a->assertStatus(200);
    $b->assertStatus(200);
    expect($a->json('message'))->toBe($b->json('message'));
});
