<?php

use App\Enums\InvoiceStatus;
use App\Enums\PickupStatus;
use App\Enums\TransportRequestStatus;
use App\Mail\PickupReadyNotification;
use App\Mail\TransportQuoteReceived;
use App\Models\TransportRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;

uses(\Tests\Helpers\CreatesPurchaseData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Mail::fake();
});

// ─── Admin purchase list ──────────────────────────────────────────────────────

test('admin can list all purchases', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $admin    = $this->makeAdmin();
    $this->makePurchase($buyer);

    $this->actingAs($admin, 'sanctum')
         ->getJson('/api/v1/admin/purchases')
         ->assertOk()
         ->assertJsonPath('meta.total', 1);
});

test('non-admin cannot access admin purchases list', function () {
    $buyer = User::factory()->create(['status' => 'active']);
    $this->makePurchase($buyer);

    $this->actingAs($buyer, 'sanctum')
         ->getJson('/api/v1/admin/purchases')
         ->assertForbidden();
});

test('admin can filter purchases by pickup_status', function () {
    $buyer  = User::factory()->create(['status' => 'active']);
    $admin  = $this->makeAdmin();
    $this->makePurchase($buyer, [], ['pickup_status' => PickupStatus::ReadyForPickup]);
    $this->makePurchase($buyer, [], ['pickup_status' => PickupStatus::AwaitingPayment]);

    $this->actingAs($admin, 'sanctum')
         ->getJson('/api/v1/admin/purchases?pickup_status=ready_for_pickup')
         ->assertOk()
         ->assertJsonPath('meta.total', 1);
});

// ─── Admin purchase show ──────────────────────────────────────────────────────

test('admin can view a purchase detail', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $admin    = $this->makeAdmin();
    $purchase = $this->makePurchase($buyer);

    $this->actingAs($admin, 'sanctum')
         ->getJson("/api/v1/admin/purchases/{$purchase->lot_id}")
         ->assertOk()
         ->assertJsonPath('data.lot_id', $purchase->lot_id)
         ->assertJsonStructure(['data' => ['buyer', 'lot', 'vehicle', 'invoice']]);
});

// ─── Admin pickup status transitions ─────────────────────────────────────────

test('admin can transition pickup status from awaiting_payment to ready_for_pickup', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $admin    = $this->makeAdmin();
    $purchase = $this->makePurchase($buyer);

    $this->actingAs($admin, 'sanctum')
         ->patchJson("/api/v1/admin/purchases/{$purchase->lot_id}/status", [
             'status' => 'ready_for_pickup',
         ])
         ->assertOk()
         ->assertJsonPath('data.pickup_status', 'ready_for_pickup');
});

test('admin cannot skip pickup status steps', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $admin    = $this->makeAdmin();
    $purchase = $this->makePurchase($buyer); // awaiting_payment

    $this->actingAs($admin, 'sanctum')
         ->patchJson("/api/v1/admin/purchases/{$purchase->lot_id}/status", [
             'status' => 'picked_up',
         ])
         ->assertStatus(422);
});

test('admin can progress through all pickup statuses in order', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $admin    = $this->makeAdmin();
    $purchase = $this->makePurchase($buyer);

    foreach (['ready_for_pickup', 'gate_pass_issued', 'picked_up'] as $status) {
        $this->actingAs($admin, 'sanctum')
             ->patchJson("/api/v1/admin/purchases/{$purchase->lot_id}/status", compact('status'))
             ->assertOk()
             ->assertJsonPath('data.pickup_status', $status);
    }

    $purchase->refresh();
    expect($purchase->picked_up_at)->not->toBeNull();
});

// ─── Admin document tracker ───────────────────────────────────────────────────

test('admin can mark title received', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $admin    = $this->makeAdmin();
    $purchase = $this->makePurchase($buyer);

    $this->actingAs($admin, 'sanctum')
         ->patchJson("/api/v1/admin/purchases/{$purchase->lot_id}/documents", [
             'title_received' => true,
         ])
         ->assertOk()
         ->assertJsonPath('data.title_received_at', fn ($v) => $v !== null);

    $purchase->refresh();
    expect($purchase->title_received_at)->not->toBeNull();
});

test('admin can set all document milestones', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $admin    = $this->makeAdmin();
    $purchase = $this->makePurchase($buyer);

    $this->actingAs($admin, 'sanctum')
         ->patchJson("/api/v1/admin/purchases/{$purchase->lot_id}/documents", [
             'title_received' => true,
             'title_verified' => true,
             'title_released' => true,
         ])
         ->assertOk();

    $purchase->refresh();
    expect($purchase->title_received_at)->not->toBeNull()
        ->and($purchase->title_verified_at)->not->toBeNull()
        ->and($purchase->title_released_at)->not->toBeNull();
});

