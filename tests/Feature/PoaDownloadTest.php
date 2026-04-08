<?php

use App\Models\PowerOfAttorney;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
    Storage::fake('public');
});

function makePoaDownloadAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    return $admin;
}

function makePoaDownloadUser(): User
{
    $user = User::factory()->create([
        'status'       => 'active',
        'account_type' => 'individual',
    ]);
    $user->assignRole('buyer');
    return $user;
}

function makeStoredPoa(User $user, string $disk = 'local'): PowerOfAttorney
{
    $path = "poa/{$user->id}/signed.pdf";
    Storage::disk($disk)->put($path, '%PDF-fake-bytes%');

    return PowerOfAttorney::create([
        'user_id'   => $user->id,
        'type'      => 'upload',
        'status'    => 'signed',
        'file_path' => $path,
        'disk'      => $disk,
    ]);
}

function poaUrlFor(PowerOfAttorney $poa, User $viewer, int $ttl = 5): string
{
    return URL::temporarySignedRoute(
        'poa.download',
        now()->addMinutes($ttl),
        ['poa' => $poa->id, 'viewer_id' => $viewer->id]
    );
}

// ══ Upload path persists disk ══════════════════════════════════════════════

it('stores the disk name on the POA row when a file is uploaded', function () {
    $user = makePoaDownloadUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/activation/poa/upload', [
            'file' => UploadedFile::fake()->create('poa.pdf', 500, 'application/pdf'),
        ])
        ->assertStatus(201);

    $poa = PowerOfAttorney::where('user_id', $user->id)->firstOrFail();
    expect($poa->disk)->toBe(config('filesystems.default'));
    expect($poa->file_path)->toBeString();
});

// ══ Admin POA queue URL emission ═══════════════════════════════════════════

it('admin POA queue returns a signed absolute download URL for uploads', function () {
    $admin = makePoaDownloadAdmin();
    $user  = makePoaDownloadUser();
    $poa   = makeStoredPoa($user);

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/poa?status=signed')
        ->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $poa->id);
    expect($row)->not->toBeNull();
    expect($row['file_url'])->toStartWith(config('app.url'));
    expect($row['file_url'])->toContain("/poa/{$poa->id}/download");
    expect($row['file_url'])->toContain('signature=');
    expect($row['file_url'])->toContain('expires=');
    expect($row['file_url'])->toContain('viewer_id='.$admin->id);
});

it('admin POA queue returns null file_url for e-signed POAs', function () {
    $admin = makePoaDownloadAdmin();
    $user  = makePoaDownloadUser();

    PowerOfAttorney::create([
        'user_id'             => $user->id,
        'type'                => 'esign',
        'status'              => 'signed',
        'signer_printed_name' => 'Jane Doe',
    ]);

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/poa?status=signed')
        ->assertOk();

    expect($response->json('data.0.file_url'))->toBeNull();
});

it('my POA endpoint returns a signed file_url for the uploading user', function () {
    $user = makePoaDownloadUser();
    $poa  = makeStoredPoa($user);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/my/poa')
        ->assertOk();

    expect($response->json('data.file_url'))->toStartWith(config('app.url'));
    expect($response->json('data.file_url'))->toContain("/poa/{$poa->id}/download");
    expect($response->json('data.file_url'))->toContain('viewer_id='.$user->id);
});

// ══ Signed download route — happy path ════════════════════════════════════

it('streams the POA file for an admin viewer', function () {
    $admin = makePoaDownloadAdmin();
    $user  = makePoaDownloadUser();
    $poa   = makeStoredPoa($user);

    $response = $this->get(poaUrlFor($poa, $admin));
    $response->assertOk();
    expect($response->streamedContent())->toBe('%PDF-fake-bytes%');
});

it('streams the POA file for the owner viewer', function () {
    $user = makePoaDownloadUser();
    $poa  = makeStoredPoa($user);

    $this->get(poaUrlFor($poa, $user))->assertOk();
});

