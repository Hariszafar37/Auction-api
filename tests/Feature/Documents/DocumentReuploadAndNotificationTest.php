<?php

use App\Models\User;
use App\Models\UserDocument;
use App\Notifications\DocumentStatusUpdatedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ══ ISSUE 1 — DUPLICATE NOTIFICATIONS ═════════════════════════════════════════
//
// Laravel 11 auto-discovers listeners in app/Listeners. AppServiceProvider also
// registered them explicitly with Event::listen(), so DocumentStatusUpdated fired
// its listener twice and the owner received two identical bell entries per review.
// This drives the real HTTP endpoint (full event → listener wiring) and asserts a
// single notification. It would fail (count 2) against the double-registered code.

it('rejecting a document notifies the owner exactly once (no duplicate)', function () {
    Notification::fake();

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $owner = User::factory()->create(['status' => 'active']);
    $doc   = UserDocument::create([
        'user_id'       => $owner->id,
        'type'          => 'government_id',
        'status'        => 'pending_review',
        'disk'          => 'public',
        'file_path'     => 'user-documents/1/government_id/original.jpg',
        'original_name' => 'id.jpg',
        'mime_type'     => 'image/jpeg',
        'size_bytes'    => 48000,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/documents/{$doc->id}/status", [
            'status'      => 'rejected',
            'admin_notes' => 'Blurry photo.',
        ])
        ->assertStatus(200);

    Notification::assertSentToTimes($owner, DocumentStatusUpdatedNotification::class, 1);
});

it('flagging a document needs_resubmission notifies the owner exactly once', function () {
    Notification::fake();

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $owner = User::factory()->create(['status' => 'active']);
    $doc   = UserDocument::create([
        'user_id'       => $owner->id,
        'type'          => 'government_id',
        'status'        => 'pending_review',
        'disk'          => 'public',
        'file_path'     => 'user-documents/1/government_id/original.jpg',
        'original_name' => 'id.jpg',
        'mime_type'     => 'image/jpeg',
        'size_bytes'    => 48000,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/admin/documents/{$doc->id}/status", [
            'status'      => 'needs_resubmission',
            'admin_notes' => 'Please resubmit a clearer scan.',
        ])
        ->assertStatus(200);

    Notification::assertSentToTimes($owner, DocumentStatusUpdatedNotification::class, 1);
});

// ══ ISSUE 2 — SERVER ERROR ON REUPLOAD ════════════════════════════════════════
//
// reupload() referenced an undefined $user when building the signed response URL.
// The document row was already updated, so the file "saved" but the response threw
// a 500 (ErrorException: Undefined variable $user). This asserts a clean 200 and a
// status reset to pending_review.

it('reuploading a flagged document succeeds and resets it to pending_review', function () {
    Storage::fake('public');

    $owner = User::factory()->create(['status' => 'active']);
    $doc   = UserDocument::create([
        'user_id'       => $owner->id,
        'type'          => 'government_id',
        'status'        => 'needs_resubmission',
        'admin_notes'   => 'Please resubmit.',
        'disk'          => 'public',
        'file_path'     => 'user-documents/' . $owner->id . '/government_id/old.jpg',
        'original_name' => 'old.jpg',
        'mime_type'     => 'image/jpeg',
        'size_bytes'    => 10000,
    ]);

    $response = $this->actingAs($owner, 'sanctum')
        ->postJson("/api/v1/my/documents/{$doc->id}/reupload", [
            'file' => UploadedFile::fake()->image('new-id.jpg'),
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'pending_review');

    // The exact expression that used to crash on the undefined $user: the signed
    // download URL must be minted for the owner, not throw a 500.
    expect($response->json('data.url'))
        ->toBeString()
        ->toContain('/documents/' . $doc->id . '/download');

    $doc->refresh();
    expect($doc->status)->toBe('pending_review');
    expect($doc->original_name)->toBe('new-id.jpg');
    expect($doc->admin_notes)->toBeNull();
});

it('reuploading an approved document is rejected with 422', function () {
    Storage::fake('public');

    $owner = User::factory()->create(['status' => 'active']);
    $doc   = UserDocument::create([
        'user_id'       => $owner->id,
        'type'          => 'government_id',
        'status'        => 'approved',
        'disk'          => 'public',
        'file_path'     => 'user-documents/' . $owner->id . '/government_id/ok.jpg',
        'original_name' => 'ok.jpg',
        'mime_type'     => 'image/jpeg',
        'size_bytes'    => 10000,
    ]);

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/v1/my/documents/{$doc->id}/reupload", [
            'file' => UploadedFile::fake()->image('new-id.jpg'),
        ])
        ->assertStatus(422);
});
