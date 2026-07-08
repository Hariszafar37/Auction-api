<?php

use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use App\Enums\SellerSettlementStatus;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\SellerSettlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Auction\AuctionLotService;
use App\Services\Payment\SellerSettlementService;
use Database\Seeders\RolePermissionSeeder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class, \Tests\Helpers\CreatesInvoiceData::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ─── Local factory helpers ─────────────────────────────────────────────────────

/**
 * Build an auction + vehicle(seller) + countdown lot ready to be closed.
 */
function makeSettlementLot(array $lotOverrides = [], ?User $seller = null): AuctionLot
{
    $creator = User::factory()->create(['status' => 'active']);
    $seller ??= User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Settlement Auction',
        'location'   => 'Baltimore, MD',
        'starts_at'  => now()->subDay(),
        'ends_at'    => now(),
        'status'     => AuctionStatus::Live,
        'created_by' => $creator->id,
    ]);

    $vehicle = Vehicle::create([
        'seller_id' => $seller->id,
        'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'      => 2021,
        'make'      => 'Honda',
        'model'     => 'Accord',
        'status'    => 'in_auction',
    ]);

    return AuctionLot::create(array_merge([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Countdown,
        'starting_bid'             => 500,
        'reserve_price'            => null,
        'current_bid'              => 5000,
        'current_winner_id'        => User::factory()->create(['status' => 'active'])->id,
        'countdown_seconds'        => 30,
        'requires_seller_approval' => false,
    ], $lotOverrides));
}

function settlementService(): SellerSettlementService
{
    return app(SellerSettlementService::class);
}

// ─── Commission calculation ────────────────────────────────────────────────────

test('sold above threshold uses 10 percent commission', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);

    $settlement = settlementService()->finalizeSold($lot);

    expect((float) $settlement->commission_amount)->toBe(500.0)      // 10% of 5000
        ->and((float) $settlement->registration_fee)->toBe(50.0)
        ->and((float) $settlement->net_proceeds)->toBe(4450.0)       // 5000 - 500 - 50
        ->and($settlement->outcome)->toBe('sold')
        ->and($settlement->status)->toBe(SellerSettlementStatus::Pending);
});

test('sold exactly at threshold uses flat 100 commission', function () {
    $lot = makeSettlementLot(['current_bid' => 1000, 'sold_price' => 1000]);

    $settlement = settlementService()->finalizeSold($lot);

    expect((float) $settlement->commission_amount)->toBe(100.0)
        ->and((float) $settlement->net_proceeds)->toBe(850.0);       // 1000 - 100 - 50
});

test('sold below threshold uses flat 100 commission', function () {
    $lot = makeSettlementLot(['current_bid' => 800, 'sold_price' => 800]);

    $settlement = settlementService()->finalizeSold($lot);

    expect((float) $settlement->commission_amount)->toBe(100.0)
        ->and((float) $settlement->net_proceeds)->toBe(650.0);       // 800 - 100 - 50
});

// ─── No sale ───────────────────────────────────────────────────────────────────

test('no sale applies the 50 fee and negative net', function () {
    $lot = makeSettlementLot(['status' => LotStatus::NoSale]);

    $settlement = settlementService()->finalizeNoSale($lot);

    expect((float) $settlement->no_sale_fee)->toBe(50.0)
        ->and((float) $settlement->registration_fee)->toBe(50.0)
        ->and((float) $settlement->net_proceeds)->toBe(-100.0)       // -(50 + 50)
        ->and($settlement->outcome)->toBe('no_sale')
        ->and($settlement->status)->toBe(SellerSettlementStatus::NoSale);
});

// ─── Idempotency ───────────────────────────────────────────────────────────────

test('re-running sold finalization does not duplicate or change fees', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);

    $first  = settlementService()->finalizeSold($lot);
    $second = settlementService()->finalizeSold($lot->fresh());

    expect($second->id)->toBe($first->id)
        ->and((float) $second->commission_amount)->toBe(500.0)
        ->and(SellerSettlement::where('lot_id', $lot->id)->count())->toBe(1);
});

