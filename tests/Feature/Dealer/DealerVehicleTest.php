<?php

use App\Models\DealerProfile;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function makeDealerUser(): User
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

function makeBuyerForDealer(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('buyer');
    return $user;
}

// ── List ──────────────────────────────────────────────────────────────────

it('dealer can list their own vehicles', function () {
    $dealer = makeDealerUser();
    Vehicle::factory(4)->create(['seller_id' => $dealer->id]);

    // Another dealer's vehicles — should not appear
    $other = makeDealerUser();
    Vehicle::factory(2)->create(['seller_id' => $other->id]);

    $response = $this->actingAs($dealer, 'sanctum')
        ->getJson('/api/v1/my/vehicles');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [['id', 'vin', 'make', 'model', 'status', 'media']],
            'meta' => ['total'],
        ]);

    expect($response->json('meta.total'))->toBe(4);
});

it('dealer vehicle list returns media array', function () {
    $dealer = makeDealerUser();
    Vehicle::factory()->create(['seller_id' => $dealer->id]);

    $response = $this->actingAs($dealer, 'sanctum')
        ->getJson('/api/v1/my/vehicles');

    $response->assertStatus(200);
    $media = $response->json('data.0.media');
    expect($media)->toBeArray();
});

it('dealer can filter own vehicles by status', function () {
    $dealer = makeDealerUser();
    Vehicle::factory(3)->create(['seller_id' => $dealer->id, 'status' => 'available']);
    Vehicle::factory(1)->create(['seller_id' => $dealer->id, 'status' => 'sold']);

    $response = $this->actingAs($dealer, 'sanctum')
        ->getJson('/api/v1/my/vehicles?status=available');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(3);
});

it('dealer can search own vehicles by keyword', function () {
    $dealer = makeDealerUser();
    Vehicle::factory()->create(['seller_id' => $dealer->id, 'make' => 'Toyota', 'model' => 'Camry']);
    Vehicle::factory()->create(['seller_id' => $dealer->id, 'make' => 'Honda', 'model' => 'Civic']);

    $response = $this->actingAs($dealer, 'sanctum')
        ->getJson('/api/v1/my/vehicles?search=Toyota');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.make'))->toBe('Toyota');
});

it('non-dealer cannot access dealer vehicle endpoint', function () {
    $buyer = makeBuyerForDealer();

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/my/vehicles')
        ->assertStatus(403);
});

it('unauthenticated user cannot access dealer vehicles', function () {
    $this->getJson('/api/v1/my/vehicles')->assertStatus(401);
});

// ── Create ────────────────────────────────────────────────────────────────

it('dealer can create a vehicle', function () {
    $dealer = makeDealerUser();

    $payload = [
        'vin'             => 'JT2BF22K1W9876543',
        'year'            => 2021,
        'make'            => 'Honda',
        'model'           => 'Accord',
        'body_type'       => 'car',
        'condition_light' => 'green',
        'has_title'       => true,
    ];

    $response = $this->actingAs($dealer, 'sanctum')
        ->postJson('/api/v1/my/vehicles', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.vin', 'JT2BF22K1W9876543')
        ->assertJsonPath('data.status', 'available');

    $this->assertDatabaseHas('vehicles', [
        'vin'       => 'JT2BF22K1W9876543',
        'seller_id' => $dealer->id,
    ]);
});

it('dealer create sets seller_id from authenticated user, not payload', function () {
    $dealer = makeDealerUser();
    $other  = makeDealerUser();

    $payload = [
        'seller_id'       => $other->id, // should be ignored
        'vin'             => 'JT2BF22K1W1111111',
        'year'            => 2021,
        'make'            => 'Ford',
        'model'           => 'Mustang',
        'body_type'       => 'car',
        'condition_light' => 'green',
    ];

    $this->actingAs($dealer, 'sanctum')
        ->postJson('/api/v1/my/vehicles', $payload)
        ->assertStatus(201);

    $this->assertDatabaseHas('vehicles', [
        'vin'       => 'JT2BF22K1W1111111',
        'seller_id' => $dealer->id, // dealer's own ID, not $other->id
    ]);
});

it('dealer create validates required fields', function () {
    $dealer = makeDealerUser();

    $this->actingAs($dealer, 'sanctum')
        ->postJson('/api/v1/my/vehicles', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['vin', 'year', 'make', 'model', 'body_type', 'condition_light']);
});

it('dealer create rejects duplicate VIN', function () {
    $dealer = makeDealerUser();
    Vehicle::factory()->create(['seller_id' => $dealer->id, 'vin' => 'JT2BF22K1W2222222']);

    $this->actingAs($dealer, 'sanctum')
        ->postJson('/api/v1/my/vehicles', [
            'vin'             => 'JT2BF22K1W2222222',
            'year'            => 2021,
            'make'            => 'Ford',
            'model'           => 'F-150',
            'body_type'       => 'truck',
            'condition_light' => 'green',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['vin']);
});

// ── Show ──────────────────────────────────────────────────────────────────

it('dealer can view their own vehicle', function () {
    $dealer  = makeDealerUser();
    $vehicle = Vehicle::factory()->create(['seller_id' => $dealer->id]);

    $response = $this->actingAs($dealer, 'sanctum')
        ->getJson("/api/v1/my/vehicles/{$vehicle->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $vehicle->id);
});

it('dealer cannot view another dealer vehicle', function () {
    $dealer = makeDealerUser();
    $other  = makeDealerUser();
    $vehicle = Vehicle::factory()->create(['seller_id' => $other->id]);

    $this->actingAs($dealer, 'sanctum')
        ->getJson("/api/v1/my/vehicles/{$vehicle->id}")
        ->assertStatus(404);
});

// ── Regression ────────────────────────────────────────────────────────────

it('dealer inventory does not throw withAllMedia error', function () {
    $dealer = makeDealerUser();
    Vehicle::factory(3)->create(['seller_id' => $dealer->id]);

    // If withAllMedia() was still present this would 500; expect 200
    $this->actingAs($dealer, 'sanctum')
        ->getJson('/api/v1/my/vehicles')
        ->assertStatus(200);
});
