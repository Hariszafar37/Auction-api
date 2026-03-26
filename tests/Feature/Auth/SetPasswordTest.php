<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
});

function makeVerifiedPendingPasswordUser(): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'password'          => null,
        'password_set_at'   => null,
        'status'            => 'pending_password',
    ]);
    $user->assignRole('buyer');
    return $user;
}

it('sets password and advances status to pending_activation', function () {
    $user = makeVerifiedPendingPasswordUser();

    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => $user->email,
        'password'              => 'Secret123!',
        'password_confirmation' => 'Secret123!',
    ])->assertOk()
      ->assertJson(['success' => true]);

    $fresh = $user->fresh();
    expect($fresh->status)->toBe('pending_activation')
        ->and($fresh->password_set_at)->not->toBeNull();
});

it('rejects set-password when email not verified', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
        'status'            => 'pending_email_verification',
        'password'          => null,
    ]);
    $user->assignRole('buyer');

    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => $user->email,
        'password'              => 'Secret123!',
        'password_confirmation' => 'Secret123!',
    ])->assertStatus(422)
      ->assertJsonPath('code', 'email_not_verified');
});

it('rejects set-password when password already set', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'password_set_at'   => now(),
        'status'            => 'pending_activation',
    ]);
    $user->assignRole('buyer');

    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => $user->email,
        'password'              => 'Secret123!',
        'password_confirmation' => 'Secret123!',
    ])->assertStatus(422)
      ->assertJsonPath('code', 'password_already_set');
});

it('rejects weak password without uppercase letter', function () {
    $user = makeVerifiedPendingPasswordUser();

    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => $user->email,
        'password'              => 'secret123!',
        'password_confirmation' => 'secret123!',
    ])->assertStatus(422)
      ->assertJsonPath('errors.password', fn ($v) => count($v) > 0);
});

it('rejects mismatched password confirmation', function () {
    $user = makeVerifiedPendingPasswordUser();

    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => $user->email,
        'password'              => 'Secret123!',
        'password_confirmation' => 'Different99!',
    ])->assertStatus(422)
      ->assertJsonPath('errors.password', fn ($v) => count($v) > 0);
});

it('allows login after setting password', function () {
    $user = makeVerifiedPendingPasswordUser();

    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => $user->email,
        'password'              => 'Secret123!',
        'password_confirmation' => 'Secret123!',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'Secret123!',
    ])->assertOk()
      ->assertJsonPath('data.activation_required', true)
      ->assertJsonStructure(['data' => ['user', 'token']]);
});