test('seeding twice keeps a single settlement and one registration fee', function () {
    $lot = makeSettlementLot();

    settlementService()->seedForRegistration($lot);
    settlementService()->seedForRegistration($lot->fresh());

    $rows = SellerSettlement::where('lot_id', $lot->id)->get();
    expect($rows)->toHaveCount(1)
        ->and((float) $rows->first()->registration_fee)->toBe(50.0);
});

test('no sale finalization is idempotent', function () {
    $lot = makeSettlementLot(['status' => LotStatus::NoSale]);

    settlementService()->finalizeNoSale($lot);
    settlementService()->finalizeNoSale($lot->fresh());

    expect(SellerSettlement::where('lot_id', $lot->id)->count())->toBe(1);
});

// ─── Release date ──────────────────────────────────────────────────────────────

test('release date is 7 calendar days after the auction date', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    $expected = $lot->auction->ends_at->copy()->addDays(7)->startOfDay();

    $settlement = settlementService()->finalizeSold($lot);

    expect($settlement->release_date->toDateString())->toBe($expected->toDateString());
});

// ─── Status workflow ───────────────────────────────────────────────────────────

test('settlement moves pending to ready to check issued to paid', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    $s   = settlementService()->finalizeSold($lot);

    $s = settlementService()->markReadyForRelease($s);
    expect($s->status)->toBe(SellerSettlementStatus::ReadyForRelease);

    $s = settlementService()->issueCheck($s, 'CHK-1001');
    expect($s->status)->toBe(SellerSettlementStatus::CheckIssued)
        ->and($s->check_number)->toBe('CHK-1001');

    $s = settlementService()->markPaid($s);
    expect($s->status)->toBe(SellerSettlementStatus::Paid)
        ->and($s->paid_at)->not->toBeNull();
});

test('invalid status transition is rejected', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    $s   = settlementService()->finalizeSold($lot);

    // pending → paid is not allowed
    settlementService()->markPaid($s);
})->throws(\Illuminate\Validation\ValidationException::class);

test('no-sale settlement can be marked collected', function () {
    $lot = makeSettlementLot(['status' => LotStatus::NoSale]);
    $s   = settlementService()->finalizeNoSale($lot);

    $s = settlementService()->markCollected($s);

    expect($s->status)->toBe(SellerSettlementStatus::Collected)
        ->and($s->collected_at)->not->toBeNull();
});

test('a sold settlement cannot be marked collected', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    $s   = settlementService()->finalizeSold($lot);

    settlementService()->markCollected($s);
})->throws(\Illuminate\Validation\ValidationException::class);

test('admin can mark no-sale fees collected via the API', function () {
    $lot = makeSettlementLot(['status' => LotStatus::NoSale]);
    $s   = settlementService()->finalizeNoSale($lot);

    $this->actingAs($this->makeAdmin(), 'sanctum')
        ->postJson("/api/v1/admin/settlements/{$s->id}/mark-collected")
        ->assertOk()
        ->assertJsonPath('data.status', 'collected');
});

test('collection records method, reference and collector', function () {
    $lot = makeSettlementLot(['status' => LotStatus::NoSale]);
    $s   = settlementService()->finalizeNoSale($lot);
    $admin = $this->makeAdmin();

    $s = settlementService()->markCollected($s, $admin, 'check', 'CHK-9001');

    expect($s->collection_method)->toBe('check')
        ->and($s->collection_reference)->toBe('CHK-9001')
        ->and($s->collected_by)->toBe($admin->id)
        ->and($s->collected_at)->not->toBeNull();
});

// ─── Adjustments ────────────────────────────────────────────────────────────────

