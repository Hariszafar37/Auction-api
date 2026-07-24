<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * /broadcasting/auth authorizes Echo's PRIVATE channel subscriptions
 * (user.{id} — outbid and won events).
 *
 * Regression guard: the endpoint used to be registered by
 * withRouting(channels: ...), which forwards to withBroadcasting() with no
 * attributes and so accepted Broadcast::routes()' default ['middleware' =>
 * ['web']] — the session guard. Because this backend is token-only, every
 * private subscription was rejected with a 403 HTML page even when the caller
 * presented a perfectly valid bearer token.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    // The log broadcaster's auth() is a no-op, so it cannot exercise the
    // authorization path. Force the real broadcaster the app deploys with.
    config([
        'app.key'                                => 'base64:' . base64_encode(random_bytes(32)),
        'broadcasting.default'                   => 'reverb',
        'broadcasting.connections.reverb.key'    => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    // Broadcast::channel() proxies through __call to driver(), so channel
    // callbacks are bound to whichever broadcaster was default when
    // routes/channels.php ran at boot — the log driver under phpunit.xml.
    // Switching the default above resolves a *fresh* reverb broadcaster with no
    // channels registered, which would fail authorization for reasons that have
    // nothing to do with the guard. Re-run the channel definitions so they land
    // on the driver actually under test.
    require base_path('routes/channels.php');
});

function activeBuyer(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('buyer');

    return $user;
}

function authorizePrivateChannel(User $subscriber, string $plainTextToken, ?int $channelUserId = null)
{
    return test()->withToken($plainTextToken)->postJson('/broadcasting/auth', [
        'socket_id'    => '1234.5678',
        'channel_name' => 'private-user.' . ($channelUserId ?? $subscriber->id),
    ]);
}

it('authorizes a private user channel for a valid bearer token', function () {
    $user  = activeBuyer();
    $token = $user->createToken('api', ['*'], now()->addMinutes(180));

    $response = authorizePrivateChannel($user, $token->plainTextToken);

    $response->assertStatus(200);

    // Reverb/Pusher hand back a signed auth payload the client echoes back to
    // the websocket server. Its presence is what proves authorization succeeded.
    expect($response->json('auth'))->toBeString()->not->toBeEmpty();
});

it('rejects a private channel belonging to a different user', function () {
    $user    = activeBuyer();
    $someone = activeBuyer();
    $token   = $user->createToken('api', ['*'], now()->addMinutes(180));

    // The channel callback in routes/channels.php compares ids, so this must
    // fail authorization even though the token itself is valid.
    authorizePrivateChannel($user, $token->plainTextToken, $someone->id)
        ->assertStatus(403);
});

it('returns 401 JSON — not 403 HTML — when the token has expired', function () {
    $user  = activeBuyer();
    $token = $user->createToken('api', ['*'], now()->subMinute());

    $response = authorizePrivateChannel($user, $token->plainTextToken);

    $response->assertStatus(401)->assertJson(['code' => 'unauthenticated']);
});

it('returns 401 for a request with no token at all', function () {
    $user = activeBuyer();

    test()->postJson('/broadcasting/auth', [
        'socket_id'    => '1234.5678',
        'channel_name' => "private-user.{$user->id}",
    ])->assertStatus(401);
});

it('slides the token expiry when a channel subscription is authorized', function () {
    // A user sitting on an auction page subscribing to their private channel is
    // active, and must be treated as such by the idle timeout.
    $user  = activeBuyer();
    $token = $user->createToken('api', ['*'], now()->addMinutes(10));

    authorizePrivateChannel($user, $token->plainTextToken)->assertStatus(200);

    expect(now()->diffInMinutes($token->accessToken->fresh()->expires_at))
        ->toBeGreaterThan(179);
});
