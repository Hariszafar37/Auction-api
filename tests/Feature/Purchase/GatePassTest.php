<?php

use App\Enums\InvoiceStatus;
use App\Enums\PickupStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;

uses(\Tests\Helpers\CreatesPurchaseData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ─── Gate pass download — buyer ───────────────────────────────────────────────

test('buyer cannot download gate pass when invoice is not paid', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer, ['status' => InvoiceStatus::Pending]);

    $this->actingAs($buyer, 'sanctum')
         ->get("/api/v1/my/purchases/{$purchase->lot_id}/gate-pass")
         ->assertForbidden();
});

test('buyer can download gate pass when invoice is paid', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer, [
        'status'      => InvoiceStatus::Paid,
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now(),
    ], ['pickup_status' => PickupStatus::ReadyForPickup]);

    $purchase->load('lot.auction', 'lot.vehicle', 'buyer');

    $this->actingAs($buyer, 'sanctum')
         ->get("/api/v1/my/purchases/{$purchase->lot_id}/gate-pass")
         ->assertOk()
         ->assertHeader('Content-Type', 'application/pdf');
});

test('gate pass download requires authentication', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer);

    $this->getJson("/api/v1/my/purchases/{$purchase->lot_id}/gate-pass")
         ->assertUnauthorized();
});

test('buyer cannot download gate pass for another buyers purchase', function () {
    $buyer1   = User::factory()->create(['status' => 'active']);
    $buyer2   = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer1);

    $this->actingAs($buyer2, 'sanctum')
         ->getJson("/api/v1/my/purchases/{$purchase->lot_id}/gate-pass")
         ->assertForbidden();
});

// ─── Gate pass token — lazy generation ───────────────────────────────────────

test('gate pass token is generated lazily on first download', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer, [
        'status'      => InvoiceStatus::Paid,
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now(),
    ], ['pickup_status' => PickupStatus::ReadyForPickup]);

    expect($purchase->gate_pass_token)->toBeNull();

    $purchase->load('lot.auction', 'lot.vehicle', 'buyer');

    $this->actingAs($buyer, 'sanctum')
         ->get("/api/v1/my/purchases/{$purchase->lot_id}/gate-pass")
         ->assertOk();

    $purchase->refresh();
    expect($purchase->gate_pass_token)->not->toBeNull();
    expect(Str::isUuid($purchase->gate_pass_token))->toBeTrue();
});

// ─── Public gate pass verification ───────────────────────────────────────────

test('verify endpoint returns valid true for a paid gate pass', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer, [
        'status'      => InvoiceStatus::Paid,
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now(),
    ]);
    $token = Str::uuid()->toString();
    $purchase->update(['gate_pass_token' => $token]);

    $this->getJson("/api/v1/verify/gate-pass/{$token}")
         ->assertOk()
         ->assertJsonPath('valid', true)
         ->assertJsonStructure(['valid', 'vehicle', 'buyer', 'paid_at']);
});

test('verify endpoint returns valid false for unknown token', function () {
    $this->getJson('/api/v1/verify/gate-pass/not-a-real-token')
         ->assertOk()
         ->assertJsonPath('valid', false);
});

test('verify endpoint returns valid false for unpaid invoice', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer, ['status' => InvoiceStatus::Pending]);
    $token    = Str::uuid()->toString();
    $purchase->update(['gate_pass_token' => $token]);

    $this->getJson("/api/v1/verify/gate-pass/{$token}")
         ->assertOk()
         ->assertJsonPath('valid', false);
});

test('verify endpoint does not require authentication', function () {
    $this->getJson('/api/v1/verify/gate-pass/any-token')
         ->assertOk(); // no 401
});

// ─── FIX 9 — Additional gate pass tests ──────────────────────────────────────

test('gate pass download blocked when invoice unpaid', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer, ['status' => InvoiceStatus::Pending]);

    $this->actingAs($buyer, 'sanctum')
         ->getJson("/api/v1/my/purchases/{$purchase->lot_id}/gate-pass")
         ->assertForbidden();
});

test('gate pass download blocked when invoice partial', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer, [
        'status'      => \App\Enums\InvoiceStatus::Partial,
        'amount_paid' => 2000,
        'balance_due' => 3800,
    ]);

    $this->actingAs($buyer, 'sanctum')
         ->getJson("/api/v1/my/purchases/{$purchase->lot_id}/gate-pass")
         ->assertForbidden();
});

test('verify endpoint returns invalid for revoked gate pass', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer, [
        'status'      => InvoiceStatus::Paid,
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now(),
    ]);
    $token = Str::uuid()->toString();
    $purchase->update([
        'gate_pass_token'      => $token,
        'gate_pass_revoked_at' => now(),
        'revocation_reason'    => 'Suspected fraud',
    ]);

    $this->getJson("/api/v1/verify/gate-pass/{$token}")
         ->assertOk()
         ->assertJsonPath('valid', false)
         ->assertJsonPath('reason', 'revoked');
});

test('verify endpoint returns invalid for tampered token', function () {
    $this->getJson('/api/v1/verify/gate-pass/tampered-token-xyz')
         ->assertOk()
         ->assertJsonPath('valid', false);
});

test('gate pass token is unique per lot', function () {
    $buyer1   = User::factory()->create(['status' => 'active']);
    $buyer2   = User::factory()->create(['status' => 'active']);
    $purchase1 = $this->makePurchase($buyer1, [
        'status' => InvoiceStatus::Paid, 'amount_paid' => 5800, 'balance_due' => 0, 'paid_at' => now(),
    ], ['pickup_status' => \App\Enums\PickupStatus::ReadyForPickup]);
    $purchase2 = $this->makePurchase($buyer2, [
        'status' => InvoiceStatus::Paid, 'amount_paid' => 5800, 'balance_due' => 0, 'paid_at' => now(),
    ], ['pickup_status' => \App\Enums\PickupStatus::ReadyForPickup]);

    $purchase1->load('lot.auction', 'lot.vehicle', 'buyer');
    $purchase2->load('lot.auction', 'lot.vehicle', 'buyer');

    $this->actingAs($buyer1, 'sanctum')->get("/api/v1/my/purchases/{$purchase1->lot_id}/gate-pass")->assertOk();
    $this->actingAs($buyer2, 'sanctum')->get("/api/v1/my/purchases/{$purchase2->lot_id}/gate-pass")->assertOk();

    $token1 = $purchase1->fresh()->gate_pass_token;
    $token2 = $purchase2->fresh()->gate_pass_token;

    expect($token1)->not->toBeNull()
        ->and($token2)->not->toBeNull()
        ->and($token1)->not->toBe($token2);
});
