<?php

use App\Models\GovProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Notification::fake();
});

// ── Helpers ───────────────────────────────────────────────────────────────

function makeGovAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    return $admin;
}

function govPayload(array $overrides = []): array
{
    return array_merge([
        'entity_name'           => 'Baltimore City Government',
        'entity_subtype'        => 'government',
        'department_division'   => 'Fleet Management',
        'point_of_contact_name' => 'John Official',
        'contact_title'         => 'Fleet Director',
        'email'                 => 'gov' . rand(1000, 9999) . '@baltimore.gov',
        'phone'                 => '410-555-1000',
        'address'               => '100 City Hall Rd',
        'city'                  => 'Baltimore',
        'state'                 => 'MD',
        'zip'                   => '21201',
    ], $overrides);
}

// ── Create gov account ────────────────────────────────────────────────────

it('admin can create a government account', function () {
    $admin   = makeGovAdmin();
    $payload = govPayload(['email' => 'fleet@baltimore.gov']);

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/government', $payload);

    $response->assertStatus(201)
        ->assertJson(['success' => true])
        ->assertJsonPath('data.user.account_type', 'government');

    $this->assertDatabaseHas('users', [
        'email'        => 'fleet@baltimore.gov',
        'account_type' => 'government',
        'status'       => 'pending_email_verification',
    ]);

    $this->assertDatabaseHas('gov_profiles', [
        'entity_name'    => 'Baltimore City Government',
        'approval_status' => 'pending',
    ]);
});

it('created government user has a valid status enum value (regression: Data truncated for status)', function () {
    $admin = makeGovAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/government', govPayload(['email' => 'enum-check@gov.test']))
        ->assertStatus(201);

    $user = User::where('email', 'enum-check@gov.test')->firstOrFail();

    // Must be one of the valid MySQL enum values to avoid SQLSTATE[01000] truncation errors.
    expect($user->status)->toBe('pending_email_verification')
        ->and(in_array($user->status, [
            'pending_email_verification',
            'pending_password',
            'pending_activation',
            'active',
            'suspended',
        ], true))->toBeTrue();
});

it('admin create gov account assigns buyer role', function () {
    $admin = makeGovAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/government', govPayload(['email' => 'role@gov.test']));

    $user = User::where('email', 'role@gov.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->hasRole('buyer'))->toBeTrue();
});

it('create gov account requires entity_name and email', function () {
    $admin = makeGovAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/government', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['entity_name', 'email', 'point_of_contact_name']);
});

it('create gov rejects duplicate email', function () {
    $admin = makeGovAdmin();
    User::factory()->create(['email' => 'taken@gov.test']);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/government', govPayload(['email' => 'taken@gov.test']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('non-admin cannot create government account', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/admin/government', govPayload())
        ->assertStatus(403);
});

// -- Invitation is issued on creation --------------------------------------

it('emails an invitation as soon as the government account is created', function () {
    $admin = makeGovAdmin();

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/government', govPayload(['email' => 'newfleet@baltimore.gov']));

    $response->assertStatus(201)
        ->assertJsonPath('data.invite_sent', true);

    $gov = User::where('email', 'newfleet@baltimore.gov')->firstOrFail();

    Notification::assertSentTo($gov, App\Notifications\GovAccountInvite::class);

    $profile = GovProfile::where('user_id', $gov->id)->firstOrFail();

    expect($profile->invite_token)->not->toBeNull()
        ->and($profile->invite_sent_at)->not->toBeNull();
});

it('keeps the account and its token when the invitation email fails to send', function () {
    $admin = makeGovAdmin();

    $this->mock(Illuminate\Contracts\Notifications\Dispatcher::class, function ($mock) {
        $mock->shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));
        $mock->shouldReceive('sendNow')->andThrow(new RuntimeException('SMTP down'));
    });

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/government', govPayload(['email' => 'mailfail@baltimore.gov']));

    $response->assertStatus(201)
        ->assertJsonPath('data.invite_sent', false);

    $gov = User::where('email', 'mailfail@baltimore.gov')->firstOrFail();

    $profile = GovProfile::where('user_id', $gov->id)->first();

    // The token is persisted regardless, so "Resend invitation" can recover,
    // but nothing was delivered so the account must not claim it was sent.
    expect($profile->invite_token)->not->toBeNull()
        ->and($profile->invite_sent_at)->toBeNull();
});

