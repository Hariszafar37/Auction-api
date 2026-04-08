<?php

use App\Models\User;
use App\Models\UserDocument;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    // Fake both possible disks — ActivationController stores on the default
    // disk (local in test env), while older rows may live on public.
    Storage::fake('local');
    Storage::fake('public');
});

function makeDocAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    return $admin;
}

function makeDocUser(): User
{
    $user = User::factory()->create([
        'status'       => 'active',
        'account_type' => 'individual',
    ]);
    $user->assignRole('buyer');
    return $user;
}

function makeStoredDocument(User $user, string $type = 'id', string $disk = 'local'): UserDocument
{
    $path = "user-documents/{$user->id}/{$type}/test-file.png";
    Storage::disk($disk)->put($path, 'fake-image-bytes');

    return UserDocument::create([
        'user_id'       => $user->id,
        'type'          => $type,
        'file_path'     => $path,
        'disk'          => $disk,
        'original_name' => 'test-file.png',
        'mime_type'     => 'image/png',
        'size_bytes'    => 16,
        'status'        => 'pending_review',
    ]);
}

function docUrlFor(UserDocument $doc, User $viewer, int $ttl = 5): string
{
    return URL::temporarySignedRoute(
        'documents.download',
        now()->addMinutes($ttl),
        ['document' => $doc->id, 'viewer_id' => $viewer->id]
    );
}

// ══ UserResource URL emission ═══════════════════════════════════════════════

it('admin user detail returns a signed absolute download URL for each document', function () {
    $admin = makeDocAdmin();
    $user  = makeDocUser();

    makeStoredDocument($user, 'id');
    makeStoredDocument($user, 'dealer_license');

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/users/{$user->id}")
        ->assertOk();

    $docs = $response->json('data.documents');
    expect($docs)->toHaveCount(2);

    foreach ($docs as $doc) {
        // URL must be absolute and anchored to APP_URL — no bare /storage/ paths.
        expect($doc['url'])->toStartWith(config('app.url'));
        expect($doc['url'])->toContain('/documents/'.$doc['id'].'/download');
        // Laravel signed URLs always include these query params.
        expect($doc['url'])->toContain('signature=');
        expect($doc['url'])->toContain('expires=');
        // The admin's id must be embedded as viewer_id.
        expect($doc['url'])->toContain('viewer_id='.$admin->id);
    }
});

it('auth/me returns a signed absolute download URL for the users own documents', function () {
    $user = makeDocUser();
    makeStoredDocument($user, 'id');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/auth/me')
        ->assertOk();

    $docs = $response->json('data.documents');
    expect($docs)->toHaveCount(1);
    expect($docs[0]['url'])->toStartWith(config('app.url'));
    expect($docs[0]['url'])->toContain('signature=');
    expect($docs[0]['url'])->toContain('viewer_id='.$user->id);
});

it('admin user detail with no documents does not regress', function () {
    $admin = makeDocAdmin();
    $user  = makeDocUser();

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/users/{$user->id}")
        ->assertOk();

    expect($response->json('data.documents'))->toBe([]);
});

// ══ Signed download route — happy path ═════════════════════════════════════

it('streams the file when the signed URL is valid and viewer is authorized', function () {
    $admin = makeDocAdmin();
    $user  = makeDocUser();
    $doc   = makeStoredDocument($user, 'id');

    $response = $this->get(docUrlFor($doc, $admin));
    $response->assertOk();
    expect($response->streamedContent())->toBe('fake-image-bytes');
});

it('streams the file for the owner viewer', function () {
    $user = makeDocUser();
    $doc  = makeStoredDocument($user, 'id');

    $response = $this->get(docUrlFor($doc, $user));
    $response->assertOk();
});

// ══ Signature layer ════════════════════════════════════════════════════════

it('rejects the download when the signature is missing', function () {
    $user = makeDocUser();
    $doc  = makeStoredDocument($user, 'id');

    $this->get("/api/v1/documents/{$doc->id}/download?viewer_id={$user->id}")
        ->assertForbidden();
});

it('rejects the download when the signature has been tampered with', function () {
    $admin = makeDocAdmin();
    $user  = makeDocUser();
    $doc   = makeStoredDocument($user, 'id');

    $tampered = preg_replace('/signature=[^&]+/', 'signature=deadbeef', docUrlFor($doc, $admin));

    $this->get($tampered)->assertForbidden();
});

it('rejects the download when viewer_id is swapped after signing', function () {
    $admin       = makeDocAdmin();
    $otherAdmin  = makeDocAdmin();
    $user        = makeDocUser();
    $doc         = makeStoredDocument($user, 'id');

    // Sign as admin; rewrite viewer_id to someone else — signature must fail.
    $url     = docUrlFor($doc, $admin);
    $swapped = preg_replace('/viewer_id=\d+/', 'viewer_id='.$otherAdmin->id, $url);

    $this->get($swapped)->assertForbidden();
});

// ══ Policy layer (download-time re-verification) ═══════════════════════════

it('rejects the download when a valid signed URL embeds a non-existent viewer', function () {
    $admin = makeDocAdmin();
    $user  = makeDocUser();
    $doc   = makeStoredDocument($user, 'id');

    // Mint a URL for admin, then delete admin — URL signature still valid
    // but the embedded viewer no longer exists.
    $url = docUrlFor($doc, $admin);
    $admin->delete();

    $this->get($url)->assertForbidden();
});

it('rejects the download when the embedded viewer lost admin role after signing', function () {
    $admin = makeDocAdmin();
    $user  = makeDocUser();
    $doc   = makeStoredDocument($user, 'id');

    // Mint a URL while the actor is an admin. Then demote them.
    $url = docUrlFor($doc, $admin);
    $admin->removeRole('admin');

    // Buyer role alone does not grant access to another user's documents.
    $this->get($url)->assertForbidden();
});

it('rejects a URL minted for a non-owner non-admin viewer', function () {
    $stranger = makeDocUser();
    $user     = makeDocUser();
    $doc      = makeStoredDocument($user, 'id');

    // Someone manually constructs a signed URL for an unrelated buyer.
    // The signature is valid (they have APP_KEY in tests), but the policy
    // must reject at download time.
    $url = docUrlFor($doc, $stranger);

    $this->get($url)->assertForbidden();
});

it('allows owner access even when the owner lost their buyer role', function () {
    $user = makeDocUser();
    $doc  = makeStoredDocument($user, 'id');

    // Ownership is by user_id, not by role — role changes should not lock
    // a user out of their own KYC files.
    $user->removeRole('buyer');
    $this->get(docUrlFor($doc, $user))->assertOk();
});

// ══ Mint-time policy layer ═════════════════════════════════════════════════

it('does not emit a URL when the viewer is a non-owner non-admin', function () {
    $stranger = makeDocUser();
    $user     = makeDocUser();
    $doc      = makeStoredDocument($user, 'id');

    $url = \App\Support\SignedFileUrl::userDocument($doc, $stranger);
    expect($url)->toBeNull();
});

it('does not emit a URL when no viewer is supplied', function () {
    $user = makeDocUser();
    $doc  = makeStoredDocument($user, 'id');

    $url = \App\Support\SignedFileUrl::userDocument($doc, null);
    expect($url)->toBeNull();
});

// ══ File-on-disk layer ═════════════════════════════════════════════════════

it('returns 404 when the underlying file is missing from the disk', function () {
    $admin = makeDocAdmin();
    $user  = makeDocUser();
    $doc   = makeStoredDocument($user, 'id');

    Storage::disk('local')->delete($doc->file_path);

    $this->get(docUrlFor($doc, $admin))->assertNotFound();
});
