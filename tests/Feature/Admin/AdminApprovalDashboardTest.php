<?php

use App\Models\BusinessProfile;
use App\Models\DealerProfile;
use App\Models\GovProfile;
use App\Models\PowerOfAttorney;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\UserDocument;
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

it('counts POA revision_requested in its own summary bucket and total stays consistent', function () {
    // One POA in each lifecycle state. `signed` normalizes to pending.
    $statuses = ['signed', 'approved', 'rejected', 'revision_requested'];
    foreach ($statuses as $status) {
        $u = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
        PowerOfAttorney::create(['user_id' => $u->id, 'type' => 'esign', 'status' => $status]);
    }

    $response = $this->actingAs(approvalDashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/approvals/dashboard?approval_type=poa');

    $response->assertStatus(200)
        ->assertJsonPath('meta.summary.pending', 1)
        ->assertJsonPath('meta.summary.approved', 1)
        ->assertJsonPath('meta.summary.rejected', 1)
        ->assertJsonPath('meta.summary.revision_requested', 1)
        ->assertJsonPath('meta.summary.total', 4);

    // The four status buckets must sum to the total.
    $s = $response->json('meta.summary');
    expect($s['pending'] + $s['approved'] + $s['rejected'] + $s['revision_requested'])->toBe($s['total']);
});

it('filters dashboard records by revision_requested status', function () {
    $u1 = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    PowerOfAttorney::create(['user_id' => $u1->id, 'type' => 'esign', 'status' => 'revision_requested']);
    $u2 = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    PowerOfAttorney::create(['user_id' => $u2->id, 'type' => 'esign', 'status' => 'signed']);

    $response = $this->actingAs(approvalDashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/approvals/dashboard?status=revision_requested');

    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['approval_type'])->toBe('poa')
        ->and($data[0]['status'])->toBe('revision_requested')
        ->and($data[0]['raw_status'])->toBe('revision_requested');
});

it('does not add revision_requested to non-POA approval types', function () {
    // Dealer/business/seller/gov only ever normalize to pending/approved/rejected.
    apdPendingDealer();
    apdApprovedSeller();

    $response = $this->actingAs(approvalDashboardAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/approvals/dashboard?approval_type=dealer');

    $response->assertStatus(200)
        ->assertJsonPath('meta.summary.revision_requested', 0);
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

// ── Document-review notes (fix: notes written from the user-detail page were
//    invisible to the Approval Dashboard because they live in `user_documents`,
//    not in the profile's `rejection_reason`) ──────────────────────────────────

function apdReviewDocument(User $admin, int $userId, string $notes, string $type = 'dealer_license'): UserDocument
{
    $doc = UserDocument::create([
        'user_id'       => $userId,
        'type'          => $type,
        'status'        => 'pending_review',
        'file_path'     => "docs/{$userId}-{$type}.pdf",
        'disk'          => 'public',
        'original_name' => "{$type}.pdf",
        'mime_type'     => 'application/pdf',
        'size_bytes'    => 1024,
    ]);

    test()->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/documents/{$doc->id}/status", [
            'status'      => 'rejected',
            'admin_notes' => $notes,
        ])
        ->assertStatus(200);

    return $doc->fresh();
}

it('exposes the latest document-review note on the dashboard record', function () {
    $admin   = approvalDashboardAdmin();
    $profile = apdPendingDealer();

    apdReviewDocument($admin, $profile->user_id, 'License scan is blurry — re-upload.');

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/approvals/dashboard?approval_type=dealer');

    $response->assertStatus(200)
        ->assertJsonPath('data.0.document_remarks', 'License scan is blurry — re-upload.')
        ->assertJsonPath('data.0.document_type', 'dealer_license')
        ->assertJsonPath('data.0.document_status', 'rejected')
        ->assertJsonPath('data.0.document_reviewed_by', $admin->name)
        ->assertJsonPath('data.0.document_notes_count', 1);
});

it('keeps document notes separate from the profile rejection reason', function () {
    $admin   = approvalDashboardAdmin();
    $profile = apdPendingDealer();

    apdReviewDocument($admin, $profile->user_id, 'Document note only.');

    // Profile itself was never rejected, so `remarks` must stay null.
    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/approvals/dashboard?approval_type=dealer')
        ->assertStatus(200)
        ->assertJsonPath('data.0.remarks', null)
        ->assertJsonPath('data.0.document_remarks', 'Document note only.');
});

it('reports the newest note and a count when several documents are reviewed', function () {
    $admin   = approvalDashboardAdmin();
    $profile = apdPendingDealer();

    apdReviewDocument($admin, $profile->user_id, 'Older note.', 'id');
    $this->travel(1)->minutes();
    apdReviewDocument($admin, $profile->user_id, 'Newest note.', 'dealer_license');

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/approvals/dashboard?approval_type=dealer')
        ->assertStatus(200)
        ->assertJsonPath('data.0.document_remarks', 'Newest note.')
        ->assertJsonPath('data.0.document_type', 'dealer_license')
        ->assertJsonPath('data.0.document_notes_count', 2);
});

it('defaults document fields when the applicant has no reviewed documents', function () {
    $admin   = approvalDashboardAdmin();
    apdPendingDealer();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/approvals/dashboard?approval_type=dealer')
        ->assertStatus(200)
        ->assertJsonPath('data.0.document_remarks', null)
        ->assertJsonPath('data.0.document_notes_count', 0);
});

it('interleaves document reviews into the record history timeline', function () {
    $admin   = approvalDashboardAdmin();
    $profile = apdPendingDealer();

    apdReviewDocument($admin, $profile->user_id, 'Blurry scan.');

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/approvals/dealer/{$profile->id}/history");

    $response->assertStatus(200);

    $entries = collect($response->json('data'));
    $review  = $entries->firstWhere('action', 'document_reviewed');

    expect($review)->not->toBeNull()
        ->and($review['remarks'])->toBe('Blurry scan.')
        ->and($review['document_type'])->toBe('dealer_license')
        ->and($review['new_status'])->toBe('rejected')
        ->and($review['previous_status'])->toBe('pending_review')
        ->and($review['performed_by_name'])->toBe($admin->name);

    // The synthesized "applied" entry must still be present.
    expect($entries->firstWhere('action', 'applied'))->not->toBeNull();
});

it('still synthesizes a legacy decision entry when only document reviews exist', function () {
    $admin = approvalDashboardAdmin();

    // Legacy profile: reviewed before audit logging existed (no approval_histories row).
    $user    = User::factory()->create(['account_type' => 'dealer']);
    $profile = DealerProfile::create([
        'user_id'          => $user->id,
        'company_name'     => 'Legacy Motors',
        'dealer_license'   => 'DL-LEGACY',
        'approval_status'  => 'rejected',
        'rejection_reason' => 'Legacy reason',
        'reviewed_by'      => $admin->id,
        'reviewed_at'      => now(),
    ]);

    apdReviewDocument($admin, $user->id, 'Doc note.');

    $entries = collect(
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/approvals/dealer/{$profile->id}/history")
            ->assertStatus(200)
            ->json('data')
    );

    // Document rows must not suppress the legacy synthesis.
    $legacy = $entries->firstWhere('action', 'rejected');
    expect($legacy)->not->toBeNull()
        ->and($legacy['remarks'])->toBe('Legacy reason')
        ->and($entries->firstWhere('action', 'document_reviewed'))->not->toBeNull();
});
