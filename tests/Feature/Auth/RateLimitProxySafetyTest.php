<?php

use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
});

/**
 * Regression cover for a rate-limit bypass that reached production.
 *
 * An earlier revision dropped the per-IP limits whenever an X-Forwarded-For
 * header arrived from an untrusted peer, meaning to protect against a
 * misconfigured proxy collapsing every caller into one bucket. But that header
 * is supplied by the caller, so the effect was that anyone could send
 * `X-Forwarded-For: anything` and switch per-IP throttling off for themselves.
 *
 * A rate limiter must fail CLOSED. No request header may weaken it.
 */

// No type hint on $test: Pest wraps each file in its own generated TestCase
// subclass (P\Tests\...), which is not the Tests\TestCase this file can name.
function forgotPassword($test, string $email, array $headers = [])
{
    return $test->withHeaders($headers)->postJson('/api/v1/auth/password/forgot', [
        'email' => $email,
    ]);
}

it('still throttles by IP when the caller sends X-Forwarded-For', function () {
    // The exact bypass: exhaust the limit, then try to escape it with a header.
    for ($i = 0; $i < 20; $i++) {
        forgotPassword($this, "burn{$i}@example.com");
    }

    forgotPassword($this, 'blocked@example.com')->assertStatus(429);

    // Same client, now claiming to be someone else. Must still be throttled.
    forgotPassword($this, 'bypass@example.com', ['X-Forwarded-For' => '198.51.100.200'])
        ->assertStatus(429);
});

it('still throttles by IP when the caller sends other forwarding headers', function () {
    for ($i = 0; $i < 20; $i++) {
        forgotPassword($this, "burn2-{$i}@example.com");
    }

    foreach (['X-Real-IP' => '203.0.113.5', 'Forwarded' => 'for=203.0.113.6'] as $header => $value) {
        forgotPassword($this, "hdr-{$header}@example.com", [$header => $value])
            ->assertStatus(429);
    }
});

it('cannot be escaped by varying the forged address every request', function () {
    for ($i = 0; $i < 20; $i++) {
        forgotPassword($this, "burn3-{$i}@example.com");
    }

    // A different forged IP each time would defeat any keying that trusted the
    // header. The real peer address is what counts, so all of these are refused.
    for ($i = 0; $i < 5; $i++) {
        forgotPassword($this, "rotate{$i}@example.com", ['X-Forwarded-For' => "198.51.100.{$i}"])
            ->assertStatus(429);
    }
});

it('applies the per-IP registration limit regardless of forwarding headers', function () {
    for ($i = 0; $i < 20; $i++) {
        $this->withHeaders(['X-Forwarded-For' => '203.0.113.' . $i])
            ->postJson('/api/v1/auth/register', [
                'email'                    => "spam{$i}@example.com",
                'email_confirmation'       => "spam{$i}@example.com",
                'first_name'               => 'Jane',
                'last_name'                => 'Doe',
                'primary_phone'            => '555-123-4567',
                'agree_terms'              => true,
                'agree_ecomm_consent'      => true,
                'agree_accuracy_confirmed' => true,
            ] + botGuardFields());
    }

    $this->withHeaders(['X-Forwarded-For' => '203.0.113.250'])
        ->postJson('/api/v1/auth/register', [
            'email'                    => 'onemore@example.com',
            'email_confirmation'       => 'onemore@example.com',
            'first_name'               => 'Jane',
            'last_name'                => 'Doe',
            'primary_phone'            => '555-123-4567',
            'agree_terms'              => true,
            'agree_ecomm_consent'      => true,
            'agree_accuracy_confirmed' => true,
        ] + botGuardFields())
        ->assertStatus(429);
});

it('keeps the per-email limit independent of the per-IP one', function () {
    // Five sends to one address, then that address is capped — while the IP
    // budget (20) still has room. The two keys must not interfere.
    for ($i = 0; $i < 5; $i++) {
        forgotPassword($this, 'victim@example.com')->assertStatus(200);
    }

    forgotPassword($this, 'victim@example.com')->assertStatus(429);

    // A different address from the same client is still under both ceilings.
    forgotPassword($this, 'someone-else@example.com')->assertStatus(200);
});
