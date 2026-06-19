<?php

use App\Models\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * Covers the optional map coordinates added to locations: admins can set
 * latitude/longitude, they round-trip through the resource, and out-of-range
 * values are rejected.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function adminUser(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    return $admin;
}

it('lets an admin create a location with coordinates and exposes them in the resource', function () {
    $this->actingAs(adminUser(), 'sanctum')
        ->postJson('/api/v1/admin/locations', [
            'name'      => 'Maryland Main Lot',
            'code'      => 'MD-MAIN',
            'city'      => 'Baltimore',
            'state'     => 'MD',
            'latitude'  => 39.2904,
            'longitude' => -76.6122,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.latitude', 39.2904)
        ->assertJsonPath('data.longitude', -76.6122);

    expect(Location::where('code', 'MD-MAIN')->first())
        ->latitude->toEqualWithDelta(39.2904, 0.0001)
        ->longitude->toEqualWithDelta(-76.6122, 0.0001);
});

it('allows a location without coordinates (null)', function () {
    $this->actingAs(adminUser(), 'sanctum')
        ->postJson('/api/v1/admin/locations', [
            'name'  => 'No Coords Lot',
            'code'  => 'NC-1',
            'city'  => 'Annapolis',
            'state' => 'MD',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.latitude', null)
        ->assertJsonPath('data.longitude', null);
});

it('rejects out-of-range coordinates', function () {
    $this->actingAs(adminUser(), 'sanctum')
        ->postJson('/api/v1/admin/locations', [
            'name'      => 'Bad Coords',
            'code'      => 'BAD-1',
            'city'      => 'Baltimore',
            'state'     => 'MD',
            'latitude'  => 120,   // > 90
            'longitude' => -200,  // < -180
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['latitude', 'longitude']);
});

it('lets an admin update coordinates on an existing location', function () {
    $location = Location::create([
        'name'  => 'Update Me',
        'code'  => 'UPD-1',
        'city'  => 'Baltimore',
        'state' => 'MD',
    ]);

    $this->actingAs(adminUser(), 'sanctum')
        ->patchJson("/api/v1/admin/locations/{$location->id}", [
            'latitude'  => 38.9072,
            'longitude' => -77.0369,
        ])
        ->assertOk()
        ->assertJsonPath('data.latitude', 38.9072)
        ->assertJsonPath('data.longitude', -77.0369);
});
