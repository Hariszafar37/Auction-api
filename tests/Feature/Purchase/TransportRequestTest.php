<?php

use App\Enums\InvoiceStatus;
use App\Enums\TransportRequestStatus;
use App\Models\AuctionLot;
use App\Models\Auction;
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
         ->assertForbidden();
});

test('transport request requires authentication', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer);

    $this->postJson("/api/v1/my/purchases/{$purchase->lot_id}/transport", [
        'pickup_location'  => 'Baltimore, MD',
        'delivery_address' => '123 Main St, NY',
    ])->assertUnauthorized();
});

// ─── FIX 3 / FIX 9 — Duplicate transport request guard ───────────────────────

test('duplicate transport request returns 422', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer);

    $payload = [
        'pickup_location'  => 'Baltimore, MD',
        'delivery_address' => '123 Main St, NY',
    ];

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/purchases/{$purchase->lot_id}/transport", $payload)
         ->assertStatus(201);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/purchases/{$purchase->lot_id}/transport", $payload)
         ->assertStatus(422)
         ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'transport request already exists'));
});

test('cancelled transport request allows new submission', function () {
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer);

    // Create an existing cancelled request
    TransportRequest::create([
        'lot_id'           => $purchase->lot_id,
        'buyer_id'         => $buyer->id,
        'pickup_location'  => 'Old Location',
        'delivery_address' => 'Old Address',
        'status'           => TransportRequestStatus::Cancelled,
    ]);

    $this->actingAs($buyer, 'sanctum')
         ->postJson("/api/v1/my/purchases/{$purchase->lot_id}/transport", [
             'pickup_location'  => 'Baltimore, MD',
             'delivery_address' => '123 Main St, NY',
         ])
         ->assertStatus(201);

    expect(TransportRequest::where('lot_id', $purchase->lot_id)->count())->toBe(2);
});

// ─── FIX 5 / FIX 9 — Bulk ready skips unpaid lots ───────────────────────────

test('admin bulk ready skips unpaid invoices', function () {
    $admin    = $this->makeAdmin();
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer, ['status' => InvoiceStatus::Pending]);

    $this->actingAs($admin, 'sanctum')
         ->postJson('/api/v1/admin/purchases/bulk-ready', [
             'auction_event_id' => $purchase->lot->auction_id,
         ])
         ->assertOk()
         ->assertJsonPath('data.updated', 0)
         ->assertJsonPath('data.skipped_count', 1);

    expect($purchase->fresh()->pickup_status->value)->toBe('awaiting_payment');
});

test('admin bulk ready reports skipped lot numbers', function () {
    $admin    = $this->makeAdmin();
    $buyer    = User::factory()->create(['status' => 'active']);
    $purchase = $this->makePurchase($buyer, ['status' => InvoiceStatus::Pending]);

    $response = $this->actingAs($admin, 'sanctum')
         ->postJson('/api/v1/admin/purchases/bulk-ready', [
             'auction_event_id' => $purchase->lot->auction_id,
         ])
         ->assertOk()
         ->json('data');

    expect($response['skipped_lots'])->toContain((string) $purchase->lot->lot_number);
});
