<?php

use App\Models\AccountAction;
use App\Models\DealerProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\PersonalAccessToken;

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
            'meta' => ['total', 'current_page', 'last_page', 'per_page', 'from', 'to'],
        ]);
});

it('sorts users newest-first by default', function () {
    $oldest = User::factory()->create(['created_at' => now()->subDays(3)]);
    $middle = User::factory()->create(['created_at' => now()->subDays(2)]);
    $newest = User::factory()->create(['created_at' => now()->subDay()]);

    $ids = $this->actingAs(makeAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/users')
        ->assertStatus(200)
        ->json('data.*.id');

    // The acting admin is created last, so it leads; the seeded three follow
    // in strict newest-first order.
    expect(array_values(array_intersect($ids, [$oldest->id, $middle->id, $newest->id])))
        ->toBe([$newest->id, $middle->id, $oldest->id]);
});

it('still honours an explicit sort parameter', function () {
    // Created newest-last, so the default sort would return them Zoe-first.
    $zoe   = User::factory()->create(['name' => 'Zoe Zander']);
    $aaron = User::factory()->create(['name' => 'Aaron Abbott']);

    $ids = $this->actingAs(makeAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/users?sort=name')
        ->assertStatus(200)
        ->json('data.*.id');

    expect(array_search($aaron->id, $ids, true))
        ->toBeLessThan(array_search($zoe->id, $ids, true));
});

it('defaults to 20 users per page and reports an accurate range', function () {
    $admin = makeAdmin();

    // 22 factory users + the acting admin = 23 total, i.e. two pages.
    User::factory(22)->create();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/users')
        ->assertStatus(200)
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.per_page', 20)
        ->assertJsonPath('meta.total', 23)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.from', 1)
        ->assertJsonPath('meta.to', 20);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/users?page=2')
        ->assertStatus(200)
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.from', 21)
        ->assertJsonPath('meta.to', 23);
});

/**
 * Regression — a newly activated user appeared absent from the unfiltered
 * listing while still being returned by name-search and status filters.
 * Root cause was the missing ORDER BY, which left MySQL returning rows in
 * ascending primary-key order and pushed the newest user onto the last page.
 *
 * Note this test runs on SQLite, whose ordering is deterministic, so it does
 * not reproduce the engine-level instability on its own — it pins the
 * contract: the activated user is reachable from the default listing, and the
 * pages tile the result set with no gaps or duplicates.
 */
it('shows a newly activated user in the default listing without search or filter', function () {
    $admin = makeAdmin();

    // Enough existing users to push the listing past a single page.
    User::factory(45)->create(['status' => 'active', 'created_at' => now()->subMonth()]);

    $newUser = User::factory()->create([
        'status'     => 'pending_activation',
        'created_at' => now()->subMonth(),
    ]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$newUser->id}/status", ['status' => 'active'])
        ->assertStatus(200);

    // Walk every page of the default (unfiltered, unsearched) listing.
    $seen = [];
    $page = 1;
    do {
        $body = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/users?page={$page}")
            ->assertStatus(200)
            ->json();

        $seen = array_merge($seen, $body['data'] ? array_column($body['data'], 'id') : []);
        $lastPage = $body['meta']['last_page'];
        $page++;
    } while ($page <= $lastPage);

    expect($seen)->toContain($newUser->id)
        // No duplicates and no gaps: the pages tile the full result set exactly.
        ->and(count($seen))->toBe(count(array_unique($seen)))
        ->and(count($seen))->toBe(User::count());

    // ...and consistently with the filtered/search paths that always worked.
    $filtered = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/users?filter[status]=active')
        ->assertStatus(200)
        ->json('data.*.id');

    expect($filtered)->toContain($newUser->id);
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

it('promoting a user to seller keeps the buyer role (seller can also buy)', function () {
    $admin = makeAdmin();
    $buyer = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$buyer->id}/role", ['role' => 'seller'])
        ->assertStatus(200);

    $fresh = $buyer->fresh();
    expect($fresh->hasRole('seller'))->toBeTrue()
        ->and($fresh->hasRole('buyer'))->toBeTrue();
});

it('accepts staff as a valid role assignment', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);
    $user->assignRole('buyer');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/role", ['role' => 'staff'])
        ->assertStatus(200);

    expect($user->fresh()->hasRole('staff'))->toBeTrue();
});

it('rejects an unknown role value', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/role", ['role' => 'superuser'])
        ->assertStatus(422);
});

