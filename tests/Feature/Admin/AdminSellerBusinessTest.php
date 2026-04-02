<?php

use App\Models\BusinessProfile;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function makeAdminUser(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    return $admin;
}

function makeIndividualWithPendingSellerApplication(): User
{
    $user = User::factory()->create([
        'status'       => 'active',
        'account_type' => 'individual',
    ]);
    $user->assignRole('buyer');
    SellerProfile::create([
        'user_id'            => $user->id,
        'approval_status'    => 'pending',
        'application_notes'  => 'I want to sell my collection.',
        'packet_accepted_at' => now(),
    ]);
    return $user;
}

function makePendingBusinessUser(): User
{
    $user = User::factory()->create([
        'status'       => 'pending_activation',
        'account_type' => 'business',
    ]);
    $user->assignRole('buyer');
    BusinessProfile::create([
        'user_id'             => $user->id,
        'legal_business_name' => 'Test Biz LLC',
        'entity_type'         => 'llc',
        'approval_status'     => 'pending',
        'packet_accepted_at'  => now(),
    ]);
    return $user;
}

// ══ SELLER APPROVAL ══════════════════════════════════════════════════════

it('admin can list pending seller applications', function () {
    makeIndividualWithPendingSellerApplication();
    makeIndividualWithPendingSellerApplication();

    $response = $this->actingAs(makeAdminUser(), 'sanctum')
        ->getJson('/api/v1/admin/sellers/pending');

    $response->assertStatus(200)
        ->assertJsonPath('meta.total', 2);
});

it('admin can approve a seller application', function () {
    $admin  = makeAdminUser();
    $seller = makeIndividualWithPendingSellerApplication();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/sellers/{$seller->id}/approve")
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    // Seller role must be assigned (buyer role kept — assignRole not syncRoles)
    expect($seller->fresh()->hasRole('seller'))->toBeTrue()
        ->and($seller->fresh()->hasRole('buyer'))->toBeTrue()
        ->and($seller->fresh()->account_intent)->toBe('buyer_and_seller');

    $this->assertDatabaseHas('seller_profiles', [
        'user_id'         => $seller->id,
        'approval_status' => 'approved',
    ]);
});

it('admin can reject a seller application — user stays active as buyer', function () {
    $admin  = makeAdminUser();
    $seller = makeIndividualWithPendingSellerApplication();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/sellers/{$seller->id}/reject", [
            'reason' => 'Insufficient documentation.',
        ])
        ->assertStatus(200);

    // User must stay active — only seller capability denied
    expect($seller->fresh()->status)->toBe('active')
        ->and($seller->fresh()->hasRole('seller'))->toBeFalse();

    $this->assertDatabaseHas('seller_profiles', [
        'user_id'          => $seller->id,
        'approval_status'  => 'rejected',
        'rejection_reason' => 'Insufficient documentation.',
    ]);
});

it('admin cannot approve seller for a non-individual account', function () {
    $admin  = makeAdminUser();
    $dealer = User::factory()->create(['status' => 'active', 'account_type' => 'dealer']);
    $dealer->assignRole('dealer');
    SellerProfile::create([
        'user_id'            => $dealer->id,
        'approval_status'    => 'pending',
        'application_notes'  => 'Test',
        'packet_accepted_at' => now(),
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/sellers/{$dealer->id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('code', 'wrong_account_type');
});

it('reject seller can be submitted without a reason — reason is optional', function () {
    // RejectSellerRequest has reason as nullable, unlike RejectDealerRequest
    $admin  = makeAdminUser();
    $seller = makeIndividualWithPendingSellerApplication();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/sellers/{$seller->id}/reject", [])
        ->assertStatus(200);
});

// ══ BUSINESS APPROVAL ════════════════════════════════════════════════════

it('admin can list pending business accounts', function () {
    makePendingBusinessUser();
    makePendingBusinessUser();

    $response = $this->actingAs(makeAdminUser(), 'sanctum')
        ->getJson('/api/v1/admin/businesses/pending');

    $response->assertStatus(200)
        ->assertJsonPath('meta.total', 2);
});

it('admin can approve a business account', function () {
    $admin    = makeAdminUser();
    $business = makePendingBusinessUser();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/businesses/{$business->id}/approve")
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    expect($business->fresh()->status)->toBe('active');

    $this->assertDatabaseHas('business_profiles', [
        'user_id'         => $business->id,
        'approval_status' => 'approved',
    ]);
});

it('admin can reject a business account', function () {
    $admin    = makeAdminUser();
    $business = makePendingBusinessUser();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/businesses/{$business->id}/reject", [
            'reason' => 'Incomplete articles of incorporation.',
        ])
        ->assertStatus(200);

    expect($business->fresh()->status)->toBe('suspended');

    $this->assertDatabaseHas('business_profiles', [
        'user_id'          => $business->id,
        'approval_status'  => 'rejected',
        'rejection_reason' => 'Incomplete articles of incorporation.',
    ]);
});

it('reject business can be submitted without a reason — reason is optional', function () {
    // RejectBusinessRequest has reason as nullable, unlike RejectDealerRequest
    $admin    = makeAdminUser();
    $business = makePendingBusinessUser();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/businesses/{$business->id}/reject", [])
        ->assertStatus(200);
});

it('non-admin cannot access seller or business admin endpoints', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/admin/sellers/pending')
        ->assertStatus(403);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/admin/businesses/pending')
        ->assertStatus(403);
});
