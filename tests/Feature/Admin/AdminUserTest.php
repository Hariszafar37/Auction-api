<?php

use App\Models\DealerProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function makeAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    return $admin;
}

function makeDealer(string $approvalStatus = 'pending'): User
{
    $dealer = User::factory()->create(['status' => 'pending']);
    $dealer->assignRole('dealer');
    DealerProfile::create([
        'user_id'         => $dealer->id,
        'company_name'    => 'Test Motors',
        'dealer_license'  => 'DLR-001',
        'packet_accepted_at' => now(),
        'approval_status' => $approvalStatus,
    ]);
    return $dealer;
}

// ── User List ─────────────────────────────────────────────────────────────

it('allows admin to list users', function () {
    User::factory(3)->create();

    $response = $this->actingAs(makeAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/users');

    $response->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure([
            'data',
            'meta' => ['total', 'current_page', 'last_page', 'per_page'],
        ]);
});

it('forbids non-admin from listing users', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/admin/users')
        ->assertStatus(403);
});

it('requires authentication to list users', function () {
    $this->getJson('/api/v1/admin/users')
        ->assertStatus(401);
});

// ── User Status ───────────────────────────────────────────────────────────

it('admin can suspend a user', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/status", ['status' => 'suspended'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'suspended');

    expect($user->fresh()->status)->toBe('suspended');
});

it('admin can activate a user', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'suspended']);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/status", ['status' => 'active'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'active');
});

it('rejects invalid status values', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create();

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/status", ['status' => 'banned'])
        ->assertStatus(422);
});

// ── Dealer Approval ───────────────────────────────────────────────────────

it('lists pending dealers', function () {
    makeDealer('pending');
    makeDealer('pending');
    makeDealer('approved');

    $response = $this->actingAs(makeAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/dealers/pending');

    $response->assertStatus(200)
        ->assertJsonPath('meta.total', 2);
});

it('admin can approve a dealer', function () {
    $admin  = makeAdmin();
    $dealer = makeDealer('pending');

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/dealers/{$dealer->id}/approve")
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    expect($dealer->dealerProfile->fresh()->approval_status)->toBe('approved')
        ->and($dealer->fresh()->status)->toBe('active');
});

it('admin can reject a dealer with a reason', function () {
    $admin  = makeAdmin();
    $dealer = makeDealer('pending');

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/dealers/{$dealer->id}/reject", [
            'reason' => 'License documentation incomplete.',
        ])
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    $profile = $dealer->dealerProfile->fresh();

    expect($profile->approval_status)->toBe('rejected')
        ->and($profile->rejection_reason)->toBe('License documentation incomplete.')
        ->and($dealer->fresh()->status)->toBe('suspended');
});

it('reject dealer requires a reason', function () {
    $admin  = makeAdmin();
    $dealer = makeDealer('pending');

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/dealers/{$dealer->id}/reject", [])
        ->assertStatus(422)
        ->assertJsonPath('errors.reason', fn ($v) => count($v) > 0);
});

it('returns 404 for non-existent user', function () {
    $this->actingAs(makeAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/users/99999')
        ->assertStatus(404);
});

// ── Role Management ────────────────────────────────────────────────────────

it('admin can update another user role', function () {
    $admin = makeAdmin();
    $buyer = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$buyer->id}/role", ['role' => 'dealer'])
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    expect($buyer->fresh()->hasRole('dealer'))->toBeTrue();
});

it('admin cannot change their own role (self-demotion)', function () {
    $admin = makeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$admin->id}/role", ['role' => 'buyer'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'self_demotion');
});

it('cannot demote the only other admin (last_admin guardrail)', function () {
    $actingAdmin = makeAdmin();
    $targetAdmin = makeAdmin();
    // Only these two admins exist — no third admin to fall back on.

    $this->actingAs($actingAdmin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$targetAdmin->id}/role", ['role' => 'buyer'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'last_admin');
});

it('can demote an admin when a third admin exists', function () {
    $actingAdmin = makeAdmin();
    $targetAdmin = makeAdmin();
    makeAdmin(); // third admin — system stays covered after demotion

    $this->actingAs($actingAdmin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$targetAdmin->id}/role", ['role' => 'buyer'])
        ->assertStatus(200);

    expect($targetAdmin->fresh()->hasRole('buyer'))->toBeTrue();
});

