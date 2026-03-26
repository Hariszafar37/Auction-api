<?php

use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function makeAdminForVehicle(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');
    return $admin;
}

function makeBuyerForVehicle(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('buyer');
    return $user;
}

function makeSeller(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('buyer');
    return $user;
}

// ── List ──────────────────────────────────────────────────────────────────

it('admin can list all vehicles including non-public statuses', function () {
    $seller = makeSeller();
    Vehicle::factory(3)->create(['seller_id' => $seller->id, 'status' => 'available']);
    Vehicle::factory(2)->create(['seller_id' => $seller->id, 'status' => 'sold']);
    Vehicle::factory(1)->create(['seller_id' => $seller->id, 'status' => 'withdrawn']);

    $response = $this->actingAs(makeAdminForVehicle(), 'sanctum')
        ->getJson('/api/v1/admin/vehicles');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [['id', 'vin', 'year', 'make', 'model', 'status', 'seller', 'media']],
            'meta' => ['total', 'current_page', 'last_page', 'per_page'],
        ]);

    expect($response->json('meta.total'))->toBe(6);
});

it('admin vehicle list returns media array', function () {
    $seller = makeSeller();
    Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available']);

    $response = $this->actingAs(makeAdminForVehicle(), 'sanctum')
        ->getJson('/api/v1/admin/vehicles');

    $response->assertStatus(200);
    $media = $response->json('data.0.media');
    expect($media)->toBeArray();
});

it('admin vehicle list includes seller info', function () {
    $seller = makeSeller();
    Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available']);

    $response = $this->actingAs(makeAdminForVehicle(), 'sanctum')
        ->getJson('/api/v1/admin/vehicles');

    $response->assertStatus(200);
    $sellerInfo = $response->json('data.0.seller');
    expect($sellerInfo)->toHaveKey('id');
    expect($sellerInfo)->toHaveKey('name');
    expect($sellerInfo)->toHaveKey('email');
});

it('admin vehicle list filters by status', function () {
    $seller = makeSeller();
    Vehicle::factory(3)->create(['seller_id' => $seller->id, 'status' => 'available']);
    Vehicle::factory(2)->create(['seller_id' => $seller->id, 'status' => 'sold']);

    $response = $this->actingAs(makeAdminForVehicle(), 'sanctum')
        ->getJson('/api/v1/admin/vehicles?status=sold');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(2);
});

it('admin vehicle list filters by search keyword', function () {
    $seller = makeSeller();
    Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available', 'make' => 'Toyota', 'model' => 'Camry']);
    Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available', 'make' => 'Honda', 'model' => 'Civic']);

    $response = $this->actingAs(makeAdminForVehicle(), 'sanctum')
        ->getJson('/api/v1/admin/vehicles?search=Toyota');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.make'))->toBe('Toyota');
});

it('non-admin cannot access admin vehicle list', function () {
    $this->actingAs(makeBuyerForVehicle(), 'sanctum')
        ->getJson('/api/v1/admin/vehicles')
        ->assertStatus(403);
});

it('unauthenticated user cannot access admin vehicle list', function () {
    $this->getJson('/api/v1/admin/vehicles')->assertStatus(401);
});

// ── Create ────────────────────────────────────────────────────────────────

it('admin can create a vehicle', function () {
    $admin  = makeAdminForVehicle();
    $seller = makeSeller();

    $payload = [
        'seller_id'       => $seller->id,
        'vin'             => 'JT2BF22K1W0123456',
        'year'            => 2022,
        'make'            => 'Toyota',
        'model'           => 'Camry',
        'body_type'       => 'car',
        'condition_light' => 'green',
        'has_title'       => true,
    ];

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/vehicles', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.vin', 'JT2BF22K1W0123456')
        ->assertJsonPath('data.make', 'Toyota');

    $this->assertDatabaseHas('vehicles', ['vin' => 'JT2BF22K1W0123456']);
});