test('a negative adjustment reduces net proceeds and is audited', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    $s   = settlementService()->finalizeSold($lot);
    $admin = $this->makeAdmin();

    expect((float) $s->net_proceeds)->toBe(4450.0);

    $s = settlementService()->applyAdjustment($s, -100, 'Carried no-sale fee', $admin);

    expect((float) $s->adjustments_total)->toBe(-100.0)
        ->and((float) $s->net_proceeds)->toBe(4350.0)
        ->and($s->adjustments()->count())->toBe(1)
        ->and($s->adjustments()->first()->created_by)->toBe($admin->id)
        ->and($s->adjustments()->first()->reason)->toBe('Carried no-sale fee');
});

test('multiple adjustments accumulate without double counting', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    $s   = settlementService()->finalizeSold($lot);
    $admin = $this->makeAdmin();

    settlementService()->applyAdjustment($s, -100, 'Deduction', $admin);
    $s = settlementService()->applyAdjustment($s->fresh(), 50, 'Credit', $admin);

    expect((float) $s->adjustments_total)->toBe(-50.0)
        ->and((float) $s->net_proceeds)->toBe(4400.0);   // 4450 - 100 + 50
});

test('admin can apply an adjustment via the API', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    $s   = settlementService()->finalizeSold($lot);

    $this->actingAs($this->makeAdmin(), 'sanctum')
        ->postJson("/api/v1/admin/settlements/{$s->id}/adjustments", ['amount' => -75, 'reason' => 'Damage recovery'])
        ->assertOk()
        ->assertJsonPath('data.net_proceeds', 4375)
        ->assertJsonPath('data.adjustments.0.reason', 'Damage recovery');
});

test('adjustment requires a non-zero amount and reason', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    $s   = settlementService()->finalizeSold($lot);

    $this->actingAs($this->makeAdmin(), 'sanctum')
        ->postJson("/api/v1/admin/settlements/{$s->id}/adjustments", ['amount' => 0, 'reason' => ''])
        ->assertStatus(422);
});

test('a seeded (not finalized) settlement cannot be adjusted', function () {
    $lot = makeSettlementLot();
    $s   = settlementService()->seedForRegistration($lot); // outcome null

    settlementService()->applyAdjustment($s, -50, 'Premature', $this->makeAdmin());
})->throws(\Illuminate\Validation\ValidationException::class);

test('a seeded (not finalized) settlement cannot be released', function () {
    $lot = makeSettlementLot();
    $s   = settlementService()->seedForRegistration($lot);

    settlementService()->markReadyForRelease($s);
})->throws(\Illuminate\Validation\ValidationException::class);

test('voiding a lot voids only a non-finalized settlement', function () {
    $lot = makeSettlementLot();
    settlementService()->seedForRegistration($lot);

    settlementService()->voidForLot($lot);

    expect(SellerSettlement::where('lot_id', $lot->id)->first()->status)
        ->toBe(SellerSettlementStatus::Void);
});

test('voiding a lot never touches a finalized settlement', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    settlementService()->finalizeSold($lot);

    settlementService()->voidForLot($lot);

    expect(SellerSettlement::where('lot_id', $lot->id)->first()->status)
        ->toBe(SellerSettlementStatus::Pending);   // unchanged
});

// ─── Summary KPIs ────────────────────────────────────────────────────────────────

test('admin summary aggregates settlement KPIs', function () {
    $seller = User::factory()->create(['status' => 'active']);
    settlementService()->finalizeSold(makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000], $seller)); // comm 500, net 4450
    settlementService()->finalizeSold(makeSettlementLot(['current_bid' => 800, 'sold_price' => 800], $seller));   // comm 100, net 650
    settlementService()->finalizeNoSale(makeSettlementLot(['status' => LotStatus::NoSale], $seller));             // net -100

    $res = $this->actingAs($this->makeAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/settlements/summary')
        ->assertOk();

    $res->assertJsonPath('data.total_settlements', 3)
        ->assertJsonPath('data.sold_count', 2)
        ->assertJsonPath('data.no_sale_count', 1)
        ->assertJsonPath('data.total_gross_sales', 5800)
        ->assertJsonPath('data.total_commission', 600)
        ->assertJsonPath('data.pending_payout', 5100)               // 4450 + 650
        ->assertJsonPath('data.no_sale_fees_outstanding', 100);
});

