<?php

use App\Models\DealerProfile;
use App\Models\PowerOfAttorney;
use App\Models\User;
use App\Models\UserDocument;
use App\Notifications\DocumentStatusUpdatedNotification;
use App\Notifications\PoaApprovedNotification;
use App\Notifications\PoaRejectedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Notification::fake();

    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');
});

// ══ POA ═══════════════════════════════════════════════════════════════════════

it('approving a POA sends PoaApprovedNotification to the user', function () {
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'dealer']);
    $user->assignRole('dealer');
    DealerProfile::create([
        'user_id'         => $user->id,
        'company_name'    => 'Test Motors',
        'dealer_license'  => 'DLR-001',
        'approval_status' => 'approved',
    ]);

    $poa = PowerOfAttorney::create([
        'user_id'             => $user->id,
        'type'                => 'esign',
        'status'              => 'signed',
        'signer_printed_name' => $user->name,
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/users/{$user->id}/poa/{$poa->id}/approve")
        ->assertStatus(200);

    Notification::assertSentTo($user, PoaApprovedNotification::class);
});

it('rejecting a POA sends PoaRejectedNotification with admin notes', function () {
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'dealer']);
    $user->assignRole('dealer');
    DealerProfile::create([
        'user_id'         => $user->id,
        'company_name'    => 'Test Motors',
        'dealer_license'  => 'DLR-001',
        'approval_status' => 'approved',
    ]);

    $poa = PowerOfAttorney::create([
        'user_id'             => $user->id,
        'type'                => 'esign',
        'status'              => 'signed',
        'signer_printed_name' => $user->name,
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/users/{$user->id}/poa/{$poa->id}/reject", [
            'admin_notes' => 'Signature does not match records.',
        ])
        ->assertStatus(200);

    Notification::assertSentTo($user, PoaRejectedNotification::class, function ($n) {
        return $n->toDatabase($n)['notes'] === 'Signature does not match records.';
    });
});

// ══ DOCUMENT STATUS ═══════════════════════════════════════════════════════════

it('updating a document status sends DocumentStatusUpdatedNotification', function () {
    $user = User::factory()->create(['status' => 'active']);

    $document = UserDocument::create([
        'user_id'       => $user->id,
        'type'          => 'driver_license',
        'status'        => 'pending',
        'file_path'     => 'documents/test.jpg',
        'disk'          => 'public',
        'original_name' => 'license.jpg',
        'mime_type'     => 'image/jpeg',
        'size_bytes'    => 10000,
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->patchJson("/api/v1/admin/documents/{$document->id}/status", [
            'status'      => 'approved',
            'admin_notes' => null,
        ])
        ->assertStatus(200);

    Notification::assertSentTo($user, DocumentStatusUpdatedNotification::class, function ($n) {
        return $n->toDatabase($n)['status'] === 'approved';
    });
});

it('marking document as needs_resubmission sends notification', function () {
    $user = User::factory()->create(['status' => 'active']);

    $document = UserDocument::create([
        'user_id'       => $user->id,
        'type'          => 'passport',
        'status'        => 'pending',
        'file_path'     => 'documents/passport.jpg',
        'disk'          => 'public',
        'original_name' => 'passport.jpg',
        'mime_type'     => 'image/jpeg',
        'size_bytes'    => 10000,
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->patchJson("/api/v1/admin/documents/{$document->id}/status", [
            'status'      => 'needs_resubmission',
            'admin_notes' => 'Image is blurry, please re-upload.',
        ])
        ->assertStatus(200);

    Notification::assertSentTo($user, DocumentStatusUpdatedNotification::class, function ($n) {
        $data = $n->toDatabase($n);
        return $data['status'] === 'needs_resubmission'
            && $data['admin_notes'] === 'Image is blurry, please re-upload.';
    });
});
