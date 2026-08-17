<?php

/**
 * Regression cover for the vehicle media upload failures reported from the yard.
 *
 * Phone photos were rejected with "The given data was invalid." because php.ini's
 * upload_max_filesize sat at the 2 MB default while the UI advertised 50 MB. PHP
 * discarded the file without failing the request, so Laravel's `file` rule failed
 * and the only visible symptom was a validation error blaming the user's data.
 *
 * These tests pin the two halves of the fix: the ceilings that application code
 * enforces, and the messages a yard operator actually reads.
 */

use App\Models\User;
use App\Models\Vehicle;
use App\Support\MediaUploadLimits;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('public');
});

function makeMediaAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    return $admin;
}

function uploadTo(Vehicle $vehicle, array $files, User $admin)
{
    return test()->actingAs($admin)
        ->postJson("/api/v1/admin/vehicles/{$vehicle->id}/media", ['files' => $files]);
}

/**
 * First user-facing error message from an upload response.
 *
 * Validation failures key on the literal string "files.0", which dot-notation
 * lookups cannot address, and storage failures key on "files" — so flatten
 * whatever shape came back rather than guessing at a path.
 */
function firstUploadError($response): string
{
    $errors = $response->json('errors') ?? [];
    $flat   = [];

    array_walk_recursive($errors, function ($message) use (&$flat) {
        $flat[] = $message;
    });

    return $flat[0] ?? '';
}

/** A fake photo with real image bytes (the thumb conversion decodes them) but a reported size we choose. */
function fakePhoto(string $name, int $kilobytes)
{
    return UploadedFile::fake()->image($name, 800, 600)->size($kilobytes);
}

// ── The reported bug ──────────────────────────────────────────────────────

it('accepts a phone-sized photo well above the old 2 MB server ceiling', function () {
    $admin   = makeMediaAdmin();
    $vehicle = Vehicle::factory()->create();

    // 5 MB — a perfectly ordinary phone photo, and the exact case that failed.
    $file = fakePhoto('IMG_4021.jpg', 5 * 1024);

    uploadTo($vehicle, [$file], $admin)
        ->assertStatus(201)
        ->assertJsonPath('success', true);
});

it('explains that the server limit is at fault when PHP discards an oversized file', function () {
    $admin   = makeMediaAdmin();
    $vehicle = Vehicle::factory()->create();

    // Exactly what PHP hands Laravel when a file exceeds upload_max_filesize:
    // present in the request, but empty and flagged. This produced the opaque
    // "The files.0 failed to upload." that sent the yard chasing file formats.
    $discarded = new UploadedFile(
        __FILE__,
        'IMG_4021.jpg',
        'image/jpeg',
        UPLOAD_ERR_INI_SIZE,
        true,
    );

    $response = uploadTo($vehicle, [$discarded], $admin)->assertStatus(422);

    $message = firstUploadError($response);

    expect($message)
        ->toContain('IMG_4021.jpg')                 // names the file
        ->toContain('server')                       // blames the server, not the user
        ->not->toContain('failed to upload.');      // not the old opaque wording
});

// ── Per-type ceilings ─────────────────────────────────────────────────────

it('allows a video above the image ceiling but below the video ceiling', function () {
    $admin   = makeMediaAdmin();
    $vehicle = Vehicle::factory()->create();

    // 120 MB: over the 50 MB image limit, well under the 250 MB video limit.
    // A 90-second 1080p walkaround lands in exactly this range.
    $file = UploadedFile::fake()->create('walkaround.mov', 120 * 1024, 'video/quicktime');

    uploadTo($vehicle, [$file], $admin)
        ->assertStatus(201)
        ->assertJsonPath('data.uploaded.0.type', 'video');
});

it('rejects an image above the image ceiling, naming the file and its real size', function () {
    $admin   = makeMediaAdmin();
    $vehicle = Vehicle::factory()->create();

    $file = fakePhoto('huge.jpg', 60 * 1024);

    $message = firstUploadError(uploadTo($vehicle, [$file], $admin)->assertStatus(422));

    expect($message)
        ->toContain('huge.jpg')
        ->toContain('60 MB')     // the size they actually sent
        ->toContain('50 MB')     // the limit they broke
        ->toContain('photo');    // in their words, not "mimetypes"
});

it('rejects a video above the video ceiling', function () {
    $admin   = makeMediaAdmin();
    $vehicle = Vehicle::factory()->create();

    $file = UploadedFile::fake()->create('long.mov', 300 * 1024, 'video/quicktime');

    $message = firstUploadError(uploadTo($vehicle, [$file], $admin)->assertStatus(422));

    expect($message)->toContain('long.mov')->toContain('250 MB')->toContain('video');
});

// ── Formats ───────────────────────────────────────────────────────────────

it('rejects an unsupported format with a message that lists what is accepted', function () {
    $admin   = makeMediaAdmin();
    $vehicle = Vehicle::factory()->create();

    $file = UploadedFile::fake()->create('scan.pdf', 200, 'application/pdf');

    $message = firstUploadError(uploadTo($vehicle, [$file], $admin)->assertStatus(422));

    expect($message)->toContain('scan.pdf')->toContain('JPG, PNG, WebP, MP4, MOV or WebM');
});

it('still accepts every format the yard actually uses', function () {
    $admin   = makeMediaAdmin();
    $vehicle = Vehicle::factory()->create();

    foreach ([
        ['a.jpg',  'image/jpeg'],
        ['b.png',  'image/png'],
        ['c.webp', 'image/webp'],
        ['d.mp4',  'video/mp4'],
        ['e.mov',  'video/quicktime'],
        ['f.webm', 'video/webm'],
    ] as [$name, $mime]) {
        $file = str_starts_with($mime, 'video/')
            ? UploadedFile::fake()->create($name, 1024, $mime)
            : fakePhoto($name, 1024);

        uploadTo($vehicle, [$file], $admin)
            ->assertStatus(201, "expected {$name} ({$mime}) to be accepted");
    }
});

// ── Guards against the limits silently drifting apart again ───────────────

it('keeps media-library max_file_size at or above the largest enforced ceiling', function () {
    // Left at its old 10 MB default this silently overrode the per-type limits
    // and surfaced as "All uploads failed." with no usable explanation.
    expect(config('media-library.max_file_size'))
        ->toBeGreaterThanOrEqual(MediaUploadLimits::VIDEO_MAX_KB * 1024);
});

it('never leaks raw exception text into a user-facing upload error', function () {
    $admin   = makeMediaAdmin();
    $vehicle = Vehicle::factory()->create();

    $file = fakePhoto('broken.jpg', 60 * 1024);

    $message = firstUploadError(uploadTo($vehicle, [$file], $admin));

    // Exception text and stack-trace fragments used to be handed straight to
    // the user by the controller's catch block.
    expect($message)
        ->not->toContain('Exception')
        ->not->toContain('\\')
        ->not->toContain('app/');
});
