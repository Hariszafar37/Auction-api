<?php

use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────

function makeBuyer(): User
{
    $user = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $user->assignRole('buyer');
    return $user;
}

function makeSellerUser(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('buyer');
    return $user;
}

// ── Public inventory list ─────────────────────────────────────────────────

it('returns paginated public inventory', function () {
    $seller = makeSellerUser();
    Vehicle::factory(5)->create(['seller_id' => $seller->id, 'status' => 'available']);
    Vehicle::factory(2)->create(['seller_id' => $seller->id, 'status' => 'in_auction']);
    // These should NOT appear
    Vehicle::factory(1)->create(['seller_id' => $seller->id, 'status' => 'sold']);
    Vehicle::factory(1)->create(['seller_id' => $seller->id, 'status' => 'withdrawn']);

    $buyer = makeBuyer();

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/vehicles');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [['id', 'vin', 'year', 'make', 'model', 'status', 'media', 'active_lot']],
            'meta' => ['total', 'current_page', 'last_page', 'per_page'],
        ]);

    // 5 available + 2 in_auction = 7 visible; sold and withdrawn excluded
    expect($response->json('meta.total'))->toBe(7);
});

it('does not expose sold or withdrawn vehicles in public list', function () {
    $seller = makeSellerUser();
    Vehicle::factory(3)->create(['seller_id' => $seller->id, 'status' => 'available']);
    Vehicle::factory(2)->create(['seller_id' => $seller->id, 'status' => 'sold']);
    Vehicle::factory(2)->create(['seller_id' => $seller->id, 'status' => 'withdrawn']);

    $response = $this->actingAs(makeBuyer(), 'sanctum')
        ->getJson('/api/v1/vehicles');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(3);
});

it('returns empty media array when vehicle has no media', function () {
    $seller = makeSellerUser();
    Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available']);

    $response = $this->actingAs(makeBuyer(), 'sanctum')
        ->getJson('/api/v1/vehicles');

    $response->assertStatus(200);

    $media = $response->json('data.0.media');
    expect($media)->toBeArray()->toBeEmpty();
});

it('does not error when no vehicles exist', function () {
    $response = $this->actingAs(makeBuyer(), 'sanctum')
        ->getJson('/api/v1/vehicles');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(0);
    expect($response->json('data'))->toBeArray()->toBeEmpty();
});

// ── Filters ───────────────────────────────────────────────────────────────

it('filters by make', function () {
    $seller = makeSellerUser();
    Vehicle::factory(3)->create(['seller_id' => $seller->id, 'status' => 'available', 'make' => 'Toyota']);
    Vehicle::factory(2)->create(['seller_id' => $seller->id, 'status' => 'available', 'make' => 'Honda']);

    $response = $this->actingAs(makeBuyer(), 'sanctum')
        ->getJson('/api/v1/vehicles?make=Toyota');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(3);

    collect($response->json('data'))->each(fn ($v) => expect($v['make'])->toBe('Toyota'));
});

it('filters by model', function () {
    $seller = makeSellerUser();
    Vehicle::factory(2)->create(['seller_id' => $seller->id, 'status' => 'available', 'make' => 'Toyota', 'model' => 'Camry']);
    Vehicle::factory(3)->create(['seller_id' => $seller->id, 'status' => 'available', 'make' => 'Toyota', 'model' => 'Corolla']);

    $response = $this->actingAs(makeBuyer(), 'sanctum')
        ->getJson('/api/v1/vehicles?model=Camry');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(2);
});

it('filters by body type', function () {
    $seller = makeSellerUser();
    Vehicle::factory(2)->create(['seller_id' => $seller->id, 'status' => 'available', 'body_type' => 'truck']);
    Vehicle::factory(3)->create(['seller_id' => $seller->id, 'status' => 'available', 'body_type' => 'suv']);

    $response = $this->actingAs(makeBuyer(), 'sanctum')
        ->getJson('/api/v1/vehicles?body_type=truck');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(2);
});

it('filters by year range', function () {
    $seller = makeSellerUser();
    Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available', 'year' => 2018]);
    Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available', 'year' => 2020]);
    Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available', 'year' => 2023]);

    $response = $this->actingAs(makeBuyer(), 'sanctum')
        ->getJson('/api/v1/vehicles?year_min=2019&year_max=2022');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.year'))->toBe(2020);
});

it('filters by max mileage', function () {
    $seller = makeSellerUser();
    Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available', 'mileage' => 10000]);
    Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available', 'mileage' => 50000]);
    Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available', 'mileage' => 100000]);

    $response = $this->actingAs(makeBuyer(), 'sanctum')
        ->getJson('/api/v1/vehicles?mileage_max=50000');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(2);
});

