<?php

use App\Enums\InvoiceStatus;
use App\Enums\PickupStatus;
use App\Models\User;
use App\Services\Pickup\PurchaseService;
use Database\Seeders\RolePermissionSeeder;

uses(\Tests\Helpers\CreatesPurchaseData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ─── State machine — invalid skip transitions ─────────────────────────────────

test('cannot skip from awaiting payment to gate pass issued', function () {
    $admin    = $this->makeAdmin();
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer);

    $this->actingAs($admin, 'sanctum')
         ->patchJson("/api/v1/admin/purchases/{$purchase->lot_id}/status", ['status' => 'gate_pass_issued'])
         ->assertStatus(422);
});

test('cannot skip from awaiting payment to picked up', function () {
    $admin    = $this->makeAdmin();
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer);

    $this->actingAs($admin, 'sanctum')
         ->patchJson("/api/v1/admin/purchases/{$purchase->lot_id}/status", ['status' => 'picked_up'])
         ->assertStatus(422);
});

test('cannot transition backwards from picked up', function () {
    $admin    = $this->makeAdmin();
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer, [], ['pickup_status' => PickupStatus::PickedUp]);

    $this->actingAs($admin, 'sanctum')
         ->patchJson("/api/v1/admin/purchases/{$purchase->lot_id}/status", ['status' => 'gate_pass_issued'])
         ->assertStatus(422);
});

test('cannot transition backwards from ready for pickup', function () {
    $admin    = $this->makeAdmin();
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer, [], ['pickup_status' => PickupStatus::ReadyForPickup]);

    $this->actingAs($admin, 'sanctum')
         ->patchJson("/api/v1/admin/purchases/{$purchase->lot_id}/status", ['status' => 'ready_for_pickup'])
         ->assertStatus(422);
});

test('valid forward transitions all succeed', function () {
    $admin    = $this->makeAdmin();
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer);

    $steps = ['ready_for_pickup', 'gate_pass_issued', 'picked_up'];

    foreach ($steps as $step) {
        $this->actingAs($admin, 'sanctum')
             ->patchJson("/api/v1/admin/purchases/{$purchase->lot_id}/status", ['status' => $step])
             ->assertOk();
    }

    expect($purchase->fresh()->pickup_status)->toBe(PickupStatus::PickedUp);
});

test('picked up at is set automatically on pickup transition', function () {
    $admin    = $this->makeAdmin();
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer, [], ['pickup_status' => PickupStatus::GatePassIssued]);

    expect($purchase->picked_up_at)->toBeNull();

    $this->actingAs($admin, 'sanctum')
         ->patchJson("/api/v1/admin/purchases/{$purchase->lot_id}/status", ['status' => 'picked_up'])
         ->assertOk();

    expect($purchase->fresh()->picked_up_at)->not->toBeNull();
});
