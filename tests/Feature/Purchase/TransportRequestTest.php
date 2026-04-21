<?php

use App\Models\TransportRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

uses(\Tests\Helpers\CreatesPurchaseData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ─── Buyer submit transport request ──────────────────────────────────────────

test('buyer can submit a transport request', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/purchases/{$purchase->lot_id}/transport", [
             'pickup_location'  => 'Baltimore, MD',
             'delivery_address' => '123 Main St, New York, NY 10001',
             'preferred_dates'  => 'April 25-30',
             'notes'            => 'Please call ahead.',
         ])
         ->assertStatus(201)
         ->assertJsonPath('data.status', 'pending')
         ->assertJsonPath('data.pickup_location', 'Baltimore, MD');

    expect(TransportRequest::where('lot_id', $purchase->lot_id)->count())->toBe(1);
});

test('transport request requires pickup_location and delivery_address', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/purchases/{$purchase->lot_id}/transport", [])
         ->assertStatus(422)
         ->assertJsonValidationErrors(['pickup_location', 'delivery_address']);
});

test('buyer cannot submit transport for another buyers purchase', function () {
    $buyer1   = User::factory()->create(['status' => 'active']);
    $buyer2   = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer1);

    $this->actingAs($buyer2, 'sanctum')
         ->postJson("/api/v1/my/purchases/{$purchase->lot_id}/transport", [
             'pickup_location'  => 'Baltimore, MD',
             'delivery_address' => '123 Main St, New York, NY',
         ])
         ->assertNotFound();
});

test('transport request requires authentication', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer);

    $this->postJson("/api/v1/my/purchases/{$purchase->lot_id}/transport", [
        'pickup_location'  => 'Baltimore, MD',
        'delivery_address' => '123 Main St, NY',
    ])->assertUnauthorized();
});