// ── Admin profile editing ──────────────────────────────────────────────────

it('exposes account_intent and editable sections on user detail', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active', 'account_intent' => 'buyer']);
    $user->assignRole('buyer');

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/users/{$user->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.account_intent', 'buyer')
        ->assertJsonStructure(['data' => ['account_information', 'billing_information']]);
});

it('admin can update a user core profile (name / email)', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active', 'email' => 'old@example.com']);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}", [
            'name'  => 'Renamed User',
            'email' => 'new@example.com',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Renamed User')
        ->assertJsonPath('data.email', 'new@example.com');

    expect($user->fresh()->email)->toBe('new@example.com');
});

it('admin profile update rejects an email already used by another user', function () {
    $admin = makeAdmin();
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}", ['email' => 'taken@example.com'])
        ->assertStatus(422);
});

it('admin can update a user contact (account) information', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/v1/admin/users/{$user->id}/account-information", [
            'date_of_birth'   => '1990-01-01',
            'address'         => '123 Main St',
            'country'         => 'US',
            'state'           => 'Maryland',
            'city'            => 'Baltimore',
            'zip_postal_code' => '21201',
            'id_type'         => 'driver_license',
            'id_number'       => 'D1234567',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.account_information.address', '123 Main St');

    expect($user->fresh()->accountInformation->city)->toBe('Baltimore');
});

it('admin can update a user billing information', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/v1/admin/users/{$user->id}/billing-information", [
            'billing_address'         => '500 Market St',
            'billing_country'         => 'US',
            'billing_city'            => 'Annapolis',
            'billing_state'           => 'MD',
            'billing_zip_postal_code' => '21401',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.billing_information.billing_address', '500 Market St');
});

it('admin cannot edit business information for a non-business account', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/v1/admin/users/{$user->id}/business-information", [
            'legal_business_name'  => 'Acme LLC',
            'primary_contact_name' => 'Jane Doe',
            'contact_title'        => 'CEO',
            'phone'                => '4105551234',
            'address'              => '1 Way',
            'city'                 => 'Baltimore',
            'state'                => 'MD',
            'zip'                  => '21201',
            'entity_type'          => 'llc',
            'state_of_formation'   => 'MD',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'not_a_business');
});

it('forbids a non-admin from editing a user profile', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer'); // blocked by the role:admin gate
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($buyer, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}", ['name' => 'Hacked'])
        ->assertStatus(403);
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

// ══ Account restrictions ══════════════════════════════════════════════════════

it('admin can block a user and it revokes all tokens', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);
    // Simulate a live session for the target user.
    $user->createToken('api');
    expect($user->tokens()->count())->toBe(1);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/status", ['status' => 'blocked', 'reason' => 'fraud'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'blocked');

    expect($user->fresh()->status)->toBe('blocked');
    // Hard lockout — every token deleted.
    expect(PersonalAccessToken::where('tokenable_id', $user->id)->count())->toBe(0);
});

it('suspending a user does NOT revoke tokens', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);
    $user->createToken('api');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/status", ['status' => 'suspended'])
        ->assertStatus(200);

    expect(PersonalAccessToken::where('tokenable_id', $user->id)->count())->toBe(1);
});

it('reactivating a user restores active status but does NOT re-enable bidding/selling', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create([
        'status'          => 'suspended',
        'bidding_enabled' => false,
        'selling_enabled' => false,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/status", ['status' => 'active'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.bidding_enabled', false)
        ->assertJsonPath('data.selling_enabled', false);

    $fresh = $user->fresh();
    expect($fresh->status)->toBe('active');
    expect($fresh->bidding_enabled)->toBeFalse();
    expect($fresh->selling_enabled)->toBeFalse();
});

it('rejects a no-op status change', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/status", ['status' => 'active'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'no_change');
});

