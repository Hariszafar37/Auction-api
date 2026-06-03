<?php

use App\Models\BusinessProfile;
use App\Models\DealerProfile;
use App\Models\GovProfile;
use App\Models\PowerOfAttorney;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers (uniquely named to avoid Pest global redeclaration) ─────────────

function approvalDashboardAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    return $admin;
}

function apdPendingDealer(string $company = 'Acme Motors'): DealerProfile
{
    $user = User::factory()->create(['status' => 'pending_activation', 'account_type' => 'dealer']);
    $user->assignRole('buyer');
    return DealerProfile::create([
        'user_id'         => $user->id,
        'company_name'    => $company,
        'dealer_license'  => 'DL-12345',
        'approval_status' => 'pending',
    ]);
}

function apdApprovedSeller(): SellerProfile
{
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $user->assignRole('seller');
    return SellerProfile::create([
        'user_id'         => $user->id,
        'approval_status' => 'approved',
        'reviewed_by'     => approvalDashboardAdmin()->id,
        'reviewed_at'     => now(),
    ]);
}

// ── Dashboard ───────────────────────────────────────────────────────────────

it('returns a summary and normalized records across all approval types', function () {
    apdPendingDealer();
    apdApprovedSeller();

    $business = User::factory()->create(['account_type' => 'business']);
    BusinessProfile::create([
        'user_id'             => $business->id,
        'legal_business_name' => 'Test Biz LLC',
        'entity_type'         => 'llc',
        'approval_status'     => 'rejected',
        'rejection_reason'    => 'Bad docs',
    ]);

    $response = $this->actingAs(approvalDashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/approvals/dashboard');

    $response->assertStatus(200)
        ->assertJsonPath('meta.summary.total', 3)
        ->assertJsonPath('meta.summary.pending', 1)
        ->assertJsonPath('meta.summary.approved', 1)
        ->assertJsonPath('meta.summary.rejected', 1);

    expect(collect($response->json('data'))->pluck('approval_type')->sort()->values()->all())
        ->toEqual(['business', 'dealer', 'seller']);
});

it('filters dashboard records by status', function () {
    apdPendingDealer();
    apdApprovedSeller();

    $response = $this->actingAs(approvalDashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/approvals/dashboard?status=pending');

    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['approval_type'])->toBe('dealer')
        ->and($data[0]['status'])->toBe('pending');
    // Summary stays full breakdown regardless of status filter
    $response->assertJsonPath('meta.summary.total', 2);
});

it('filters dashboard records by approval type', function () {
    apdPendingDealer();
    apdApprovedSeller();

    $response = $this->actingAs(approvalDashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/approvals/dashboard?approval_type=seller');

    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data')[0]['approval_type'])->toBe('seller');
});

it('searches dashboard records by company name', function () {
    apdPendingDealer('Unique Auto House');
    apdPendingDealer('Other Dealer');

    $response = $this->actingAs(approvalDashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/approvals/dashboard?search=Unique');

    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data')[0]['company_name'])->toBe('Unique Auto House');
});

// ── History recording ─────────────────────────────────────────────────────

it('records an approval history row when a dealer is approved', function () {
    $admin   = approvalDashboardAdmin();
    $profile = apdPendingDealer();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/dealers/{$profile->user_id}/approve")
        ->assertStatus(200);

    $this->assertDatabaseHas('approval_histories', [
        'approval_type'   => 'dealer',
        'related_id'      => $profile->id,
        'subject_user_id' => $profile->user_id,
        'action'          => 'approved',
        'previous_status' => 'pending',
        'new_status'      => 'approved',
        'performed_by'    => $admin->id,
    ]);
});

it('returns a record history timeline including a synthesized applied entry', function () {
    $admin   = approvalDashboardAdmin();
    $profile = apdPendingDealer();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/dealers/{$profile->user_id}/approve")
        ->assertStatus(200);

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/approvals/dealer/{$profile->id}/history");

    $response->assertStatus(200);
    $actions = collect($response->json('data'))->pluck('action')->all();
    expect($actions)->toContain('applied')
        ->and($actions)->toContain('approved');
});

it('synthesizes history for a legacy record reviewed before audit logging existed', function () {
    // Seller approved directly via factory — no approval_histories row exists.
    $profile = apdApprovedSeller();

    $response = $this->actingAs(approvalDashboardAdmin(), 'sanctum')
        ->getJson("/api/v1/admin/approvals/seller/{$profile->id}/history");

    $response->assertStatus(200);
    $actions = collect($response->json('data'))->pluck('action')->all();
    expect($actions)->toContain('applied')
        ->and($actions)->toContain('approved');
});

it('returns 404 for an unknown record id', function () {
    $this->actingAs(approvalDashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/approvals/dealer/999999/history')
        ->assertStatus(404);
});

it('returns 422 for an unknown approval type', function () {
    $this->actingAs(approvalDashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/approvals/unicorn/1/history')
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_type');
});

// ── Global history feed ─────────────────────────────────────────────────────

it('returns the global approval history feed', function () {
    $admin   = approvalDashboardAdmin();
    $profile = apdPendingDealer();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/dealers/{$profile->user_id}/reject", ['reason' => 'No good'])
        ->assertStatus(200);

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/approvals/history');

    $response->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'rejected')
        ->assertJsonPath('data.0.approval_type', 'dealer');
});

// ── Authorization ───────────────────────────────────────────────────────────

it('blocks non-admin users from the approval dashboard', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/admin/approvals/dashboard')
        ->assertStatus(403);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/admin/approvals/history')
        ->assertStatus(403);
});
