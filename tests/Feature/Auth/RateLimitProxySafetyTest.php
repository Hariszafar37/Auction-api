<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
});

/**
 * Behind a proxy that TRUSTED_PROXIES does not cover, Request::ip() reports the
 * proxy's address, so every client in the world shares one rate-limit bucket.
 * A per-IP limit then throttles the entire site instead of one abuser.
 *
 * These tests pin the safety net: when a forwarding header arrives from an
 * untrusted peer, the IP keys are dropped and only the per-email limits — which
 * are immune to proxying — remain in force.
 */

// No type hint on $test: Pest wraps each file in its own generated TestCase
// subclass (P\Tests\...), which is not the Tests\TestCase this file can name.
function loginAttempt($test, string $email, array $headers = [])
{
    return $test->withHeaders($headers)->postJson('/api/v1/auth/login', [
        'email'    => $email,
        'password' => 'wrong-password-on-purpose',
    ]);
}

it('does not throttle the whole site when requests arrive via an untrusted proxy', function () {
    // 40 different people signing in through the same load balancer, which is
    // well past the 30/minute per-IP ceiling. None of them may be locked out.
    for ($i = 0; $i < 40; $i++) {
        $response = loginAttempt($this, "person{$i}@example.com", [
            'X-Forwarded-For' => '203.0.113.' . $i,
        ]);

        expect($response->status())->not->toBe(429);
    }
});

it('still throttles a single account through an untrusted proxy', function () {
    // The per-email limit is keyed on caller-supplied data, so proxying cannot
    // weaken it. 10/minute, then 429 — credential stuffing against one account
    // stays blocked even with the IP keys dropped.
    for ($i = 0; $i < 10; $i++) {
        loginAttempt($this, 'victim@example.com', ['X-Forwarded-For' => '203.0.113.9']);
    }

    loginAttempt($this, 'victim@example.com', ['X-Forwarded-For' => '203.0.113.9'])
        ->assertStatus(429);
});

it('applies per-IP limits normally when there is no proxy', function () {
    // Direct connection — the address is genuine, so the IP ceiling must bite.
    for ($i = 0; $i < 30; $i++) {
        loginAttempt($this, "direct{$i}@example.com");
    }

    loginAttempt($this, 'direct-final@example.com')->assertStatus(429);
});

it('treats the client IP as trustworthy only when no untrusted proxy is in front', function () {
    // Exercised directly rather than through the HTTP stack: the decision is a
    // pure function of the request, and driving it end-to-end would really be
    // testing Symfony's trusted-proxy resolution instead of our branch.
    //
    // The security-relevant direction is the second assertion. If someone later
    // drops the isFromTrustedProxy() check, per-IP limiting would be silently
    // disabled even on a correctly configured server.
    $method = new ReflectionMethod(\App\Providers\AppServiceProvider::class, 'clientIpIsTrustworthy');
    $method->setAccessible(true);

    $request = \Illuminate\Http\Request::create('/', 'POST');
    $request->headers->set('X-Forwarded-For', '198.51.100.7');

    // Untrusted peer in front → the address we see is the proxy's, not the client's.
    expect($method->invoke(null, $request))->toBeFalse();

    // Same request, peer now trusted → ip() resolves the real client, so per-IP
    // limits are meaningful and must be applied.
    \Illuminate\Http\Request::setTrustedProxies(
        ['127.0.0.1'],
        \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR,
    );

    try {
        expect($method->invoke(null, $request))->toBeTrue();
    } finally {
        \Illuminate\Http\Request::setTrustedProxies([], \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR);
    }

    // No forwarding header at all → direct connection, always trustworthy.
    expect($method->invoke(null, \Illuminate\Http\Request::create('/', 'POST')))->toBeTrue();
});

it('keeps registration open for real users behind an untrusted proxy', function () {
    Event::fake([Registered::class]);

    // Far more than the 20/hour per-IP registration ceiling, all through one
    // load balancer. Every one of these is a distinct genuine signup.
    for ($i = 0; $i < 25; $i++) {
        $response = $this->withHeaders(['X-Forwarded-For' => '203.0.113.' . $i])
            ->postJson('/api/v1/auth/register', [
                'email'                    => "new{$i}@example.com",
                'email_confirmation'       => "new{$i}@example.com",
                'first_name'               => 'Jane',
                'last_name'                => 'Doe',
                'primary_phone'            => '555-123-4567',
                'agree_terms'              => true,
                'agree_ecomm_consent'      => true,
                'agree_accuracy_confirmed' => true,
            ] + botGuardFields());

        expect($response->status())->not->toBe(429);
    }

    expect(User::count())->toBe(25);
});

it('still caps repeat registrations for one address behind an untrusted proxy', function () {
    Event::fake([Registered::class]);

    $attempt = fn () => $this->withHeaders(['X-Forwarded-For' => '203.0.113.50'])
        ->postJson('/api/v1/auth/register', [
            'email'                    => 'repeat@example.com',
            'email_confirmation'       => 'repeat@example.com',
            'first_name'               => 'Jane',
            'last_name'                => 'Doe',
            'primary_phone'            => '555-123-4567',
            'agree_terms'              => true,
            'agree_ecomm_consent'      => true,
            'agree_accuracy_confirmed' => true,
        ] + botGuardFields());

    $attempt();
    $attempt();
    $attempt();

    $attempt()->assertStatus(429);
});
