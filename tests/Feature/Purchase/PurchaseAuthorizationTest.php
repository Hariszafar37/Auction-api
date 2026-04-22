<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

uses(\Tests\Helpers\CreatesPurchaseData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ─── Cross-buyer authorization ────────────────────────────────────────────────

test('buyer cannot view another buyers purchase', function () {
    $buyer1   = User::factory()->create(['status' => 'active']);
    $buyer2   = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer1);

    $this->actingAs($buyer2, 'sanctum')
         ->getJson("/api/v1/my/purchases/{$purchase->lot_id}")
         ->assertForbidden();
});

test('buyer cannot download another buyers gate pass', function () {
    $buyer1   = User::factory()->create(['status' => 'active']);
    $buyer2   = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer1);

    $this->actingAs($buyer2, 'sanctum')
         ->getJson("/api/v1/my/purchases/{$purchase->lot_id}/gate-pass")
         ->assertForbidden();
});

test('buyer cannot submit transport for another buyers lot', function () {
    $buyer1   = User::factory()->create(['status' => 'active']);
    $buyer2   = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer1);

    $this->actingAs($buyer2, 'sanctum')
         ->postJson("/api/v1/my/purchases/{$purchase->lot_id}/transport", [
             'pickup_location'  => 'Baltimore, MD',
             'delivery_address' => '123 Main St, NY',
         ])
         ->assertForbidden();
});

test('unauthenticated user cannot access purchases', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer);

    $this->getJson("/api/v1/my/purchases")->assertUnauthorized();
    $this->getJson("/api/v1/my/purchases/{$purchase->lot_id}")->assertUnauthorized();
    $this->postJson("/api/v1/my/purchases/{$purchase->lot_id}/transport", [])->assertUnauthorized();
});