it('records an audit row for a status change', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/status", ['status' => 'suspended', 'reason' => 'chargebacks']);

    $action = AccountAction::where('subject_user_id', $user->id)->latest('id')->first();
    expect($action)->not->toBeNull();
    expect($action->action)->toBe('suspended');
    expect($action->previous_value)->toBe('active');
    expect($action->new_value)->toBe('suspended');
    expect($action->reason)->toBe('chargebacks');
    expect($action->performed_by)->toBe($admin->id);
});

it('admin can disable and re-enable bidding independently', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/bidding", ['enabled' => false, 'reason' => 'review'])
        ->assertStatus(200)
        ->assertJsonPath('data.bidding_enabled', false)
        ->assertJsonPath('data.selling_enabled', true);

    expect($user->fresh()->bidding_enabled)->toBeFalse();
    expect(AccountAction::where('subject_user_id', $user->id)->where('action', 'bidding_disabled')->exists())->toBeTrue();

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/bidding", ['enabled' => true])
        ->assertStatus(200)
        ->assertJsonPath('data.bidding_enabled', true);
});

it('admin can disable selling without affecting bidding', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/selling", ['enabled' => false])
        ->assertStatus(200)
        ->assertJsonPath('data.selling_enabled', false)
        ->assertJsonPath('data.bidding_enabled', true);

    expect($user->fresh()->selling_enabled)->toBeFalse();
});

it('a bidding-disabled active user is blocked from bidding with bidding_disabled reason', function () {
    $user = User::factory()->create(['status' => 'active', 'bidding_enabled' => false]);
    expect($user->canBid())->toBeFalse();
    expect($user->getBidIneligibilityReason())->toBe('bidding_disabled');
});

it('a selling-disabled active seller cannot perform seller actions', function () {
    $user = User::factory()->create([
        'status'          => 'active',
        'account_intent'  => 'buyer_and_seller',
        'selling_enabled' => false,
    ]);
    $user->assignRole('seller');
    expect($user->canPerformSellerActions())->toBeFalse();
});

it('blocked users are locked out of every authenticated route by the middleware', function () {
    $user = User::factory()->create(['status' => 'blocked']);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me')
        ->assertStatus(403)
        ->assertJsonPath('code', 'account_blocked');
});

it('blocking a user is denied login', function () {
    $user = User::factory()->create(['status' => 'blocked', 'password' => bcrypt('password123')]);

    $this->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'password123',
    ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'account_blocked');
});

// ══ Account-action audit history ══════════════════════════════════════════════

it('returns account-action history newest first with full detail', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    // Two actions in sequence — suspend then disable bidding.
    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/status", ['status' => 'suspended', 'reason' => 'chargebacks'])
        ->assertStatus(200);
    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/users/{$user->id}/bidding", ['enabled' => false])
        ->assertStatus(200);

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/users/{$user->id}/account-actions")
        ->assertStatus(200)
        ->assertJsonCount(2, 'data');

    // Newest first — the bidding toggle was performed last.
    $response->assertJsonPath('data.0.action', 'bidding_disabled')
             ->assertJsonPath('data.0.previous_value', 'enabled')
             ->assertJsonPath('data.0.new_value', 'disabled')
             ->assertJsonPath('data.0.performed_by', $admin->name);

    $response->assertJsonPath('data.1.action', 'suspended')
             ->assertJsonPath('data.1.previous_value', 'active')
             ->assertJsonPath('data.1.new_value', 'suspended')
             ->assertJsonPath('data.1.reason', 'chargebacks');
});

it('returns an empty history when no actions have been taken', function () {
    $admin = makeAdmin();
    $user  = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/users/{$user->id}/account-actions")
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');
});

it('forbids a non-admin from reading account-action history', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');
    $subject = User::factory()->create(['status' => 'active']);

    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/admin/users/{$subject->id}/account-actions")
        ->assertStatus(403);
});

it('requires authentication to read account-action history', function () {
    $subject = User::factory()->create(['status' => 'active']);

    $this->getJson("/api/v1/admin/users/{$subject->id}/account-actions")
        ->assertStatus(401);
});
