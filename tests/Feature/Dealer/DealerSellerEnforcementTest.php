<?php

/**
 * Backend enforcement of "active seller" gate on the /my/vehicles surface.
 *
 * Frontend already hides these actions for pending-approval users, but the API
 * must block them independently so an authenticated dealer/business at
 * status='pending_activation' cannot bypass the UI by hitting endpoints
 * directly. Active, approved dealers and individual sellers must continue to
 * work unchanged. Buyers without seller intent are caught earlier by the
 * permission:inventory.create middleware (tested here for completeness).
 */

use App\Models\DealerProfile;
use App\Models\PowerOfAttorney;
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

function makePendingDealer(): User
{
    // Dealer who has completed activation wizard but not yet been approved by admin.
    // Role is already assigned (matches ActivationController::complete behaviour),
    // but status is pending_activation so seller actions must be blocked.
    $dealer = User::factory()->create([
        'status'                  => 'pending_activation',
        'account_type'            => 'dealer',
        'activation_completed_at' => now(),
    ]);
    $dealer->assignRole('dealer');
    DealerProfile::create([
        'user_id'         => $dealer->id,
        'company_name'    => 'Pending Motors',
        'dealer_license'  => 'DLR-' . rand(100, 999),
        'approval_status' => 'pending',
    ]);
    return $dealer;
}

function makeActiveDealer(): User
{
    $dealer = User::factory()->create([
        'status'                  => 'active',
        'account_type'            => 'dealer',
        'activation_completed_at' => now(),
    ]);
    $dealer->assignRole('dealer');
    DealerProfile::create([
        'user_id'            => $dealer->id,
        'company_name'       => 'Active Motors',
        'dealer_license'     => 'DLR-' . rand(100, 999),
        'packet_accepted_at' => now(),
        'approval_status'    => 'approved',
    ]);
    return $dealer;
}

function validVehiclePayload(array $overrides = []): array
{
    return array_merge([
        'vin'             => 'JT2BF22K1W' . rand(1000000, 9999999),
        'year'            => 2021,
        'make'            => 'Toyota',
        'model'           => 'Camry',
        'body_type'       => 'car',
        'condition_light' => 'green',
    ], $overrides);
}

// ── Pending dealer: all write surfaces blocked ────────────────────────────

it('pending-approval dealer cannot create a vehicle', function () {
    $dealer = makePendingDealer();

    $response = $this->actingAs($dealer, 'sanctum')
        ->postJson('/api/v1/my/vehicles', validVehiclePayload());

    $response->assertStatus(403)
        ->assertJsonPath('code', 'seller_inactive')
        ->assertJsonPath('success', false);

    $this->assertDatabaseMissing('vehicles', ['seller_id' => $dealer->id]);
});

it('pending-approval dealer cannot submit a vehicle to auction', function () {
    $dealer  = makePendingDealer();
    $vehicle = Vehicle::factory()->create([
        'seller_id' => $dealer->id,
        'status'    => 'available',
    ]);

    $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'   => 1,
            'starting_bid' => 1000,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'seller_inactive');
});

it('pending-approval dealer cannot upload vehicle media', function () {
    $dealer  = makePendingDealer();
    $vehicle = Vehicle::factory()->create(['seller_id' => $dealer->id]);

    $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/media", [
            'files' => [UploadedFile::fake()->image('photo.jpg', 400, 300)],
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'seller_inactive');
});

it('pending-approval dealer cannot reorder vehicle media', function () {
    $dealer  = makePendingDealer();
    $vehicle = Vehicle::factory()->create(['seller_id' => $dealer->id]);

    // Seed a valid media row so FormRequest validation passes and we reach the
    // controller gate. `addMedia` needs the model saved — it does not care
    // about the seller's status.
    $media = $vehicle
        ->addMedia(UploadedFile::fake()->image('reorder.jpg'))
        ->toMediaCollection('images');

    $this->actingAs($dealer, 'sanctum')
        ->patchJson("/api/v1/my/vehicles/{$vehicle->id}/media/reorder", [
            'ids' => [$media->id],
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'seller_inactive');
});

it('pending-approval dealer cannot delete vehicle media', function () {
    $dealer  = makePendingDealer();
    $vehicle = Vehicle::factory()->create(['seller_id' => $dealer->id]);

    // Attach a fake media row directly — we just need a numeric id to hit the route.
    $media = $vehicle
        ->addMedia(UploadedFile::fake()->image('seed.jpg'))
        ->toMediaCollection('images');

    // Flip status back to pending_activation AFTER seeding (addMedia under an
    // active dealer isn't necessary — factory user created fresh).
    $dealer->update(['status' => 'pending_activation']);

    $this->actingAs($dealer, 'sanctum')
        ->deleteJson("/api/v1/my/vehicles/{$vehicle->id}/media/{$media->id}")
        ->assertStatus(403)
        ->assertJsonPath('code', 'seller_inactive');
});

// ── Pending dealer: reads still allowed ───────────────────────────────────

it('pending-approval dealer can still list their vehicles', function () {
    $dealer = makePendingDealer();
    Vehicle::factory(2)->create(['seller_id' => $dealer->id]);

    $this->actingAs($dealer, 'sanctum')
        ->getJson('/api/v1/my/vehicles')
        ->assertStatus(200);
});

it('pending-approval dealer can still view a single owned vehicle', function () {
    $dealer  = makePendingDealer();
    $vehicle = Vehicle::factory()->create(['seller_id' => $dealer->id]);

    $this->actingAs($dealer, 'sanctum')
        ->getJson("/api/v1/my/vehicles/{$vehicle->id}")
        ->assertStatus(200);
});

// ── Active dealer: baseline unchanged ─────────────────────────────────────

it('active approved dealer can still create a vehicle', function () {
    $dealer = makeActiveDealer();

    $this->actingAs($dealer, 'sanctum')
        ->postJson('/api/v1/my/vehicles', validVehiclePayload(['vin' => 'JT2BF22K1W3000001']))
        ->assertStatus(201)
        ->assertJsonPath('data.vin', 'JT2BF22K1W3000001');
});

it('active approved dealer can still upload media', function () {
    $dealer  = makeActiveDealer();
    $vehicle = Vehicle::factory()->create(['seller_id' => $dealer->id]);

    $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/media", [
            'files' => [UploadedFile::fake()->image('photo.jpg', 400, 300)],
        ])
        ->assertStatus(201);
});

// ── Individual buyer without seller role: blocked by middleware earlier ──

it('individual buyer without seller role is rejected by permission middleware', function () {
    $buyer = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $buyer->assignRole('buyer');

    // permission:inventory.create middleware fires before our canPerformSellerActions() check
    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/my/vehicles', validVehiclePayload())
        ->assertStatus(403);
});
