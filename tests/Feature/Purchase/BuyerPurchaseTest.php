<?php

use App\Enums\InvoiceStatus;
use App\Enums\PickupStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

uses(\Tests\Helpers\CreatesPurchaseData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ─── My Purchases — Index ─────────────────────────────────────────────────────

test('buyer can list their purchases', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->getJson('/api/v1/my/purchases')
         ->assertOk()
         ->assertJsonPath('data.0.lot_id', $purchase->lot_id)
         ->assertJsonPath('data.0.pickup_status', 'awaiting_payment');
});

test('buyer cannot see other buyers purchases', function () {
    $buyer1   = User::factory()->create(['status' => 'active']);
    $buyer2   = User::factory()->create(['status' => 'active']);
    $this->makePurchase($buyer1);

    $this->actingAs($buyer2, 'sanctum')
         ->getJson('/api/v1/my/purchases')
         ->assertOk()
         ->assertJsonPath('meta.total', 0);
});

test('purchase list requires authentication', function () {
    $this->getJson('/api/v1/my/purchases')->assertUnauthorized();
});

// ─── My Purchases — Show ─────────────────────────────────────────────────────

test('buyer can view their purchase detail', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->getJson("/api/v1/my/purchases/{$purchase->lot_id}")
         ->assertOk()
         ->assertJsonPath('data.lot_id', $purchase->lot_id)
         ->assertJsonStructure(['data' => ['lot', 'vehicle', 'auction', 'invoice', 'pickup_status']]);
});

test('buyer gets 404 when accessing another buyers purchase', function () {
    $buyer1   = User::factory()->create(['status' => 'active']);
    $buyer2   = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer1);

    $this->actingAs($buyer2, 'sanctum')
         ->getJson("/api/v1/my/purchases/{$purchase->lot_id}")
         ->assertNotFound();
});

// ─── Purchase response contains invoice summary ───────────────────────────────

test('purchase detail includes invoice status and balance', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer, ['status' => InvoiceStatus::Pending]);

    $this->actingAs($buyer, 'sanctum')
         ->getJson("/api/v1/my/purchases/{$purchase->lot_id}")
         ->assertOk()
         ->assertJsonPath('data.invoice.status', 'pending')
         ->assertJsonPath('data.invoice.balance_due', 5800);
});

// ─── Document tracker visible ─────────────────────────────────────────────────

test('document milestones are null by default', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->getJson("/api/v1/my/purchases/{$purchase->lot_id}")
         ->assertOk()
         ->assertJsonPath('data.title_received_at', null)
         ->assertJsonPath('data.title_verified_at', null)
         ->assertJsonPath('data.title_released_at', null);
});
