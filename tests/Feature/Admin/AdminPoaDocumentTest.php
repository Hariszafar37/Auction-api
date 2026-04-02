<?php

use App\Models\PowerOfAttorney;
use App\Models\User;
use App\Models\UserDocument;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('public');
});

// ── Helpers ───────────────────────────────────────────────────────────────

function makePoaAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    return $admin;
}

function makePoaUser(): User
{
    $user = User::factory()->create([
        'status'       => 'active',
        'account_type' => 'individual',
    ]);
    $user->assignRole('buyer');
    return $user;
}

// ══ USER-FACING POA ENDPOINTS ════════════════════════════════════════════

it('user can e-sign a POA and it is stored as signed', function () {
    $user = makePoaUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/activation/poa/esign', [
            'signer_printed_name' => 'Jane Doe',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.type', 'esign')
        ->assertJsonPath('data.status', 'signed')
        ->assertJsonPath('data.signer_printed_name', 'Jane Doe');

    $this->assertDatabaseHas('power_of_attorney', [
        'user_id' => $user->id,
        'type'    => 'esign',
        'status'  => 'signed',
    ]);
});

it('user can upload a signed POA document', function () {
    $user = makePoaUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/activation/poa/upload', [
            'file' => UploadedFile::fake()->create('poa.pdf', 500, 'application/pdf'),
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.type', 'upload')
        ->assertJsonPath('data.status', 'signed');

    $this->assertDatabaseHas('power_of_attorney', [
        'user_id' => $user->id,
        'type'    => 'upload',
        'status'  => 'signed',
    ]);
});

it('second POA submission is rejected — poa_already_exists', function () {
    $user = makePoaUser();

    // First submission
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/activation/poa/esign', [
            'signer_printed_name' => 'Jane Doe',
        ])->assertStatus(201);

    // Second submission should be rejected
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/activation/poa/esign', [
            'signer_printed_name' => 'Jane Doe Again',
        ])->assertStatus(422)
          ->assertJsonPath('code', 'poa_already_exists');
});

it('user can view their own POA record', function () {
    $user = makePoaUser();

    PowerOfAttorney::create([
        'user_id'             => $user->id,
        'type'                => 'esign',
        'status'              => 'signed',
        'signer_printed_name' => 'Jane Doe',
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/my/poa')
        ->assertOk()
        ->assertJsonPath('data.type', 'esign')
        ->assertJsonPath('data.status', 'signed');
});

it('user with no POA receives null from the my/poa endpoint', function () {
    $user = makePoaUser();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/my/poa')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('unauthenticated user cannot esign or view POA', function () {
    $this->postJson('/api/v1/activation/poa/esign', ['signer_printed_name' => 'X'])
        ->assertStatus(401);

    $this->getJson('/api/v1/my/poa')
        ->assertStatus(401);
});

// ══ ADMIN POA REVIEW ════════════════════════════════════════════════════

it('admin can list POAs for a user', function () {
    $admin = makePoaAdmin();
    $user  = makePoaUser();

    PowerOfAttorney::create([
        'user_id' => $user->id, 'type' => 'esign', 'status' => 'signed',
        'signer_printed_name' => 'Jane Doe',
    ]);
    PowerOfAttorney::create([
        'user_id' => $user->id, 'type' => 'upload', 'status' => 'rejected',
        'file_path' => 'poa/old.pdf',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/users/{$user->id}/poa")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('admin can approve a POA', function () {
    $admin = makePoaAdmin();
    $user  = makePoaUser();

    $poa = PowerOfAttorney::create([
        'user_id'             => $user->id,
        'type'                => 'esign',
        'status'              => 'signed',
        'signer_printed_name' => 'Jane Doe',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/users/{$user->id}/poa/{$poa->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $this->assertDatabaseHas('power_of_attorney', [
        'id'          => $poa->id,
        'status'      => 'approved',
        'reviewed_by' => $admin->id,
    ]);
});

it('admin can reject a POA with admin notes', function () {
    $admin = makePoaAdmin();
    $user  = makePoaUser();

    $poa = PowerOfAttorney::create([
        'user_id'   => $user->id,
        'type'      => 'upload',
        'status'    => 'signed',
        'file_path' => 'poa/test.pdf',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/users/{$user->id}/poa/{$poa->id}/reject", [
            'admin_notes' => 'Signature does not match records.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    $this->assertDatabaseHas('power_of_attorney', [
        'id'          => $poa->id,
        'status'      => 'rejected',
        'admin_notes' => 'Signature does not match records.',
    ]);
});

it('admin poa rejection requires admin_notes', function () {
    $admin = makePoaAdmin();
    $user  = makePoaUser();

    $poa = PowerOfAttorney::create([
        'user_id' => $user->id, 'type' => 'esign', 'status' => 'signed',
        'signer_printed_name' => 'Test',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/users/{$user->id}/poa/{$poa->id}/reject", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['admin_notes']);
});

// ══ ADMIN DOCUMENT REVIEW ════════════════════════════════════════════════

it('admin can update a document status to approved', function () {
    $admin = makePoaAdmin();
    $user  = makePoaUser();

    $doc = UserDocument::create([
        'user_id'       => $user->id,
        'type'          => 'id',
        'file_path'     => 'user-documents/1/id/test.jpg',
        'disk'          => 'public',
        'original_name' => 'test.jpg',
        'mime_type'     => 'image/jpeg',
        'size_bytes'    => 50000,
        'status'        => 'pending_review',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/documents/{$doc->id}/status", [
            'status'      => 'approved',
            'admin_notes' => 'Looks good.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.admin_notes', 'Looks good.');

    $this->assertDatabaseHas('user_documents', [
        'id'          => $doc->id,
        'status'      => 'approved',
        'admin_notes' => 'Looks good.',
        'reviewed_by' => $admin->id,
    ]);
});

it('admin can set document status to needs_resubmission', function () {
    $admin = makePoaAdmin();
    $user  = makePoaUser();

    $doc = UserDocument::create([
        'user_id'       => $user->id,
        'type'          => 'dealer_license',
        'file_path'     => 'user-documents/1/dealer_license/lic.pdf',
        'disk'          => 'public',
        'original_name' => 'license.pdf',
        'mime_type'     => 'application/pdf',
        'size_bytes'    => 200000,
        'status'        => 'pending_review',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/documents/{$doc->id}/status", [
            'status'      => 'needs_resubmission',
            'admin_notes' => 'Image is blurry.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'needs_resubmission');
});

it('document status update requires a valid status value', function () {
    $admin = makePoaAdmin();
    $user  = makePoaUser();

    $doc = UserDocument::create([
        'user_id' => $user->id, 'type' => 'id', 'file_path' => 'x',
        'disk' => 'public', 'original_name' => 'x.jpg',
        'mime_type' => 'image/jpeg', 'size_bytes' => 1000,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/documents/{$doc->id}/status", [
            'status' => 'invalid_status',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('non-admin cannot update a document status', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');

    $doc = UserDocument::create([
        'user_id' => $buyer->id, 'type' => 'id', 'file_path' => 'x',
        'disk' => 'public', 'original_name' => 'x.jpg',
        'mime_type' => 'image/jpeg', 'size_bytes' => 1000,
    ]);

    $this->actingAs($buyer, 'sanctum')
        ->patchJson("/api/v1/admin/documents/{$doc->id}/status", [
            'status' => 'approved',
        ])
        ->assertStatus(403);
});
