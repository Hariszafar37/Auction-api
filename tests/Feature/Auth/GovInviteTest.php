<?php

use App\Models\GovProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────────

// Mirrors AdminGovController::store(): the account is created with no password
// and, crucially, no `email_verified_at`. UserFactory's default state fills that
// column in, which previously hid a mass-assignment bug in acceptInvite() behind
// a green suite — `unverified()` keeps the fixture honest to production.
function makeInvitedGovUser(string $token = 'test-invite-token-abc123'): array
{
    $user = User::factory()->unverified()->create([
        'email'        => 'gov@example.gov',
        'account_type' => 'government',
        'status'       => 'pending_email_verification',
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

it('returns 422 when compliance checkboxes are missing on acceptance', function () {
    makeInvitedGovUser('compliance-missing-token');

    $this->postJson('/api/v1/auth/accept-invite', ['token' => 'compliance-missing-token'])
        ->assertStatus(422);
});

it('returns 422 when compliance checkboxes are false on acceptance', function () {
    makeInvitedGovUser('compliance-false-token');

    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                    => 'compliance-false-token',
        'agree_ecomm_consent'      => false,
        'agree_accuracy_confirmed' => false,
    ])->assertStatus(422);
});

it('accepts invite, marks email verified, and advances status to pending_password', function () {
    [$user, $profile] = makeInvitedGovUser('accept-token-123');

    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                    => 'accept-token-123',
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.email', 'gov@example.gov');

    $user->refresh();
    $profile->refresh();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->status)->toBe('pending_password')
        ->and($profile->invite_accepted_at)->not->toBeNull()
        ->and($profile->invite_token)->toBeNull();
});

it('persists email_verified_at on acceptance even though the column is not fillable', function () {
    [$user] = makeInvitedGovUser('not-fillable-token');

    expect($user->email_verified_at)->toBeNull();

    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                    => 'not-fillable-token',
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])->assertOk();

    // Reading straight from the database: the regression was that Eloquent
    // dropped this key during mass assignment, leaving the row NULL while the
    // in-memory model looked correct.
    $row = DB::table('users')->where('id', $user->id)->first();

    expect($row->email_verified_at)->not->toBeNull()
        ->and($row->status)->toBe('pending_password');
});

it('lets an invited gov user set a password immediately after accepting', function () {
    makeInvitedGovUser('regression-set-password-token');

    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                    => 'regression-set-password-token',
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])->assertOk();

    // Previously returned 422 `email_not_verified`, which stranded the account:
    // the invite token is cleared on acceptance, so the link could not be replayed.
    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => 'gov@example.gov',
        'password'              => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ])->assertOk();
});

it('returns 404 for invalid token on acceptance', function () {
    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                    => 'does-not-exist',
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])
        ->assertStatus(404)
        ->assertJsonPath('code', 'invalid_token');
});

it('returns 422 when token body param is missing', function () {
    $this->postJson('/api/v1/auth/accept-invite', [])
        ->assertStatus(422);
});

it('consumed token cannot be used again after acceptance', function () {
    makeInvitedGovUser('one-time-token');

    $complianceFields = [
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ];

    // First acceptance succeeds
    $this->postJson('/api/v1/auth/accept-invite', array_merge(['token' => 'one-time-token'], $complianceFields))->assertOk();

    // Second attempt fails — token was cleared
    $this->postJson('/api/v1/auth/accept-invite', array_merge(['token' => 'one-time-token'], $complianceFields))
        ->assertStatus(404)
        ->assertJsonPath('code', 'invalid_token');
});

it('after acceptance, set-password works using email from invite', function () {
    [$user] = makeInvitedGovUser('flow-token-abc');

    // Step 1: accept invite
    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                    => 'flow-token-abc',
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])->assertOk();

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

it('gov user login after set-password does not require activation wizard', function () {
    [$user] = makeInvitedGovUser('login-flow-token');

    // Complete invite + set-password flow
    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                    => 'login-flow-token',
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])->assertOk();
    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => 'gov@example.gov',
        'password'              => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ])->assertOk();

    // Login: activation_required must be false, next_activation_url must point to pending-approval
    $this->postJson('/api/v1/auth/login', [
        'email'    => 'gov@example.gov',
        'password' => 'SecurePass123!',
    ])->assertOk()
      ->assertJsonPath('data.activation_required', false)
      ->assertJsonPath('data.next_activation_url', '/activation/pending-approval');
});

it('gov pending_activation user has activation_status pending_approval in /me response', function () {
    [$user] = makeInvitedGovUser('status-token');

    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                    => 'status-token',
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])->assertOk();
    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => 'gov@example.gov',
        'password'              => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ])->assertOk();

    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.activation_required', false)
        ->assertJsonPath('data.activation_status', 'pending_approval');
});

it('approved gov user login has no activation redirect', function () {
    [$user] = makeInvitedGovUser('approved-flow-token');

    $this->postJson('/api/v1/auth/accept-invite', [
        'token'                    => 'approved-flow-token',
        'agree_ecomm_consent'      => true,
        'agree_accuracy_confirmed' => true,
    ])->assertOk();
    $this->postJson('/api/v1/auth/set-password', [
        'email'                 => 'gov@example.gov',
        'password'              => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ])->assertOk();

    // Admin approves the account
    $user->update(['status' => 'active']);

    $this->postJson('/api/v1/auth/login', [
        'email'    => 'gov@example.gov',
        'password' => 'SecurePass123!',
    ])->assertOk()
      ->assertJsonPath('data.activation_required', false)
      ->assertJsonPath('data.next_activation_url', null);
});
