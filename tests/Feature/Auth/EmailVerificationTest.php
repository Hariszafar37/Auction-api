<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
});

function makeUnverifiedUser(): User
{
    $user = User::factory()->create([
        'email_verified_at' => null,
        'status'            => 'pending_email_verification',
    ]);
    $user->assignRole('buyer');
    return $user;
}

it('verifies email and redirects to frontend set-password page', function () {
    $user = makeUnverifiedUser();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $response = $this->get($url);

    $response->assertRedirect();
    $location = $response->headers->get('location');
    expect($location)->toContain('/set-password')
        ->and($location)->toContain('email_verified=1');

    $this->assertDatabaseHas('users', [
        'id'     => $user->id,
        'status' => 'pending_password',
    ]);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('dispatches Verified event on email verification', function () {
    Event::fake([Verified::class]);

    $user = makeUnverifiedUser();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $this->get($url);

    Event::assertDispatched(Verified::class);
});

it('rejects invalid verification hash', function () {
    $user = makeUnverifiedUser();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => 'bad-hash-value']
    );

    $this->getJson($url)->assertStatus(400)
        ->assertJsonPath('code', 'invalid_link');
});

it('resends verification email by email address', function () {
    $user = makeUnverifiedUser();

    $this->postJson('/api/v1/auth/resend-verification', ['email' => $user->email])
        ->assertOk()
        ->assertJson(['success' => true]);
});

it('does not disclose that an email is already verified', function () {
    // This endpoint used to answer 422 already_verified here and 422 validation
    // for an unknown address, which let anyone enumerate accounts and read off how
    // far through signup each one was. Every outcome now returns the same 200, and
    // no verification mail is sent for an already-verified account.
    Notification::fake();

    $user = User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);
    $user->assignRole('buyer');

    $this->postJson('/api/v1/auth/resend-verification', ['email' => $user->email])
        ->assertOk()
        ->assertJson(['success' => true]);

    Notification::assertNothingSent();
});

it('keeps the verification link usable until a password is set', function () {
    // Mail scanners open links before the recipient does. The second open used to
    // redirect to /set-password?already_verified=1, which the frontend renders as
    // "this link is invalid or has expired" — see the 2026-09-17 client report.
    $user = makeUnverifiedUser();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $this->get($url)->assertRedirect();

    $location = $this->get($url)->headers->get('location');

    expect($location)->toContain('/set-password')
        ->and($location)->toContain('email_verified=1')
        ->and($location)->toContain(urlencode($user->email))
        ->and($location)->not->toContain('already_verified');
});

it('sends an already-activated user to sign in instead of set-password', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'password_set_at'   => now(),
        'status'            => 'pending_activation',
    ]);
    $user->assignRole('buyer');

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    expect($this->get($url)->headers->get('location'))
        ->toContain('/login')
        ->and($this->get($url)->headers->get('location'))->toContain('already_verified=1');
});
