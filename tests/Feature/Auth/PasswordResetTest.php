<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
});

function resetPasswordFor(User $user, string $password = 'Secret123!'): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/auth/password/reset', [
        'email'                 => $user->email,
        'token'                 => Password::createToken($user),
        'password'              => $password,
        'password_confirmation' => $password,
    ]);
}

it('lets a reset finish signup for an account stuck without a password', function () {
    // The recovery path for anyone whose verification link was opened by a mail
    // scanner: verified, but never past set-password. The reset used to report
    // success while login kept answering "Please set your password".
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'password'          => null,
        'password_set_at'   => null,
        'status'            => 'pending_password',
    ]);
    $user->assignRole('buyer');

    resetPasswordFor($user)->assertOk();

    $fresh = $user->fresh();
    expect($fresh->status)->toBe('pending_activation')
        ->and($fresh->password_set_at)->not->toBeNull();

    test()->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'Secret123!',
    ])->assertOk()->assertJsonPath('success', true);
});

it('does not let a reset stand in for email verification', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
        'password'          => null,
        'password_set_at'   => null,
        'status'            => 'pending_email_verification',
    ]);
    $user->assignRole('buyer');

    resetPasswordFor($user)->assertOk();

    $fresh = $user->fresh();
    expect($fresh->status)->toBe('pending_email_verification')
        ->and($fresh->password_set_at)->toBeNull();

    test()->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'Secret123!',
    ])->assertStatus(403)->assertJsonPath('code', 'email_not_verified');
});

it('leaves an established account\'s status untouched on reset', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'password_set_at'   => now()->subMonth(),
        'status'            => 'active',
    ]);
    $user->assignRole('buyer');

    resetPasswordFor($user, 'Brandnew123!')->assertOk();

    expect($user->fresh()->status)->toBe('active');

    test()->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'Brandnew123!',
    ])->assertOk();
});
