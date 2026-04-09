<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('logs in with valid credentials', function () {
    $user = User::factory()->create([
        'email'    => 'user@example.com',
        'password' => Hash::make('password123'),
        'status'   => 'active',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'user@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => ['user', 'token'],
            'message',
        ])
        ->assertJson(['success' => true]);
});

it('returns token on successful login', function () {
    User::factory()->create([
        'email'    => 'user@example.com',
        'password' => Hash::make('password123'),
        'status'   => 'active',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'user@example.com',
        'password' => 'password123',
    ]);

    expect($response->json('data.token'))->not->toBeNull()->toBeString();
});

it('rejects invalid credentials', function () {
    User::factory()->create([
        'email'    => 'user@example.com',
        'password' => Hash::make('correct-password'),
        'status'   => 'active',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'user@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422);
});

it('rejects suspended accounts', function () {
    User::factory()->create([
        'email'    => 'suspended@example.com',
        'password' => Hash::make('password123'),
        'status'   => 'suspended',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'suspended@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(403)
        ->assertJson(['success' => false, 'code' => 'account_suspended']);
});

it('rejects non-existent email', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'nobody@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(422);
});

it('returns 401 on unauthenticated access to me endpoint', function () {
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertStatus(401)
        ->assertJson(['success' => false, 'code' => 'unauthenticated']);
});

it('returns authenticated user on me endpoint', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('buyer');

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me');

    $response->assertStatus(200)
        ->assertJsonPath('data.email', $user->email);
});

it('logs out and deletes the token from the database', function () {
    $user      = User::factory()->create(['status' => 'active']);
    $newToken  = $user->createToken('test');

    $this->withToken($newToken->plainTextToken)->postJson('/api/v1/auth/logout')
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    // Token row should be removed from DB
    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $newToken->accessToken->id,
    ]);
});

// ── Auth status enforcement ────────────────────────────────────────────────────

it('rejects login for pending_email_verification status', function () {
    $user = User::factory()->create([
        'email'                => 'unverified@example.com',
        'password'             => \Illuminate\Support\Facades\Hash::make('Secret123!'),
        'email_verified_at'    => null,
        'status'               => 'pending_email_verification',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email'    => 'unverified@example.com',
        'password' => 'Secret123!',
    ])->assertStatus(403)
      ->assertJsonPath('code', 'email_not_verified');
});

it('rejects login for pending_password status', function () {
    $user = User::factory()->create([
        'email'             => 'nopw@example.com',
        'email_verified_at' => now(),
        'password'          => \Illuminate\Support\Facades\Hash::make('Secret123!'),
        'password_set_at'   => null,
        'status'            => 'pending_password',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email'    => 'nopw@example.com',
        'password' => 'Secret123!',
    ])->assertStatus(403)
      ->assertJsonPath('code', 'password_not_set');
});

it('login for pending_activation returns activation_required true', function () {
    $user = User::factory()->create([
        'email'             => 'pending@example.com',
        'email_verified_at' => now(),
        'password'          => \Illuminate\Support\Facades\Hash::make('Secret123!'),
        'password_set_at'   => now(),
        'status'            => 'pending_activation',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email'    => 'pending@example.com',
        'password' => 'Secret123!',
    ])->assertStatus(200)
      ->assertJsonPath('data.activation_required', true)
      ->assertJsonPath('data.next_activation_url', '/activation/account-type');
});

// ── next_activation_url routing by activation state ───────────────────────────
//
// Regression guard for the dealer/business pending-approval redirect loop:
// users who completed the wizard and are only awaiting admin approval must
// NOT be sent back to /activation/account-type.

it('individual with incomplete activation is routed to the wizard', function () {
    User::factory()->create([
        'email'                   => 'indiv.incomplete@example.com',
        'email_verified_at'       => now(),
        'password'                => Hash::make('Secret123!'),
        'password_set_at'         => now(),
        'status'                  => 'pending_activation',
        'account_type'            => 'individual',
        'activation_completed_at' => null,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email'    => 'indiv.incomplete@example.com',
        'password' => 'Secret123!',
    ])->assertStatus(200)
      ->assertJsonPath('data.activation_required', true)
      ->assertJsonPath('data.next_activation_url', '/activation/account-type');
});

it('dealer pending admin approval is NOT sent back to the wizard', function () {
    $dealer = User::factory()->create([
        'email'                   => 'dealer.pending@example.com',
        'email_verified_at'       => now(),
        'password'                => Hash::make('Secret123!'),
        'password_set_at'         => now(),
        'status'                  => 'pending_activation',
        'account_type'            => 'dealer',
        'activation_completed_at' => now(),
    ]);
    $dealer->assignRole('dealer');

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'dealer.pending@example.com',
        'password' => 'Secret123!',
    ])->assertStatus(200);

    // activation_required stays true (status !== 'active'), but the URL must
    // land on the pending-approval screen rather than the wizard.
    expect($response->json('data.activation_required'))->toBeTrue();
    expect($response->json('data.next_activation_url'))->toBe('/activation/pending-approval');
    expect($response->json('data.next_activation_url'))->not->toBe('/activation/account-type');
});

it('business pending admin approval is NOT sent back to the wizard', function () {
    User::factory()->create([
        'email'                   => 'biz.pending@example.com',
        'email_verified_at'       => now(),
        'password'                => Hash::make('Secret123!'),
        'password_set_at'         => now(),
        'status'                  => 'pending_activation',
        'account_type'            => 'business',
        'activation_completed_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'biz.pending@example.com',
        'password' => 'Secret123!',
    ])->assertStatus(200);

    expect($response->json('data.activation_required'))->toBeTrue();
    expect($response->json('data.next_activation_url'))->toBe('/activation/pending-approval');
    expect($response->json('data.next_activation_url'))->not->toBe('/activation/account-type');
});

it('government pending admin approval lands on pending-approval page', function () {
    // Government accounts skip the wizard entirely — User::isActivationRequired()
    // returns false for them — so activation_required is false, but they still
    // need the pending-approval URL to surface until an admin approves.
    User::factory()->create([
        'email'                   => 'gov.pending@example.com',
        'email_verified_at'       => now(),
        'password'                => Hash::make('Secret123!'),
        'password_set_at'         => now(),
        'status'                  => 'pending_activation',
        'account_type'            => 'government',
        'activation_completed_at' => null,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'gov.pending@example.com',
        'password' => 'Secret123!',
    ])->assertStatus(200);

    expect($response->json('data.activation_required'))->toBeFalse();
    expect($response->json('data.next_activation_url'))->toBe('/activation/pending-approval');
});

it('active user gets null next_activation_url', function () {
    User::factory()->create([
        'email'             => 'active@example.com',
        'email_verified_at' => now(),
        'password'          => Hash::make('Secret123!'),
        'password_set_at'   => now(),
        'status'            => 'active',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'active@example.com',
        'password' => 'Secret123!',
    ])->assertStatus(200);

    expect($response->json('data.activation_required'))->toBeFalse();
    expect($response->json('data.next_activation_url'))->toBeNull();
});