// ── Permission Middleware ──────────────────────────────────────────────────

it('requires users.view permission to list users', function () {
    $admin = makeAdmin(); // admin role carries users.view

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/users')
        ->assertStatus(200);
});

it('requires users.manage permission to update user status', function () {
    $admin = makeAdmin(); // admin role carries users.manage
    $user  = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/status", ['status' => 'suspended'])
        ->assertStatus(200);
});

it('requires dealers.view permission to list pending dealers', function () {
    $admin = makeAdmin(); // admin role carries dealers.view

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/dealers/pending')
        ->assertStatus(200);
});

it('requires dealers.approve permission to approve a dealer', function () {
    $admin  = makeAdmin(); // admin role carries dealers.approve
    $dealer = makeDealer('pending');

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/dealers/{$dealer->id}/approve")
        ->assertStatus(200);
});

it('non-admin is blocked by role middleware before reaching permission check', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');

    // buyer has no admin role — role:admin gate fires first → 403
    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/admin/users')
        ->assertStatus(403);

    $this->actingAs($buyer, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$buyer->id}/status", ['status' => 'suspended'])
        ->assertStatus(403);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/admin/dealers/pending')
        ->assertStatus(403);
});

// ── User Detail — Date Casting Regression ─────────────────────────────────
// Covers the bug: "Call to a member function toIso8601String() on string"
// Root cause: UserDocument.reviewed_at had no cast, so it was returned as a
// raw string instead of a Carbon instance, which caused toIso8601String() to
// fail for any user whose documents had been reviewed.

it('admin can fetch user detail with no documents (baseline)', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);
    $user->assignRole('buyer');

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/users/{$user->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.id', $user->id);
});

it('admin can fetch user detail when documents have null reviewed_at', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);
    $user->assignRole('buyer');

    // Document uploaded but not yet reviewed — reviewed_at is null
    $user->documents()->create([
        'type'          => 'id',
        'status'        => 'pending_review',
        'file_path'     => 'documents/test.jpg',
        'disk'          => 'public',
        'original_name' => 'test.jpg',
        'mime_type'     => 'image/jpeg',
        'size_bytes'    => 1024,
        'reviewed_at'   => null,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/users/{$user->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.documents.0.reviewed_at', null);
});

it('admin can fetch user detail when documents have a Carbon reviewed_at', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);
    $user->assignRole('buyer');

    $reviewedAt = now()->setMicroseconds(0);

    $user->documents()->create([
        'type'          => 'id',
        'status'        => 'approved',
        'file_path'     => 'documents/test.jpg',
        'disk'          => 'public',
        'original_name' => 'test.jpg',
        'mime_type'     => 'image/jpeg',
        'size_bytes'    => 1024,
        'reviewed_by'   => $admin->id,
        'reviewed_at'   => $reviewedAt,
    ]);

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/users/{$user->id}")
        ->assertStatus(200);

    // Should be a valid ISO-8601 string — not null, not an exception
    $reviewedAtStr = $response->json('data.documents.0.reviewed_at');
    expect($reviewedAtStr)->toBeString()
        ->and(\Carbon\Carbon::parse($reviewedAtStr)->toIso8601String())->toBe($reviewedAtStr);
});

it('user detail returns valid ISO-8601 for all date fields — no toIso8601String exception', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create([
        'status'             => 'active',
        'email_verified_at'  => now(),
        'agreed_terms_at'    => now(),
    ]);
    $user->assignRole('buyer');

    // Reviewed document — this is the exact case that triggered the bug
    $user->documents()->create([
        'type'          => 'dealer_license',
        'status'        => 'approved',
        'file_path'     => 'documents/license.pdf',
        'disk'          => 'public',
        'original_name' => 'license.pdf',
        'mime_type'     => 'application/pdf',
        'size_bytes'    => 20480,
        'reviewed_by'   => $admin->id,
        'reviewed_at'   => now(),
    ]);

    // Must not throw — previously crashed with "Call to a member function
    // toIso8601String() on string" when reviewed_at was not cast.
    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/users/{$user->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.id', $user->id);
});