it('keeps the existing invitation alive when a resend fails to deliver', function () {
    $admin = makeGovAdmin();

    $gov = User::factory()->create(['account_type' => 'government']);
    $profile = GovProfile::create([
        'user_id'               => $gov->id,
        'entity_name'           => 'Stranded Agency',
        'entity_subtype'        => 'government',
        'point_of_contact_name' => 'Pat Official',
        'phone'                 => '410-555-3000',
        'address'               => '300 Agency Way',
        'city'                  => 'Baltimore',
        'state'                 => 'MD',
        'zip'                   => '21201',
        'approval_status'       => 'pending',
        'invite_token'          => 'the-link-already-in-their-inbox',
        'invite_sent_at'        => now()->subDay(),
    ]);

    $this->mock(Illuminate\Contracts\Notifications\Dispatcher::class, function ($mock) {
        $mock->shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));
        $mock->shouldReceive('sendNow')->andThrow(new RuntimeException('SMTP down'));
    });

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/government/{$gov->id}/invite")
        ->assertStatus(503)
        ->assertJsonPath('code', 'mail_failed');

    // Rotating the token before delivery would have killed a working link.
    expect($profile->refresh()->invite_token)->toBe('the-link-already-in-their-inbox');
});

it('rotates the token only once the resend has actually gone out', function () {
    $admin = makeGovAdmin();

    $gov = User::factory()->create(['account_type' => 'government']);
    $profile = GovProfile::create([
        'user_id'               => $gov->id,
        'entity_name'           => 'Resend Agency',
        'entity_subtype'        => 'government',
        'point_of_contact_name' => 'Pat Official',
        'phone'                 => '410-555-4000',
        'address'               => '400 Agency Way',
        'city'                  => 'Baltimore',
        'state'                 => 'MD',
        'zip'                   => '21201',
        'approval_status'       => 'pending',
        'invite_token'          => 'old-token',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/government/{$gov->id}/invite")
        ->assertOk();

    Notification::assertSentTo($gov, App\Notifications\GovAccountInvite::class);

    expect($profile->refresh()->invite_token)->not->toBe('old-token')
        ->and($profile->invite_sent_at)->not->toBeNull();
});

it('refuses to resend an invitation that has already been accepted', function () {
    $admin = makeGovAdmin();

    $gov = User::factory()->create(['account_type' => 'government']);
    GovProfile::create([
        'user_id'            => $gov->id,
        'entity_name'           => 'Accepted Agency',
        'entity_subtype'        => 'government',
        'point_of_contact_name' => 'Pat Official',
        'phone'                 => '410-555-2000',
        'address'               => '200 Agency Way',
        'city'                  => 'Baltimore',
        'state'                 => 'MD',
        'zip'                   => '21201',
        'approval_status'       => 'pending',
        'invite_accepted_at'    => now(),
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/government/{$gov->id}/invite")
        ->assertStatus(422)
        ->assertJsonPath('code', 'already_accepted');
});

// ── Send invite ───────────────────────────────────────────────────────────

it('admin can send an invite to a government user', function () {
    $admin = makeGovAdmin();
    $gov   = User::factory()->create([
        'email'        => 'invite@gov.test',
        'account_type' => 'government',
        'status'       => 'pending',
    ]);
    GovProfile::create([
        'user_id'               => $gov->id,
        'entity_name'           => 'Test Gov',
        'entity_subtype'        => 'government',
        'point_of_contact_name' => 'Test Contact',
        'phone'                 => '555-000-0001',
        'address'               => '1 Gov St',
        'city'                  => 'Baltimore',
        'state'                 => 'MD',
        'zip'                   => '21201',
        'approval_status'       => 'pending',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/government/{$gov->id}/invite")
        ->assertOk()
        ->assertJson(['success' => true]);

    $profile = $gov->govProfile->fresh();
    expect($profile->invite_token)->not->toBeNull()
        ->and($profile->invite_sent_at)->not->toBeNull();

    Notification::assertSentTo($gov, \App\Notifications\GovAccountInvite::class);
});

// ── List pending ──────────────────────────────────────────────────────────

it('admin can list pending government accounts', function () {
    $admin = makeGovAdmin();

    // Two pending gov accounts
    foreach (range(1, 2) as $i) {
        $gov = User::factory()->create([
            'email'        => "pending{$i}@gov.test",
            'account_type' => 'government',
            'status'       => 'pending',
        ]);
        GovProfile::create([
            'user_id'               => $gov->id,
            'entity_name'           => "Gov Entity {$i}",
            'entity_subtype'        => 'government',
            'point_of_contact_name' => 'Contact',
            'phone'                 => '555-000-0000',
            'address'               => '1 St',
            'city'                  => 'City',
            'state'                 => 'MD',
            'zip'                   => '00000',
            'approval_status'       => 'pending',
        ]);
    }

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/government/pending')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── Show ──────────────────────────────────────────────────────────────────

it('admin can view a government account detail', function () {
    $admin = makeGovAdmin();
    $gov   = User::factory()->create([
        'email'        => 'show@gov.test',
        'account_type' => 'government',
        'status'       => 'pending',
    ]);
    GovProfile::create([
        'user_id'               => $gov->id,
        'entity_name'           => 'Show Entity',
        'entity_subtype'        => 'charity',
        'point_of_contact_name' => 'Jane Charity',
        'phone'                 => '555-000-0000',
        'address'               => '1 Charity Ln',
        'city'                  => 'Baltimore',
        'state'                 => 'MD',
        'zip'                   => '21201',
        'approval_status'       => 'pending',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/government/{$gov->id}")
        ->assertOk()
        ->assertJsonPath('data.government_profile.entity_name', 'Show Entity');
});

it('show returns 404 for a non-government user', function () {
    $admin  = makeGovAdmin();
    $buyer  = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/government/{$buyer->id}")
        ->assertStatus(404);
});

// ── Approve ───────────────────────────────────────────────────────────────

it('admin can approve a government account', function () {
    $admin = makeGovAdmin();
    $gov   = User::factory()->create([
        'email'        => 'approve@gov.test',
        'account_type' => 'government',
        'status'       => 'pending',
    ]);
    GovProfile::create([
        'user_id'               => $gov->id,
        'entity_name'           => 'Approve Gov',
        'entity_subtype'        => 'government',
        'point_of_contact_name' => 'Officer',
        'phone'                 => '555-000-0000',
        'address'               => '1 Gov Ave',
        'city'                  => 'Baltimore',
        'state'                 => 'MD',
        'zip'                   => '21201',
        'approval_status'       => 'pending',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/government/{$gov->id}/approve")
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($gov->fresh()->status)->toBe('active');
    $this->assertDatabaseHas('gov_profiles', [
        'user_id'         => $gov->id,
        'approval_status' => 'approved',
    ]);
});

// ── Reject ────────────────────────────────────────────────────────────────

it('admin can reject a government account with a reason', function () {
    $admin = makeGovAdmin();
    $gov   = User::factory()->create([
        'email'        => 'reject@gov.test',
        'account_type' => 'government',
        'status'       => 'pending',
    ]);
    GovProfile::create([
        'user_id'               => $gov->id,
        'entity_name'           => 'Reject Gov',
        'entity_subtype'        => 'repo',
        'point_of_contact_name' => 'Agent',
        'phone'                 => '555-000-0000',
        'address'               => '1 Repo Rd',
        'city'                  => 'Baltimore',
        'state'                 => 'MD',
        'zip'                   => '21201',
        'approval_status'       => 'pending',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/government/{$gov->id}/reject", [
            'rejection_reason' => 'Authority letter not provided.',
        ])
        ->assertOk();

    expect($gov->fresh()->status)->toBe('suspended');
    $this->assertDatabaseHas('gov_profiles', [
        'user_id'          => $gov->id,
        'approval_status'  => 'rejected',
        'rejection_reason' => 'Authority letter not provided.',
    ]);
});
