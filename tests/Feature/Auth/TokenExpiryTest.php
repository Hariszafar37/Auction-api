<?php

use App\Http\Middleware\SlidingTokenExpiration;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * Logs a user in through the real endpoint and returns the decoded response.
 */
function loginAs(User $user, string $password = 'Secret123!'): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => $password,
    ]);
}

/**
 * Minutes from now until $moment, as a positive float.
 *
 * Carbon 3's diffInMinutes is signed and non-absolute, so calling it on the
 * future timestamp ($expiresAt->diffInMinutes(now())) yields a negative value.
 * Always diff forward from now.
 */
function minutesUntil(\Illuminate\Support\Carbon $moment): float
{
    return now()->diffInMinutes($moment);
}

function activeUserWithRole(string $role): User
{
    $user = User::factory()->create([
        'password'          => Hash::make('Secret123!'),
        'email_verified_at' => now(),
        'password_set_at'   => now(),
        'status'            => 'active',
    ]);

    $user->assignRole($role);

    return $user;
}

// ── Per-role expiry on issue ──────────────────────────────────────────────────

it('issues admin tokens with a 60 minute expiry', function () {
    $admin = activeUserWithRole('admin');

    loginAs($admin)->assertStatus(200);

    $token = $admin->tokens()->latest('id')->first();

    expect($token->expires_at)->not->toBeNull();
    expect(minutesUntil($token->expires_at))->toBeGreaterThan(59)->toBeLessThanOrEqual(60);
});

it('issues staff tokens with the privileged 60 minute expiry', function () {
    $staff = activeUserWithRole('staff');

    loginAs($staff)->assertStatus(200);

    $token = $staff->tokens()->latest('id')->first();

    expect(minutesUntil($token->expires_at))->toBeGreaterThan(59)->toBeLessThanOrEqual(60);
});

it('issues buyer tokens with the standard 180 minute expiry', function () {
    $buyer = activeUserWithRole('buyer');

    loginAs($buyer)->assertStatus(200);

    $token = $buyer->tokens()->latest('id')->first();

    expect(minutesUntil($token->expires_at))->toBeGreaterThan(179)->toBeLessThanOrEqual(180);
});

it('gives a dealer the standard window, not the privileged one', function () {
    $dealer = activeUserWithRole('dealer');

    loginAs($dealer)->assertStatus(200);

    expect(minutesUntil($dealer->tokens()->latest('id')->first()->expires_at))
        ->toBeGreaterThan(60);
});

it('returns the expiry to the client in the login payload', function () {
    $admin = activeUserWithRole('admin');

    $response = loginAs($admin)->assertStatus(200);

    expect($response->json('data.token_expires_in'))->toBe(60 * 60);
    expect($response->json('data.token_expires_at'))->not->toBeNull();
});

// ── Enforcement ───────────────────────────────────────────────────────────────

it('rejects a token whose expires_at has passed', function () {
    $user     = activeUserWithRole('buyer');
    $newToken = $user->createToken('api', ['*'], now()->subMinute());

    $this->withToken($newToken->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJson(['code' => 'unauthenticated']);
});

it('accepts a token that has not yet expired', function () {
    $user     = activeUserWithRole('buyer');
    $newToken = $user->createToken('api', ['*'], now()->addMinutes(5));

    $this->withToken($newToken->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(200);
});

// ── Sliding renewal ───────────────────────────────────────────────────────────

it('pushes expires_at forward on an authenticated request', function () {
    $user     = activeUserWithRole('buyer');
    $newToken = $user->createToken('api', ['*'], now()->addMinutes(10));
    $original = $newToken->accessToken->expires_at;

    $this->withToken($newToken->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(200);

    $refreshed = $newToken->accessToken->fresh()->expires_at;

    // 10 minutes remaining → renewed to the full 180 minute standard window.
    expect($refreshed->gt($original))->toBeTrue();
    expect(minutesUntil($refreshed))->toBeGreaterThan(179);
});

it('renews an admin session to 60 minutes, not 180', function () {
    $admin    = activeUserWithRole('admin');
    $newToken = $admin->createToken('api', ['*'], now()->addMinutes(5));

    $this->withToken($newToken->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(200);

    expect(minutesUntil($newToken->accessToken->fresh()->expires_at))
        ->toBeGreaterThan(59)
        ->toBeLessThanOrEqual(60);
});

it('throttles the slide so a burst of requests does not rewrite expires_at', function () {
    $user     = activeUserWithRole('buyer');
    $newToken = $user->createToken('api', ['*'], now()->addMinutes(180));

    $this->withToken($newToken->plainTextToken)->getJson('/api/v1/auth/me')->assertStatus(200);
    $afterFirst = $newToken->accessToken->fresh()->expires_at;

    $this->withToken($newToken->plainTextToken)->getJson('/api/v1/auth/me')->assertStatus(200);
    $afterSecond = $newToken->accessToken->fresh()->expires_at;

    // Second request lands well inside the 60s throttle window, so the stored
    // expiry must be untouched.
    expect($afterSecond->equalTo($afterFirst))->toBeTrue();
});

it('exposes the refreshed expiry as a response header', function () {
    $user     = activeUserWithRole('buyer');
    $newToken = $user->createToken('api', ['*'], now()->addMinutes(10));

    $response = $this->withToken($newToken->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(200);

    $header = $response->headers->get(SlidingTokenExpiration::HEADER);

    expect($header)->not->toBeNull();
    expect(\Illuminate\Support\Carbon::parse($header)->isFuture())->toBeTrue();
});

it('does not resurrect a token that logout just deleted', function () {
    // Regression guard: the sliding middleware runs in the after phase, by which
    // point /logout has already deleted the token. Saving that in-memory model
    // would INSERT the row straight back and leave the user still signed in.
    $user     = activeUserWithRole('buyer');
    $newToken = $user->createToken('api', ['*'], now()->addMinutes(180));

    $this->withToken($newToken->plainTextToken)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(200);

    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $newToken->accessToken->id,
    ]);
});

it('does not set the expiry header on unauthenticated responses', function () {
    $response = $this->getJson('/api/v1/auth/me')->assertStatus(401);

    expect($response->headers->get(SlidingTokenExpiration::HEADER))->toBeNull();
});
