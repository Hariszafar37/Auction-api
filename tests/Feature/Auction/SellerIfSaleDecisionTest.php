<?php

use App\Enums\LotStatus;
use App\Models\Bid;
use App\Models\User;
use App\Notifications\LotAwaitingSellerDecision;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * Seller-facing "If Sale" decision flow.
 *
 * A lot that closes needing seller approval sits in if_sale until the seller
 * approves (→ sold) or rejects (→ reserve_not_met), or the 48h deadline expires.
 * These endpoints give the vehicle's owner those actions; previously only admins
 * could decide. Routes are gated on permission:inventory.create so both dealers
 * and approved individual sellers reach them, with per-lot ownership enforced
 * in AuctionLotService.
 */

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ───────────────────────────────────────────────────────────────────

/** An active user holding a role that grants inventory.create. */
function makeLotSeller(string $role = 'seller'): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

// ── Approve ───────────────────────────────────────────────────────────────────

test('seller approves the highest bid and the lot sells at the current bid', function () {
    $seller = makeLotSeller();
    $lot    = $this->createIfSaleLotForSeller($seller, ['current_bid' => 4200]);

    $this->actingAs($seller, 'sanctum')
        ->postJson("/api/v1/my/lots/{$lot->id}/if-sale/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'sold')
        ->assertJsonPath('data.sold_price', 4200);

    $lot->refresh();
    expect($lot->status)->toBe(LotStatus::Sold)
        ->and($lot->sold_price)->toBe(4200)
        ->and($lot->buyer_id)->toBe($lot->current_winner_id)
        ->and($lot->seller_approved_at)->not->toBeNull();
});

test('a dealer can approve their own if_sale lot too', function () {
    $dealer = makeLotSeller('dealer');
    $lot    = $this->createIfSaleLotForSeller($dealer);

    $this->actingAs($dealer, 'sanctum')
        ->postJson("/api/v1/my/lots/{$lot->id}/if-sale/approve")
        ->assertOk();

    expect($lot->refresh()->status)->toBe(LotStatus::Sold);
});

// ── Reject ────────────────────────────────────────────────────────────────────

test('seller rejects the highest bid and the lot closes as reserve_not_met', function () {
    $seller = makeLotSeller();
    $lot    = $this->createIfSaleLotForSeller($seller);

    $this->actingAs($seller, 'sanctum')
        ->postJson("/api/v1/my/lots/{$lot->id}/if-sale/reject")
        ->assertOk()
        ->assertJsonPath('data.status', 'reserve_not_met');

    $lot->refresh();
    expect($lot->status)->toBe(LotStatus::ReserveNotMet)
        ->and($lot->buyer_id)->toBeNull()
        ->and($lot->vehicle->status)->toBe('available');
});

// ── Ownership + status guards ─────────────────────────────────────────────────

test('a seller cannot decide on a lot they do not own', function () {
    $seller  = makeLotSeller();
    $someone = makeLotSeller();
    $lot     = $this->createIfSaleLotForSeller($someone);

    $this->assertForbidden(
        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/my/lots/{$lot->id}/if-sale/approve")
    );

    expect($lot->refresh()->status)->toBe(LotStatus::IfSale);
});

test('a plain buyer cannot reach the seller decision endpoints', function () {
    $seller = makeLotSeller();
    $lot    = $this->createIfSaleLotForSeller($seller);

    $this->assertForbidden(
        $this->actingAsBuyer()->postJson("/api/v1/my/lots/{$lot->id}/if-sale/approve")
    );
});

test('a lot that is not in if_sale status cannot be decided', function () {
    $seller  = makeLotSeller();
    $vehicle = $this->createVehicle($seller);
    $lot     = $this->createLot(null, ['vehicle_id' => $vehicle->id, 'status' => LotStatus::Open]);

    $this->actingAs($seller, 'sanctum')
        ->postJson("/api/v1/my/lots/{$lot->id}/if-sale/approve")
        ->assertStatus(422);

    expect($lot->refresh()->status)->toBe(LotStatus::Open);
});

// ── Pending decision list ─────────────────────────────────────────────────────

test('pending-decision returns only my if_sale lots', function () {
    $seller = makeLotSeller();
    $mine   = $this->createIfSaleLotForSeller($seller);
    $this->createIfSaleLotForSeller(makeLotSeller());                       // someone else's if_sale lot
    $this->createLot(null, ['vehicle_id' => $this->createVehicle($seller)->id]); // mine, still open

    $response = $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/my/lots/pending-decision')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    $response->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonPath('data.0.status', 'if_sale');
});

test('pending-decision exposes the seller decision deadline and reserve price', function () {
    $seller = makeLotSeller();
    $this->createIfSaleLotForSeller($seller, ['reserve_price' => 5000]);

    $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/my/lots/pending-decision')
        ->assertOk()
        ->assertJsonPath('data.0.reserve_price', 5000)
        ->assertJsonPath('data.0.reserve_met', false)
        ->assertJsonStructure(['data' => [['seller_decision_deadline']]]);
});

// ── Bid history ───────────────────────────────────────────────────────────────

test('seller sees the bid history on their lot, highest first, without bidder identity', function () {
    $seller = makeLotSeller();
    $lot    = $this->createIfSaleLotForSeller($seller);
    $bidder = User::factory()->create(['status' => 'active']);

    foreach ([800, 1000, 600] as $amount) {
        Bid::create([
            'auction_lot_id' => $lot->id,
            'user_id'        => $bidder->id,
            'amount'         => $amount,
            'type'           => 'manual',
            'is_winning'     => $amount === 1000,
            'placed_at'      => now(),
        ]);
    }

    $response = $this->actingAs($seller, 'sanctum')
        ->getJson("/api/v1/my/lots/{$lot->id}/bids")
        ->assertOk()
        ->assertJsonPath('data.0.amount', 1000)
        ->assertJsonPath('data.2.amount', 600);

    // Bidder identity stays hidden from the seller — only the bidder number is public.
    expect($response->json('data.0'))->not->toHaveKey('bidder_id')
        ->and($response->json('data.0.bidder_number'))->toBe($bidder->bidder_number);
});

test('seller cannot read the bid history of a lot they do not own', function () {
    $lot = $this->createIfSaleLotForSeller(makeLotSeller());

    $this->assertForbidden(
        $this->actingAs(makeLotSeller(), 'sanctum')->getJson("/api/v1/my/lots/{$lot->id}/bids")
    );
});

// ── Notification ──────────────────────────────────────────────────────────────

test('the seller is notified in-app when their lot enters if_sale', function () {
    Notification::fake();

    $seller  = makeLotSeller();
    $vehicle = $this->createVehicle($seller);
    $lot     = $this->createLot(User::factory()->create(['status' => 'active']), [
        'vehicle_id'               => $vehicle->id,
        'status'                   => LotStatus::Countdown,
        'requires_seller_approval' => true,
        'current_bid'              => 3000,
    ]);

    app(App\Services\Auction\AuctionLotService::class)->closeLot($lot);

    expect($lot->refresh()->status)->toBe(LotStatus::IfSale);
    Notification::assertSentTo($seller, LotAwaitingSellerDecision::class);
});

// ── Admin path unchanged ──────────────────────────────────────────────────────

test('admin can still approve an if_sale lot they do not own', function () {
    $lot = $this->createIfSaleLotForSeller(makeLotSeller());

    $this->actingAsAdmin()
        ->postJson("/api/v1/admin/lots/{$lot->id}/if-sale/approve")
        ->assertOk();

    expect($lot->refresh()->status)->toBe(LotStatus::Sold);
});