it('create validates required fields', function () {
    $admin = makeAdminForVehicle();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/vehicles', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['seller_id', 'vin', 'year', 'make', 'model', 'body_type', 'condition_light']);
});

it('create rejects duplicate VIN', function () {
    $admin  = makeAdminForVehicle();
    $seller = makeSeller();

    Vehicle::factory()->create(['seller_id' => $seller->id, 'vin' => 'JT2BF22K1W0123456']);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/admin/vehicles', [
            'seller_id'       => $seller->id,
            'vin'             => 'JT2BF22K1W0123456',
            'year'            => 2022,
            'make'            => 'Toyota',
            'model'           => 'Camry',
            'body_type'       => 'car',
            'condition_light' => 'green',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['vin']);
});

// ── Show ──────────────────────────────────────────────────────────────────

it('admin can view single vehicle', function () {
    $seller  = makeSeller();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id]);

    $response = $this->actingAs(makeAdminForVehicle(), 'sanctum')
        ->getJson("/api/v1/admin/vehicles/{$vehicle->id}");

    $response->assertStatus(200)
        ->assertJsonStructure(['data' => ['id', 'vin', 'seller', 'media']]);

    expect($response->json('data.id'))->toBe($vehicle->id);
});

// ── Update ────────────────────────────────────────────────────────────────

it('admin can update a vehicle', function () {
    $seller  = makeSeller();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available', 'make' => 'Honda']);

    $response = $this->actingAs(makeAdminForVehicle(), 'sanctum')
        ->patchJson("/api/v1/admin/vehicles/{$vehicle->id}", ['make' => 'Toyota']);

    $response->assertStatus(200)
        ->assertJsonPath('data.make', 'Toyota');

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'make' => 'Toyota']);
});

it('admin cannot update a sold vehicle', function () {
    $seller  = makeSeller();
    $vehicle = Vehicle::factory()->sold()->create(['seller_id' => $seller->id]);

    $this->actingAs(makeAdminForVehicle(), 'sanctum')
        ->patchJson("/api/v1/admin/vehicles/{$vehicle->id}", ['make' => 'Toyota'])
        ->assertStatus(422);
});

// ── Status update ─────────────────────────────────────────────────────────

it('admin can update vehicle status', function () {
    $seller  = makeSeller();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available']);

    $response = $this->actingAs(makeAdminForVehicle(), 'sanctum')
        ->patchJson("/api/v1/admin/vehicles/{$vehicle->id}/status", ['status' => 'withdrawn']);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'withdrawn');

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'withdrawn']);
});

it('status update rejects invalid status values', function () {
    $seller  = makeSeller();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id]);

    $this->actingAs(makeAdminForVehicle(), 'sanctum')
        ->patchJson("/api/v1/admin/vehicles/{$vehicle->id}/status", ['status' => 'invalid'])
        ->assertStatus(422);
});

// ── Delete ────────────────────────────────────────────────────────────────

it('admin can soft-delete an available vehicle', function () {
    $seller  = makeSeller();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available']);

    $this->actingAs(makeAdminForVehicle(), 'sanctum')
        ->deleteJson("/api/v1/admin/vehicles/{$vehicle->id}")
        ->assertStatus(200);

    $this->assertSoftDeleted('vehicles', ['id' => $vehicle->id]);
});

it('admin cannot delete a vehicle that is in auction', function () {
    $seller  = makeSeller();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'in_auction']);

    $this->actingAs(makeAdminForVehicle(), 'sanctum')
        ->deleteJson("/api/v1/admin/vehicles/{$vehicle->id}")
        ->assertStatus(422);
});

// ── CSV Export ────────────────────────────────────────────────────────────

it('admin can export vehicle list as CSV', function () {
    $seller = makeSeller();
    Vehicle::factory(3)->create(['seller_id' => $seller->id]);

    $response = $this->actingAs(makeAdminForVehicle(), 'sanctum')
        ->get('/api/v1/admin/vehicles/export');

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});