test('seller summary is scoped to the seller', function () {
    $seller = User::factory()->create(['status' => 'active']);
    settlementService()->finalizeSold(makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000], $seller));
    settlementService()->finalizeSold(makeSettlementLot(['current_bid' => 2000, 'sold_price' => 2000])); // other seller

    $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/my/settlements/summary')
        ->assertOk()
        ->assertJsonPath('data.total_settlements', 1)
        ->assertJsonPath('data.total_net_proceeds', 4450);
});

test('admin list and summary can be filtered by auction', function () {
    $a = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    settlementService()->finalizeSold($a);
    $b = makeSettlementLot(['current_bid' => 2000, 'sold_price' => 2000]);
    settlementService()->finalizeSold($b);

    $admin = $this->makeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/settlements?auction_id={$a->auction_id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/settlements/summary?auction_id={$a->auction_id}")
        ->assertOk()
        ->assertJsonPath('data.total_settlements', 1)
        ->assertJsonPath('data.total_gross_sales', 5000);
});

// ─── PDF ─────────────────────────────────────────────────────────────────────────

test('admin can download a settlement statement pdf', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    $s   = settlementService()->finalizeSold($lot);

    $res = $this->actingAs($this->makeAdmin(), 'sanctum')
        ->get("/api/v1/admin/settlements/{$s->id}/pdf");

    $res->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('application/pdf');
});

test('seller can download their own settlement pdf but not another sellers', function () {
    $seller = User::factory()->create(['status' => 'active']);
    $mine   = settlementService()->finalizeSold(makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000], $seller));
    $other  = settlementService()->finalizeSold(makeSettlementLot(['current_bid' => 2000, 'sold_price' => 2000]));

    $this->actingAs($seller, 'sanctum')->get("/api/v1/my/settlements/{$mine->id}/pdf")->assertOk();
    $this->actingAs($seller, 'sanctum')->get("/api/v1/my/settlements/{$other->id}/pdf")->assertForbidden();
});

// ─── Payment settings (seller fee rules) ──────────────────────────────────────────

test('admin can update seller fee settings', function () {
    $this->actingAs($this->makeAdmin(), 'sanctum')
        ->putJson('/api/v1/admin/payment-settings', [
            'seller_registration_fee' => 75,
            'seller_commission_rate'  => 12,
            'seller_no_sale_fee'      => 60,
            'seller_release_days'     => 10,
        ])
        ->assertOk()
        ->assertJsonPath('data.seller_registration_fee', 75)
        ->assertJsonPath('data.seller_commission_rate', 12)
        ->assertJsonPath('data.seller_release_days', 10);
});

test('settlement uses the configured commission rate and fees', function () {
    \App\Models\PaymentSetting::current()->update([
        'seller_registration_fee' => 75,
        'seller_commission_rate'  => 12,
    ]);

    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    $s   = settlementService()->finalizeSold($lot);

    expect((float) $s->registration_fee)->toBe(75.0)
        ->and((float) $s->commission_amount)->toBe(600.0)   // 12% of 5000
        ->and((float) $s->net_proceeds)->toBe(4325.0);      // 5000 - 600 - 75
});

// ─── Release scheduler ─────────────────────────────────────────────────────────

test('release command marks due pending settlements ready', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    $s   = settlementService()->finalizeSold($lot);
    // Force the release date into the past.
    $s->update(['release_date' => now()->subDay()]);

    $this->artisan('settlements:release')->assertSuccessful();

    expect($s->fresh()->status)->toBe(SellerSettlementStatus::ReadyForRelease);
});

test('release command leaves future-dated settlements pending', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    $s   = settlementService()->finalizeSold($lot);
    $s->update(['release_date' => now()->addDays(5)]);

    $this->artisan('settlements:release')->assertSuccessful();

    expect($s->fresh()->status)->toBe(SellerSettlementStatus::Pending);
});

