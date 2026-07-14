<?php

use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Tests\Helpers\CreatesAuctionData;

uses(CreatesAuctionData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Vehicle::hasConditionReport() — the authoritative availability rule ──────

it('reports a condition report as available when a url is on file', function () {
    $vehicle = $this->createVehicle(null, [
        'condition_report_url' => 'https://reports.example.com/cr/123.pdf',
    ]);

    expect($vehicle->hasConditionReport())->toBeTrue();
});

it('reports a condition report as unavailable when the url is null', function () {
    $vehicle = $this->createVehicle(null, ['condition_report_url' => null]);

    expect($vehicle->hasConditionReport())->toBeFalse();
});

it('treats a blank condition report url as unavailable', function () {
    $vehicle = $this->createVehicle(null, ['condition_report_url' => '   ']);

    expect($vehicle->hasConditionReport())->toBeFalse();
});

// ── Lot list / detail expose lightweight report metadata ────────────────────

it('exposes condition report metadata on the public lot list', function () {
    $vehicle = $this->createVehicle(null, [
        'condition_report_url' => 'https://reports.example.com/cr/abc.pdf',
    ]);
    $lot = $this->createLot(null, ['vehicle_id' => $vehicle->id]);

    $this->getJson("/api/v1/auctions/{$lot->auction_id}/lots")
        ->assertOk()
        ->assertJsonPath('data.0.vehicle.has_condition_report', true)
        ->assertJsonPath('data.0.vehicle.condition_report_url', 'https://reports.example.com/cr/abc.pdf');
});

it('flags the report unavailable on a lot whose vehicle has no report', function () {
    $vehicle = $this->createVehicle(null, ['condition_report_url' => null]);
    $lot     = $this->createLot(null, ['vehicle_id' => $vehicle->id]);

    $this->getJson("/api/v1/auctions/{$lot->auction_id}/lots")
        ->assertOk()
        ->assertJsonPath('data.0.vehicle.has_condition_report', false)
        ->assertJsonPath('data.0.vehicle.condition_report_url', null);
});

it('exposes condition report metadata on the single lot endpoint', function () {
    $vehicle = $this->createVehicle(null, [
        'condition_report_url' => 'https://reports.example.com/cr/single.pdf',
    ]);
    $lot = $this->createLot(null, ['vehicle_id' => $vehicle->id]);

    $this->getJson("/api/v1/auctions/{$lot->auction_id}/lots/{$lot->id}")
        ->assertOk()
        ->assertJsonPath('data.vehicle.has_condition_report', true)
        ->assertJsonPath('data.vehicle.condition_report_url', 'https://reports.example.com/cr/single.pdf');
});

it('exposes condition report metadata to an authenticated bidder', function () {
    $buyer = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $buyer->assignRole('buyer');

    $vehicle = $this->createVehicle(null, [
        'condition_report_url' => 'https://reports.example.com/cr/auth.pdf',
    ]);
    $lot = $this->createLot(null, ['vehicle_id' => $vehicle->id]);

    $this->actingAs($buyer)
        ->getJson("/api/v1/auctions/{$lot->auction_id}/lots/{$lot->id}")
        ->assertOk()
        ->assertJsonPath('data.vehicle.has_condition_report', true);
});

// ── Public vehicle resource keeps the same flag ─────────────────────────────

it('exposes the availability flag on the public vehicle resource', function () {
    $seller = User::factory()->create(['status' => 'active']);
    $vehicle = Vehicle::factory()->create([
        'seller_id'            => $seller->id,
        'status'               => 'available',
        'condition_report_url' => 'https://reports.example.com/cr/pub.pdf',
    ]);

    $this->getJson("/api/v1/vehicles/{$vehicle->id}")
        ->assertOk()
        ->assertJsonPath('data.has_condition_report', true);
});
