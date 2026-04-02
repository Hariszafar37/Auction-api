<?php

use App\Models\GovProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeInvitedGovUser(string $token = 'test-invite-token-abc123'): array
{
    $user = User::factory()->create([
        'email'        => 'gov@example.gov',
        'account_type' => 'government',
        'status'       => 'pending',
        'password'     => null,
    ]);
    $user->assignRole('buyer');

    $profile = GovProfile::create([
        'user_id'               => $user->id,
        'entity_name'           => 'Department of Transport',
        'entity_subtype'        => 'government',
        'point_of_contact_name' => 'Jane Gov',
        'phone'                 => '555-000-0000',
        'address'               => '1 Gov Plaza',
        'city'                  => 'Annapolis',
        'state'                 => 'MD',
        'zip'                   => '21401',
        'approval_status'       => 'pending',
        'invite_token'          => $token,
        'invite_sent_at'        => now(),
    ]);

    return [$user, $profile];
}

// ── Validate invite token ─────────────────────────────────────────────────────

it('returns email and entity name for a valid invite token', function () {
    [$user] = makeInvitedGovUser('valid-token-xyz');

    $this->getJson('/api/v1/auth/accept-invite?token=valid-token-xyz')
        ->assertOk()
        ->assertJsonPath('data.email', 'gov@example.gov')
        ->assertJsonPath('data.entity_name', 'Department of Transport');
});

it('returns 404 for an invalid invite token', function () {
    $this->getJson('/api/v1/auth/accept-invite?token=nonexistent')
        ->assertStatus(404)
        ->assertJsonPath('code', 'invalid_token');
});

it('returns 422 when token query param is missing', function () {
    $this->getJson('/api/v1/auth/accept-invite')
        ->assertStatus(422)
        ->assertJsonPath('code', 'token_required');
});

// ── Accept invite ─────────────────────────────────────────────────────────────

it('accepts invite, marks email verified, and advances status to pending_password', function () {
    [$user, $profile] = makeInvitedGovUser('accept-token-123');

    $this->postJson('/api/v1/auth/accept-invite', ['token' => 'accept-token-123'])
        ->assertOk()
        ->assertJsonPath('data.email', 'gov@example.gov');

    $user->refresh();
    $profile->refresh();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->status)->toBe('pending_password')
        ->and($profile->invite_accepted_at)->not->toBeNull()
        ->and($profile->invite_token)->toBeNull();
});

it('returns 404 for invalid token on acceptance', function () {
    $this->postJson('/api/v1/auth/accept-invite', ['token' => 'does-not-exist'])
        ->assertStatus(404)
        ->assertJsonPath('code', 'invalid_token');
});

it('returns 422 when token body param is missing', function () {
    $this->postJson('/api/v1/auth/accept-invite', [])
        ->assertStatus(422);
});

it('consumed token cannot be used again after acceptance', function () {
    makeInvitedGovUser('one-time-token');

    // First acceptance succeeds
    $this->postJson('/api/v1/auth/accept-invite', ['token' => 'one-time-token'])->assertOk();

    // Second attempt fails — token was cleared
    $this->postJson('/api/v1/auth/accept-invite', ['token' => 'one-time-token'])
        ->assertStatus(404)
        ->assertJsonPath('code', 'invalid_token');
});

it('after acceptance, set-password works using email from invite', function () {
    [$user] = makeInvitedGovUser('flow-token-abc');

    // Step 1: accept invite
    $this->postJson('/api/v1/auth/accept-invite', ['token' => 'flow-token-abc'])->assertOk();

    // Step 2: set password using existing endpoint
    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => 'gov@example.gov',
        'password'              => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ])->assertOk();

    $user->refresh();
    expect($user->password)->not->toBeNull()
        ->and($user->status)->toBe('pending_activation');
});