// ══ Signature layer ═══════════════════════════════════════════════════════

it('rejects the POA download when the signature is missing', function () {
    $user = makePoaDownloadUser();
    $poa  = makeStoredPoa($user);

    $this->get("/api/v1/poa/{$poa->id}/download?viewer_id={$user->id}")
        ->assertForbidden();
});

it('rejects the POA download when the signature is tampered with', function () {
    $admin = makePoaDownloadAdmin();
    $user  = makePoaDownloadUser();
    $poa   = makeStoredPoa($user);

    $tampered = preg_replace('/signature=[^&]+/', 'signature=deadbeef', poaUrlFor($poa, $admin));

    $this->get($tampered)->assertForbidden();
});

it('rejects the POA download when viewer_id is swapped after signing', function () {
    $admin      = makePoaDownloadAdmin();
    $otherAdmin = makePoaDownloadAdmin();
    $user       = makePoaDownloadUser();
    $poa        = makeStoredPoa($user);

    $swapped = preg_replace(
        '/viewer_id=\d+/',
        'viewer_id='.$otherAdmin->id,
        poaUrlFor($poa, $admin)
    );

    $this->get($swapped)->assertForbidden();
});

// ══ Policy layer — download-time re-verification ══════════════════════════

it('rejects the POA download when the embedded viewer has been deleted', function () {
    $admin = makePoaDownloadAdmin();
    $user  = makePoaDownloadUser();
    $poa   = makeStoredPoa($user);

    $url = poaUrlFor($poa, $admin);
    $admin->delete();

    $this->get($url)->assertForbidden();
});

it('rejects the POA download when the embedded viewer lost admin role after signing', function () {
    $admin = makePoaDownloadAdmin();
    $user  = makePoaDownloadUser();
    $poa   = makeStoredPoa($user);

    $url = poaUrlFor($poa, $admin);
    $admin->removeRole('admin');

    $this->get($url)->assertForbidden();
});

it('rejects a POA URL minted for a non-owner non-admin viewer', function () {
    $stranger = makePoaDownloadUser();
    $user     = makePoaDownloadUser();
    $poa      = makeStoredPoa($user);

    $this->get(poaUrlFor($poa, $stranger))->assertForbidden();
});

it('allows POA access for the owner even without the buyer role', function () {
    $user = makePoaDownloadUser();
    $poa  = makeStoredPoa($user);

    $user->removeRole('buyer');
    $this->get(poaUrlFor($poa, $user))->assertOk();
});

// ══ Mint-time policy layer ════════════════════════════════════════════════

it('does not emit a POA URL when the viewer is a non-owner non-admin', function () {
    $stranger = makePoaDownloadUser();
    $user     = makePoaDownloadUser();
    $poa      = makeStoredPoa($user);

    $url = \App\Support\SignedFileUrl::powerOfAttorney($poa, $stranger);
    expect($url)->toBeNull();
});

it('does not emit a POA URL when no viewer is supplied', function () {
    $user = makePoaDownloadUser();
    $poa  = makeStoredPoa($user);

    expect(\App\Support\SignedFileUrl::powerOfAttorney($poa, null))->toBeNull();
});

// ══ File / row state ══════════════════════════════════════════════════════

it('returns 404 when the underlying POA file is missing from the disk', function () {
    $admin = makePoaDownloadAdmin();
    $user  = makePoaDownloadUser();
    $poa   = makeStoredPoa($user);

    Storage::disk('local')->delete($poa->file_path);

    $this->get(poaUrlFor($poa, $admin))->assertNotFound();
});

it('returns 404 when the POA row has no file (e-sign record)', function () {
    $admin = makePoaDownloadAdmin();
    $user  = makePoaDownloadUser();

    $poa = PowerOfAttorney::create([
        'user_id'             => $user->id,
        'type'                => 'esign',
        'status'              => 'signed',
        'signer_printed_name' => 'Jane Doe',
    ]);

    $this->get(poaUrlFor($poa, $admin))->assertNotFound();
});
