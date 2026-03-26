<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
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

it('returns error when resending to an already verified email', function () {
    $user = User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);
    $user->assignRole('buyer');

    $this->postJson('/api/v1/auth/resend-verification', ['email' => $user->email])
        ->assertStatus(422)
        ->assertJsonPath('code', 'already_verified');
});
