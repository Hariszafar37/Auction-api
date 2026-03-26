<?php

use App\Models\DealerProfile;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('public');
});

// ── Helpers ───────────────────────────────────────────────────────────────

function makeDealerForMedia(): User
{
    $dealer = User::factory()->create(['status' => 'active', 'account_type' => 'dealer']);
    $dealer->assignRole('dealer');
    DealerProfile::create([
        'user_id'            => $dealer->id,
        'company_name'       => 'Test Motors',
        'dealer_license'     => 'DLR-' . rand(100, 999),
        'packet_accepted_at' => now(),
        'approval_status'    => 'approved',
    ]);
    return $dealer;
}

function makeDealerVehicle(User $dealer, array $attrs = []): Vehicle
{
    return Vehicle::factory()->create(array_merge(['seller_id' => $dealer->id], $attrs));
}

// ── Upload ────────────────────────────────────────────────────────────────

it('dealer can upload media to their own vehicle', function () {
    $dealer  = makeDealerForMedia();
    $vehicle = makeDealerVehicle($dealer);

    $file = UploadedFile::fake()->image('photo.jpg', 400, 300);

    $response = $this->actingAs($dealer)
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/media", [
            'files' => [$file],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'uploaded' => [['id', 'type', 'url', 'file_name', 'mime_type', 'size', 'order']],
                'media',
            ],
        ]);

    expect($response->json('data.uploaded'))->toHaveCount(1);
    expect($response->json('data.uploaded.0.type'))->toBe('image');
});

it('dealer cannot upload media to another dealers vehicle', function () {
    $dealer1 = makeDealerForMedia();
    $dealer2 = makeDealerForMedia();
    $vehicle = makeDealerVehicle($dealer1);

    $file = UploadedFile::fake()->image('photo.jpg');

    $this->actingAs($dealer2)
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/media", [
            'files' => [$file],
        ])
        ->assertStatus(404);
});

it('unauthenticated user cannot upload media', function () {
    $dealer  = makeDealerForMedia();
    $vehicle = makeDealerVehicle($dealer);

    $this->postJson("/api/v1/my/vehicles/{$vehicle->id}/media", [
        'files' => [UploadedFile::fake()->image('photo.jpg')],
    ])->assertStatus(401);
});

it('non-dealer role cannot upload media', function () {
    $buyer  = User::factory()->create(['status' => 'active']);
    $buyer->assignRole('buyer');
    $dealer  = makeDealerForMedia();
    $vehicle = makeDealerVehicle($dealer);

    $this->actingAs($buyer)
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/media", [
            'files' => [UploadedFile::fake()->image('photo.jpg')],
        ])
        ->assertStatus(403);
});

it('upload requires at least one file', function () {
    $dealer  = makeDealerForMedia();
    $vehicle = makeDealerVehicle($dealer);

    $this->actingAs($dealer)
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/media", ['files' => []])
        ->assertStatus(422);
});

// ── Delete ────────────────────────────────────────────────────────────────

it('dealer can delete media from their own vehicle', function () {
    $dealer  = makeDealerForMedia();
    $vehicle = makeDealerVehicle($dealer);

    // Upload first
    $file = UploadedFile::fake()->image('delete_me.jpg');
    $uploadRes = $this->actingAs($dealer)
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/media", ['files' => [$file]]);
    $uploadRes->assertStatus(201);

    $mediaId = $uploadRes->json('data.uploaded.0.id');

    $deleteRes = $this->actingAs($dealer)
        ->deleteJson("/api/v1/my/vehicles/{$vehicle->id}/media/{$mediaId}");

    $deleteRes->assertStatus(200)
        ->assertJsonStructure(['data' => ['media']]);

    expect($deleteRes->json('data.media'))->toBeEmpty();
});

it('dealer cannot delete media from another dealers vehicle', function () {
    $dealer1 = makeDealerForMedia();
    $dealer2 = makeDealerForMedia();
    $vehicle = makeDealerVehicle($dealer1);

    $file = UploadedFile::fake()->image('photo.jpg');
    $uploadRes = $this->actingAs($dealer1)
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/media", ['files' => [$file]]);

    $mediaId = $uploadRes->json('data.uploaded.0.id');

    $this->actingAs($dealer2)
        ->deleteJson("/api/v1/my/vehicles/{$vehicle->id}/media/{$mediaId}")
        ->assertStatus(404);
});

it('returns 404 when deleting media that does not belong to vehicle', function () {
    $dealer   = makeDealerForMedia();
    $vehicle1 = makeDealerVehicle($dealer);
    $vehicle2 = makeDealerVehicle($dealer);

    // Upload to vehicle1
    $file = UploadedFile::fake()->image('photo.jpg');
    $uploadRes = $this->actingAs($dealer)
        ->postJson("/api/v1/my/vehicles/{$vehicle1->id}/media", ['files' => [$file]]);

    $mediaId = $uploadRes->json('data.uploaded.0.id');

    // Try to delete via vehicle2
    $this->actingAs($dealer)
        ->deleteJson("/api/v1/my/vehicles/{$vehicle2->id}/media/{$mediaId}")
        ->assertStatus(404);
});

// ── Reorder ───────────────────────────────────────────────────────────────

it('dealer can reorder media on their own vehicle', function () {
    $dealer  = makeDealerForMedia();
    $vehicle = makeDealerVehicle($dealer);

    // Upload two files
    $uploadRes = $this->actingAs($dealer)
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/media", [
            'files' => [
                UploadedFile::fake()->image('first.jpg'),
                UploadedFile::fake()->image('second.jpg'),
            ],
        ]);

    $ids = collect($uploadRes->json('data.uploaded'))->pluck('id')->toArray();

    // Reverse the order
    $reorderRes = $this->actingAs($dealer)
        ->patchJson("/api/v1/my/vehicles/{$vehicle->id}/media/reorder", [
            'ids' => array_reverse($ids),
        ]);

    $reorderRes->assertStatus(200)
        ->assertJsonStructure(['data' => ['media']]);
});

it('reorder rejects media IDs from a different vehicle', function () {
    $dealer   = makeDealerForMedia();
    $vehicle1 = makeDealerVehicle($dealer);
    $vehicle2 = makeDealerVehicle($dealer);

    // Upload to vehicle1
    $uploadRes = $this->actingAs($dealer)
        ->postJson("/api/v1/my/vehicles/{$vehicle1->id}/media", [
            'files' => [UploadedFile::fake()->image('photo.jpg')],
        ]);

    $mediaId = $uploadRes->json('data.uploaded.0.id');

    // Attempt reorder on vehicle2 with vehicle1's media ID
    $this->actingAs($dealer)
        ->patchJson("/api/v1/my/vehicles/{$vehicle2->id}/media/reorder", [
            'ids' => [$mediaId],
        ])
        ->assertStatus(422);
});

it('dealer cannot reorder media on another dealers vehicle', function () {
    $dealer1 = makeDealerForMedia();
    $dealer2 = makeDealerForMedia();
    $vehicle = makeDealerVehicle($dealer1);

    // Upload a real file as dealer1 to get a valid media ID
    $uploadRes = $this->actingAs($dealer1)
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/media", [
            'files' => [UploadedFile::fake()->image('photo.jpg')],
        ]);
    $mediaId = $uploadRes->json('data.uploaded.0.id');

    // dealer2 should not be able to reorder dealer1's vehicle
    $this->actingAs($dealer2)
        ->patchJson("/api/v1/my/vehicles/{$vehicle->id}/media/reorder", [
            'ids' => [$mediaId],
        ])
        ->assertStatus(404);
});
