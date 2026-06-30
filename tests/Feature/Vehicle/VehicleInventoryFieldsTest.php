<?php

use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seller = User::factory()->create(['status' => 'active']);
    $this->seller->assignRole('buyer');
    $this->buyer = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $this->buyer->assignRole('buyer');
});

// ── Inventory number auto-assignment ────────────────────────────────────────

it('auto-assigns an inventory number when none is provided', function () {
    $vehicle = Vehicle::factory()->create([
        'seller_id'        => $this->seller->id,
        'inventory_number' => null,
    ]);

    expect($vehicle->fresh()->inventory_number)->toBe(sprintf('INV-%05d', $vehicle->id));
});

it('keeps a manually-supplied inventory number', function () {
    $vehicle = Vehicle::factory()->create([
        'seller_id'        => $this->seller->id,
        'inventory_number' => 'CUSTOM-123',
    ]);

    expect($vehicle->fresh()->inventory_number)->toBe('CUSTOM-123');
});

// ── New fields exposed in public resource ───────────────────────────────────

it('exposes the new inventory fields in the public resource', function () {
    Vehicle::factory()->create([
        'seller_id'             => $this->seller->id,
        'status'                => 'available',
        'exterior_color'        => 'Pearl White',
        'interior_color'        => 'Black',
        'interior_seating_type' => 'Leather',
        'odometer_status'       => 'actual',
        'number_of_keys'        => 2,
        'number_of_fobs'        => 1,
        'asset_number'          => 'AST-9001',
    ]);

    $response = $this->actingAs($this->buyer, 'sanctum')->getJson('/api/v1/vehicles');

    $response->assertStatus(200)->assertJsonStructure([
        'data' => [[
            'asset_number', 'inventory_number', 'exterior_color', 'interior_color',
            'interior_seating_type', 'odometer_status', 'number_of_keys', 'number_of_fobs',
        ]],
    ]);

    $row = $response->json('data.0');
    expect($row['exterior_color'])->toBe('Pearl White');
    expect($row['interior_color'])->toBe('Black');
    expect($row['interior_seating_type'])->toBe('Leather');
    expect($row['odometer_status'])->toBe('actual');
    expect($row['number_of_keys'])->toBe(2);
    expect($row['number_of_fobs'])->toBe(1);
});

// ── Multi-select make/model filtering ───────────────────────────────────────

it('filters inventory by multiple makes (array)', function () {
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'make' => 'Toyota']);
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'make' => 'Honda']);
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'make' => 'Ford']);

    $response = $this->actingAs($this->buyer, 'sanctum')
        ->getJson('/api/v1/vehicles?make[]=Toyota&make[]=Honda');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(2);
});

it('filters inventory by multiple models (array)', function () {
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'make' => 'Toyota', 'model' => 'Camry']);
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'make' => 'Toyota', 'model' => 'Corolla']);
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'make' => 'Toyota', 'model' => 'RAV4']);

    $response = $this->actingAs($this->buyer, 'sanctum')
        ->getJson('/api/v1/vehicles?model[]=Camry&model[]=RAV4');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(2);
});

it('still supports single-string make partial match', function () {
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'make' => 'Toyota']);
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'make' => 'Honda']);

    $response = $this->actingAs($this->buyer, 'sanctum')->getJson('/api/v1/vehicles?make=Toy');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(1);
});

// ── Sorting ─────────────────────────────────────────────────────────────────

it('sorts by year ascending and descending', function () {
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'year' => 2018]);
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'year' => 2022]);
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'year' => 2020]);

    $asc = $this->actingAs($this->buyer, 'sanctum')->getJson('/api/v1/vehicles?sort=year_asc');
    expect(array_column($asc->json('data'), 'year'))->toBe([2018, 2020, 2022]);

    $desc = $this->actingAs($this->buyer, 'sanctum')->getJson('/api/v1/vehicles?sort=year_desc');
    expect(array_column($desc->json('data'), 'year'))->toBe([2022, 2020, 2018]);
});

it('sorts by mileage highest and lowest', function () {
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'mileage' => 50000]);
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'mileage' => 10000]);
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'mileage' => 90000]);

    $high = $this->actingAs($this->buyer, 'sanctum')->getJson('/api/v1/vehicles?sort=mileage_desc');
    expect(array_column($high->json('data'), 'mileage'))->toBe([90000, 50000, 10000]);

    $low = $this->actingAs($this->buyer, 'sanctum')->getJson('/api/v1/vehicles?sort=mileage_asc');
    expect(array_column($low->json('data'), 'mileage'))->toBe([10000, 50000, 90000]);
});

// ── Facets endpoint ─────────────────────────────────────────────────────────

it('returns make/model facets for the public inventory', function () {
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'make' => 'Toyota', 'model' => 'Camry']);
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'make' => 'Toyota', 'model' => 'Corolla']);
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'available', 'make' => 'Honda', 'model' => 'Civic']);
    // Hidden vehicles must not appear in facets
    Vehicle::factory()->create(['seller_id' => $this->seller->id, 'status' => 'sold', 'make' => 'Ford', 'model' => 'F-150']);

    $response = $this->actingAs($this->buyer, 'sanctum')->getJson('/api/v1/vehicles/facets');

    $response->assertStatus(200);
    $makes = collect($response->json('data'));

    expect($makes->pluck('make')->all())->toBe(['Honda', 'Toyota']);
    expect($makes->pluck('make')->all())->not->toContain('Ford');

    $toyota = $makes->firstWhere('make', 'Toyota');
    expect($toyota['count'])->toBe(2);
    expect(collect($toyota['models'])->pluck('model')->all())->toBe(['Camry', 'Corolla']);
});