test('admin can clear a document milestone', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $admin    = $this->makeAdmin();
    $purchase = $this->makePurchase($buyer, [], [
        'title_received_at' => now(),
    ]);

    $this->actingAs($admin, 'sanctum')
         ->patchJson("/api/v1/admin/purchases/{$purchase->lot_id}/documents", [
             'title_received' => false,
         ])
         ->assertOk();

    $purchase->refresh();
    expect($purchase->title_received_at)->toBeNull();
});

// ─── Admin notes ─────────────────────────────────────────────────────────────

test('admin can add pickup notes', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $admin    = $this->makeAdmin();
    $purchase = $this->makePurchase($buyer);

    $this->actingAs($admin, 'sanctum')
         ->postJson("/api/v1/admin/purchases/{$purchase->lot_id}/notes", [
             'notes' => 'Please bring photo ID. Gate opens at 8am.',
         ])
         ->assertOk()
         ->assertJsonPath('data.pickup_notes', 'Please bring photo ID. Gate opens at 8am.');
});

// ─── Pickup ready email (via webhook-triggered PurchaseService) ───────────────

test('handleInvoicePaid transitions pickup status and queues email', function () {
    $buyer    = User::factory()->create(['status' => 'active', 'email' => 'buyer@example.com']);
    $purchase = $this->makePurchase($buyer, ['status' => InvoiceStatus::Pending]);

    // Mark invoice paid directly (simulating webhook)
    $invoice = $purchase->invoice;
    $invoice->update([
        'status'      => InvoiceStatus::Paid,
        'amount_paid' => 5800,
        'balance_due' => 0,
        'paid_at'     => now(),
    ]);

    $purchaseService = app(\App\Services\Pickup\PurchaseService::class);
    $purchaseService->handleInvoicePaid($invoice->fresh());

    $purchase->refresh();
    expect($purchase->pickup_status)->toBe(PickupStatus::ReadyForPickup);

    Mail::assertQueued(PickupReadyNotification::class, fn ($m) =>
        $m->purchase->id === $purchase->id
    );
});

// ─── Admin transport requests ─────────────────────────────────────────────────

test('admin can list transport requests', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $admin    = $this->makeAdmin();
    $purchase = $this->makePurchase($buyer);

    TransportRequest::create([
        'lot_id'           => $purchase->lot_id,
        'buyer_id'         => $buyer->id,
        'pickup_location'  => 'Baltimore, MD',
        'delivery_address' => '123 Main St, NY',
    ]);

    $this->actingAs($admin, 'sanctum')
         ->getJson('/api/v1/admin/transport-requests')
         ->assertOk()
         ->assertJsonPath('meta.total', 1);
});

test('admin can add a quote to a transport request and buyer is notified', function () {
    $buyer    = User::factory()->create(['status' => 'active', 'email' => 'buyer@example.com']);
    $admin    = $this->makeAdmin();
    $purchase = $this->makePurchase($buyer);

    $transport = TransportRequest::create([
        'lot_id'           => $purchase->lot_id,
        'buyer_id'         => $buyer->id,
        'pickup_location'  => 'Baltimore, MD',
        'delivery_address' => '123 Main St, NY',
    ]);

    $this->actingAs($admin, 'sanctum')
         ->patchJson("/api/v1/admin/transport-requests/{$transport->id}", [
             'status'       => 'quoted',
             'quote_amount' => 350.00,
             'admin_notes'  => 'Available week of April 28.',
         ])
         ->assertOk()
         ->assertJsonPath('data.status', 'quoted')
         ->assertJsonPath('data.quote_amount', 350);

    Mail::assertQueued(TransportQuoteReceived::class);
});

test('admin does not re-notify buyer when updating an already-quoted request', function () {
    $buyer    = User::factory()->create(['status' => 'active', 'email' => 'buyer@example.com']);
    $admin    = $this->makeAdmin();
    $purchase = $this->makePurchase($buyer);

    $transport = TransportRequest::create([
        'lot_id'           => $purchase->lot_id,
        'buyer_id'         => $buyer->id,
        'pickup_location'  => 'Baltimore, MD',
        'delivery_address' => '123 Main St, NY',
        'status'           => TransportRequestStatus::Quoted,
        'quote_amount'     => 300.00,
        'quoted_at'        => now(),
    ]);

    $this->actingAs($admin, 'sanctum')
         ->patchJson("/api/v1/admin/transport-requests/{$transport->id}", [
             'status' => 'arranged',
         ])
         ->assertOk();

    Mail::assertNotQueued(TransportQuoteReceived::class);
});

test('non-admin cannot access transport requests list', function () {
    $buyer = User::factory()->create(['status' => 'active']);

    $this->actingAs($buyer, 'sanctum')
         ->getJson('/api/v1/admin/transport-requests')
         ->assertForbidden();
});