it('searches by keyword across vin, make, model', function () {
    $seller = makeSellerUser();
    Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available', 'vin' => 'ABC12345678901234', 'make' => 'Ford', 'model' => 'F-150']);
    Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available', 'make' => 'Toyota', 'model' => 'Camry']);
    Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available', 'make' => 'Honda', 'model' => 'Civic']);

    // Search by make
    $r1 = $this->actingAs(makeBuyer(), 'sanctum')->getJson('/api/v1/vehicles?search=Toyota');
    expect($r1->json('meta.total'))->toBe(1);

    // Search by model
    $r2 = $this->actingAs(makeBuyer(), 'sanctum')->getJson('/api/v1/vehicles?search=Camry');
    expect($r2->json('meta.total'))->toBe(1);

    // Search by VIN substring
    $r3 = $this->actingAs(makeBuyer(), 'sanctum')->getJson('/api/v1/vehicles?search=ABC');
    expect($r3->json('meta.total'))->toBe(1);
});

it('filters by available status override', function () {
    $seller = makeSellerUser();
    Vehicle::factory(3)->create(['seller_id' => $seller->id, 'status' => 'available']);
    Vehicle::factory(2)->create(['seller_id' => $seller->id, 'status' => 'in_auction']);

    $response = $this->actingAs(makeBuyer(), 'sanctum')
        ->getJson('/api/v1/vehicles?status=available');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(3);
});

// ── Vehicle detail ────────────────────────────────────────────────────────

it('returns vehicle detail for publicly visible vehicle', function () {
    $seller = makeSellerUser();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available']);

    $response = $this->actingAs(makeBuyer(), 'sanctum')
        ->getJson("/api/v1/vehicles/{$vehicle->id}");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'id', 'vin', 'year', 'make', 'model', 'status',
                'media', 'active_lot', 'condition_light', 'has_title',
            ],
        ]);

    expect($response->json('data.id'))->toBe($vehicle->id);
});

it('returns 404 for sold vehicle', function () {
    $seller = makeSellerUser();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'sold']);

    $this->actingAs(makeBuyer(), 'sanctum')
        ->getJson("/api/v1/vehicles/{$vehicle->id}")
        ->assertStatus(404);
});

it('returns 404 for withdrawn vehicle', function () {
    $seller = makeSellerUser();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'withdrawn']);

    $this->actingAs(makeBuyer(), 'sanctum')
        ->getJson("/api/v1/vehicles/{$vehicle->id}")
        ->assertStatus(404);
});

it('returns empty media array on detail when no media uploaded', function () {
    $seller = makeSellerUser();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available']);

    $response = $this->actingAs(makeBuyer(), 'sanctum')
        ->getJson("/api/v1/vehicles/{$vehicle->id}");

    $response->assertStatus(200);
    expect($response->json('data.media'))->toBeArray()->toBeEmpty();
});

it('detail does not expose seller identity', function () {
    $seller = makeSellerUser();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available']);

    $response = $this->actingAs(makeBuyer(), 'sanctum')
        ->getJson("/api/v1/vehicles/{$vehicle->id}");

    $data = $response->json('data');
    expect($data)->not->toHaveKey('seller');
    expect($data)->not->toHaveKey('seller_id');
});

// ── Notify Me ────────────────────────────────────────────────────────────

it('allows buyer to subscribe to vehicle notifications', function () {
    $seller  = makeSellerUser();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available']);
    $buyer   = makeBuyer();

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/vehicles/{$vehicle->id}/notify");

    $response->assertStatus(200);
    $this->assertDatabaseHas('vehicle_notification_subscriptions', [
        'vehicle_id' => $vehicle->id,
        'user_id'    => $buyer->id,
    ]);
});

it('is idempotent — duplicate notify request does not create duplicate', function () {
    $seller  = makeSellerUser();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'available']);
    $buyer   = makeBuyer();

    $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/vehicles/{$vehicle->id}/notify");
    $response = $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/vehicles/{$vehicle->id}/notify");

    $response->assertStatus(200);
    $this->assertDatabaseCount('vehicle_notification_subscriptions', 1);
});

it('rejects notify for in_auction vehicle', function () {
    $seller  = makeSellerUser();
    $vehicle = Vehicle::factory()->create(['seller_id' => $seller->id, 'status' => 'in_auction']);
    $buyer   = makeBuyer();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/vehicles/{$vehicle->id}/notify")
        ->assertStatus(422);
});

it('requires authentication for inventory', function () {
    $this->getJson('/api/v1/vehicles')->assertStatus(401);
});