// ─── Multiple vehicles / sellers ───────────────────────────────────────────────

test('multiple vehicles for one seller produce separate settlements', function () {
    $seller = User::factory()->create(['status' => 'active']);
    $lotA = makeSettlementLot(['current_bid' => 3000, 'sold_price' => 3000], $seller);
    $lotB = makeSettlementLot(['current_bid' => 800, 'sold_price' => 800], $seller);

    settlementService()->finalizeSold($lotA);
    settlementService()->finalizeSold($lotB);

    expect(SellerSettlement::forSeller($seller->id)->count())->toBe(2);
});

// ─── Integration: closing a lot generates the settlement ───────────────────────

test('closing a no-bid lot generates a no-sale settlement', function () {
    $lot = makeSettlementLot([
        'current_bid'       => null,
        'current_winner_id' => null,
    ]);

    app(AuctionLotService::class)->closeLot($lot);

    $settlement = SellerSettlement::where('lot_id', $lot->id)->first();
    expect($settlement)->not->toBeNull()
        ->and($settlement->outcome)->toBe('no_sale')
        ->and((float) $settlement->no_sale_fee)->toBe(50.0);
});

test('closing a lot with a winner generates a sold settlement', function () {
    $lot = makeSettlementLot(['current_bid' => 5000]);

    app(AuctionLotService::class)->closeLot($lot);

    $settlement = SellerSettlement::where('lot_id', $lot->id)->first();
    expect($settlement)->not->toBeNull()
        ->and($settlement->outcome)->toBe('sold')
        ->and((float) $settlement->commission_amount)->toBe(500.0);
});

// ─── Admin API ─────────────────────────────────────────────────────────────────

test('admin can list seller settlements', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    settlementService()->finalizeSold($lot);

    $this->actingAs($this->makeAdmin(), 'sanctum')
        ->getJson('/api/v1/admin/settlements')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('admin can run the issue-check then mark-paid workflow', function () {
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    $s   = settlementService()->finalizeSold($lot);
    $s   = settlementService()->markReadyForRelease($s);

    $admin = $this->makeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/settlements/{$s->id}/issue-check", ['check_number' => 'CHK-77'])
        ->assertOk()
        ->assertJsonPath('data.status', 'check_issued')
        ->assertJsonPath('data.check_number', 'CHK-77');

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/settlements/{$s->id}/mark-paid")
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');
});

test('non admin cannot access admin settlements', function () {
    $this->actingAsBuyer()
        ->getJson('/api/v1/admin/settlements')
        ->assertForbidden();
});

// ─── Seller portal ─────────────────────────────────────────────────────────────

test('seller sees only their own finalized settlements', function () {
    $seller = User::factory()->create(['status' => 'active']);
    $mine   = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000], $seller);
    settlementService()->finalizeSold($mine);

    // Another seller's settlement
    $other = makeSettlementLot(['current_bid' => 2000, 'sold_price' => 2000]);
    settlementService()->finalizeSold($other);

    $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/my/settlements')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('seeded but not finalized settlements are hidden from the seller', function () {
    $seller = User::factory()->create(['status' => 'active']);
    $lot    = makeSettlementLot([], $seller);
    settlementService()->seedForRegistration($lot); // outcome null

    $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/my/settlements')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

// ─── Buyer flow isolation ──────────────────────────────────────────────────────

test('generating a seller settlement does not touch buyer invoices', function () {
    $buyer   = User::factory()->create(['status' => 'active']);
    $invoice = $this->makeInvoice($buyer);
    $before  = $invoice->only(['total_amount', 'balance_due', 'status']);

    // Independently generate a seller settlement on a different lot.
    $lot = makeSettlementLot(['current_bid' => 5000, 'sold_price' => 5000]);
    settlementService()->finalizeSold($lot);

    $invoice->refresh();
    expect($invoice->only(['total_amount', 'balance_due', 'status']))->toEqual($before);
});
