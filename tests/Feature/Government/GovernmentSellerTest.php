<?php

use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\SellerFeeProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Payment\SellerSettlementService;
use Database\Seeders\RolePermissionSeeder;

/*
 * Government consignor = restricted seller.
 *
 * Covers: own-inventory entry and edit (until listed), reserve control (until
 * the lot opens), POA exemption, own results via /my/dealer/lots, no bidding,
 * and settlement on the per-account fee profile instead of the global fees.
 */

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

// ── Helpers ──────────────────────────────────────────────────────────────────

function govSeller(array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'status'          => 'active',
        'account_type'    => 'government',
        'bidding_enabled' => false,
    ], $attributes));
    $user->assignRole('government');

    return $user;
}

function govVehicle(User $seller, array $overrides = []): Vehicle
{
    return Vehicle::create(array_merge([
        'seller_id'       => $seller->id,
        'vin'             => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'            => 2019,
        'make'            => 'Ford',
        'model'           => 'Explorer',
        'body_type'       => 'suv',
        'condition_light' => 'green',
        'status'          => 'available',
    ], $overrides));
}

function govAuction(AuctionStatus $status = AuctionStatus::Scheduled): Auction
{
    return Auction::create([
        'title'      => 'County Surplus Auction',
        'location'   => 'Baltimore, MD',
        'starts_at'  => now()->addDays(3),
        'ends_at'    => now()->addDays(3)->addHours(4),
        'status'     => $status,
        'created_by' => User::factory()->create(['status' => 'active'])->id,
    ]);
}

function govLot(Vehicle $vehicle, array $overrides = []): AuctionLot
{
    return AuctionLot::create(array_merge([
        'auction_id'        => govAuction()->id,
        'vehicle_id'        => $vehicle->id,
        'lot_number'        => 1,
        'status'            => LotStatus::Pending,
        'starting_bid'      => 500,
        'reserve_price'     => 4000,
        'countdown_seconds' => 30,
    ], $overrides));
}

function govVehiclePayload(array $overrides = []): array
{
    return array_merge([
        'vin'                  => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'                 => 2018,
        'make'                 => 'Chevrolet',
        'model'                => 'Tahoe',
        'body_type'            => 'suv',
        'condition_light'      => 'yellow',
        'condition_report_url' => 'https://reports.example.com/fleet-42',
    ], $overrides);
}

// ── Inventory entry ──────────────────────────────────────────────────────────

it('lets a government account add a vehicle it owns', function () {
    $gov = govSeller();

    $this->actingAs($gov, 'sanctum')
        ->postJson('/api/v1/my/vehicles', govVehiclePayload())
        ->assertCreated()
        ->assertJsonPath('data.status', 'available');

    expect(Vehicle::where('seller_id', $gov->id)->count())->toBe(1);
});

it('lists only the government account\'s own vehicles', function () {
    $gov   = govSeller();
    $other = govSeller();
    govVehicle($gov);
    $foreign = govVehicle($other);

    $this->actingAs($gov, 'sanctum')
        ->getJson('/api/v1/my/vehicles')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($gov, 'sanctum')
        ->getJson("/api/v1/my/vehicles/{$foreign->id}")
        ->assertNotFound();
});

// ── Edit before cutoff ───────────────────────────────────────────────────────

it('lets a government account edit its vehicle while it is not yet listed', function () {
    $gov     = govSeller();
    $vehicle = govVehicle($gov);

    $this->actingAs($gov, 'sanctum')
        ->patchJson("/api/v1/my/vehicles/{$vehicle->id}", ['mileage' => 81234, 'condition_light' => 'red'])
        ->assertOk()
        ->assertJsonPath('data.mileage', 81234)
        ->assertJsonPath('data.condition_light', 'red');

    expect($vehicle->fresh()->make)->toBe('Ford'); // untouched fields keep their value
});

it('locks vehicle edits once the vehicle is placed in an auction', function () {
    $gov     = govSeller();
    $vehicle = govVehicle($gov, ['status' => 'in_auction']);

    $this->actingAs($gov, 'sanctum')
        ->patchJson("/api/v1/my/vehicles/{$vehicle->id}", ['mileage' => 1])
        ->assertStatus(422)
        ->assertJsonPath('code', 'vehicle_locked');
});

it('does not let a government account edit another seller\'s vehicle', function () {
    $gov     = govSeller();
    $vehicle = govVehicle(govSeller());

    $this->actingAs($gov, 'sanctum')
        ->patchJson("/api/v1/my/vehicles/{$vehicle->id}", ['mileage' => 1])
        ->assertNotFound();
});

it('keeps vehicle self-edit closed to dealers and individual sellers', function (string $role) {
    $seller = User::factory()->create(['status' => 'active']);
    $seller->assignRole($role);
    $vehicle = govVehicle($seller);

    $this->actingAs($seller, 'sanctum')
        ->patchJson("/api/v1/my/vehicles/{$vehicle->id}", ['mileage' => 1])
        ->assertForbidden();
})->with(['dealer', 'seller']);

it('rejects a VIN already used by another vehicle but accepts its own', function () {
    $gov     = govSeller();
    $vehicle = govVehicle($gov);
    $taken   = govVehicle(govSeller());

    $this->actingAs($gov, 'sanctum')
        ->patchJson("/api/v1/my/vehicles/{$vehicle->id}", ['vin' => $taken->vin])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['vin']);

    $this->actingAs($gov, 'sanctum')
        ->patchJson("/api/v1/my/vehicles/{$vehicle->id}", ['vin' => $vehicle->vin])
        ->assertOk();
});

// ── Listing: POA required ────────────────────────────────────────────────────

function govApprovedPoa(User $user): void
{
    \App\Models\PowerOfAttorney::create([
        'user_id'             => $user->id,
        'type'                => 'esign',
        'status'              => 'approved',
        'signer_printed_name' => 'Fleet Director',
    ]);
}

function govAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    return $admin;
}

it('refuses to list a government vehicle, even for an admin, until the POA is approved', function () {
    $gov     = govSeller();
    $vehicle = govVehicle($gov);
    $auction = govAuction();

    $this->actingAs(govAdmin(), 'sanctum')
        ->postJson("/api/v1/admin/auctions/{$auction->id}/lots", [
            'vehicle_id'   => $vehicle->id,
            'starting_bid' => 500,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['vehicle_id']);

    expect($vehicle->fresh()->status)->toBe('available');
});

it('lets an admin list a government vehicle once the POA is approved', function () {
    $gov = govSeller();
    govApprovedPoa($gov);
    $vehicle = govVehicle($gov);
    $auction = govAuction();

    $this->actingAs(govAdmin(), 'sanctum')
        ->postJson("/api/v1/admin/auctions/{$auction->id}/lots", [
            'vehicle_id'    => $vehicle->id,
            'starting_bid'  => 500,
            'reserve_price' => 3000,
        ])
        ->assertCreated();

    expect($vehicle->fresh()->status)->toBe('in_auction');
});

it('still lets an admin list other sellers\' vehicles without a POA', function () {
    $seller = User::factory()->create(['status' => 'active']);
    $seller->assignRole('seller');
    $vehicle = govVehicle($seller);
    $auction = govAuction();

    $this->actingAs(govAdmin(), 'sanctum')
        ->postJson("/api/v1/admin/auctions/{$auction->id}/lots", [
            'vehicle_id'   => $vehicle->id,
            'starting_bid' => 500,
        ])
        ->assertCreated();
});

it('does not let a government account put its own vehicle into an auction', function () {
    $gov = govSeller();
    govApprovedPoa($gov);
    $vehicle = govVehicle($gov);

    $this->actingAs($gov, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'   => govAuction()->id,
            'starting_bid' => 500,
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'admin_assigns_auction');

    expect($vehicle->fresh()->status)->toBe('available');
});

it('still requires a Power of Attorney from individual sellers', function () {
    $seller = User::factory()->create(['status' => 'active']);
    $seller->assignRole('seller');
    $vehicle = govVehicle($seller);

    $this->actingAs($seller, 'sanctum')
        ->postJson("/api/v1/my/vehicles/{$vehicle->id}/submit-to-auction", [
            'auction_id'   => govAuction()->id,
            'starting_bid' => 500,
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'poa_required');
});

// ── Reserve control ──────────────────────────────────────────────────────────

it('lets a government account change the reserve while the lot is pending', function () {
    $gov = govSeller();
    $lot = govLot(govVehicle($gov, ['status' => 'in_auction']));

    $this->actingAs($gov, 'sanctum')
        ->patchJson("/api/v1/my/lots/{$lot->id}/reserve", ['reserve_price' => 6500])
        ->assertOk()
        ->assertJsonPath('data.reserve_price', 6500);

    $this->actingAs($gov, 'sanctum')
        ->patchJson("/api/v1/my/lots/{$lot->id}/reserve", ['reserve_price' => null])
        ->assertOk()
        ->assertJsonPath('data.reserve_price', null);
});

it('freezes the reserve once the lot has opened', function () {
    $gov = govSeller();
    $lot = govLot(govVehicle($gov, ['status' => 'in_auction']), ['status' => LotStatus::Open]);

    $this->actingAs($gov, 'sanctum')
        ->patchJson("/api/v1/my/lots/{$lot->id}/reserve", ['reserve_price' => 1])
        ->assertStatus(422)
        ->assertJsonPath('code', 'reserve_locked');

    expect($lot->fresh()->reserve_price)->toBe(4000);
});

it('does not let a government account change another seller\'s reserve', function () {
    $lot = govLot(govVehicle(govSeller(), ['status' => 'in_auction']));

    $this->actingAs(govSeller(), 'sanctum')
        ->patchJson("/api/v1/my/lots/{$lot->id}/reserve", ['reserve_price' => 1])
        ->assertForbidden();
});

it('keeps seller reserve changes closed to dealers', function () {
    $dealer = User::factory()->create(['status' => 'active', 'account_type' => 'dealer']);
    $dealer->assignRole('dealer');
    $lot = govLot(govVehicle($dealer, ['status' => 'in_auction']));

    $this->actingAs($dealer, 'sanctum')
        ->patchJson("/api/v1/my/lots/{$lot->id}/reserve", ['reserve_price' => 1])
        ->assertForbidden();
});

// ── Results & history ────────────────────────────────────────────────────────

it('shows a government account the results of its own lots only', function () {
    $gov  = govSeller();
    $mine = govLot(govVehicle($gov, ['status' => 'sold']), [
        'status'     => LotStatus::Sold,
        'sold_price' => 7200,
        'closed_at'  => now(),
    ]);
    govLot(govVehicle(govSeller(), ['status' => 'in_auction']));

    $this->actingAs($gov, 'sanctum')
        ->getJson('/api/v1/my/dealer/lots')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonPath('data.0.status', 'sold')
        ->assertJsonPath('data.0.sold_price', 7200)
        ->assertJsonPath('data.0.auction.title', 'County Surplus Auction');
});

it('keeps the dealer portal dashboard closed to government accounts', function () {
    $this->actingAs(govSeller(), 'sanctum')
        ->getJson('/api/v1/my/dealer/dashboard')
        ->assertForbidden();
});

it('still serves dealer lots and the dealer dashboard to dealers', function () {
    $dealer = User::factory()->create(['status' => 'active', 'account_type' => 'dealer']);
    $dealer->assignRole('dealer');

    $this->actingAs($dealer, 'sanctum')->getJson('/api/v1/my/dealer/lots')->assertOk();
    $this->actingAs($dealer, 'sanctum')->getJson('/api/v1/my/dealer/dashboard')->assertOk();
});

// ── No bidding ───────────────────────────────────────────────────────────────

it('treats a government account as unable to bid', function () {
    $gov = govSeller();

    expect($gov->canBid())->toBeFalse()
        ->and($gov->getBidIneligibilityReason())->toBe('bidding_disabled');
});

// ── Per-account fees in settlements ──────────────────────────────────────────

function govSettle(User $seller, ?int $soldPrice): \App\Models\SellerSettlement
{
    $lot = govLot(govVehicle($seller, ['status' => 'in_auction']), ['status' => LotStatus::Countdown]);

    $service = app(SellerSettlementService::class);
    $service->seedForRegistration($lot);

    if ($soldPrice === null) {
        return $service->finalizeNoSale($lot->fresh());
    }

    $lot->update(['status' => LotStatus::Sold, 'sold_price' => $soldPrice]);

    return $service->finalizeSold($lot->fresh());
}

it('settles a government account with no fee profile at zero fees', function () {
    $settlement = govSettle(govSeller(), 8000);

    expect((float) $settlement->registration_fee)->toBe(0.0)
        ->and((float) $settlement->commission_amount)->toBe(0.0)
        ->and((float) $settlement->net_proceeds)->toBe(8000.0)
        ->and($settlement->fee_snapshot['source'])->toBe('account_fee_profile');
});

it('applies a percentage commission from the account fee profile', function () {
    $gov = govSeller();
    SellerFeeProfile::create([
        'user_id'          => $gov->id,
        'commission_type'  => 'percent',
        'commission_value' => 5,
    ]);

    $settlement = govSettle($gov, 8000);

    expect((float) $settlement->commission_amount)->toBe(400.0)
        ->and((float) $settlement->registration_fee)->toBe(0.0)
        ->and((float) $settlement->net_proceeds)->toBe(7600.0);
});

it('applies flat commission, registration and no-sale fees from the profile', function () {
    $gov = govSeller();
    SellerFeeProfile::create([
        'user_id'          => $gov->id,
        'registration_fee' => 25,
        'commission_type'  => 'flat',
        'commission_value' => 150,
        'no_sale_fee'      => 40,
    ]);

    $sold = govSettle($gov, 3000);
    expect((float) $sold->commission_amount)->toBe(150.0)
        ->and((float) $sold->registration_fee)->toBe(25.0)
        ->and((float) $sold->net_proceeds)->toBe(2825.0);

    $unsold = govSettle($gov, null);
    expect((float) $unsold->no_sale_fee)->toBe(40.0)
        ->and((float) $unsold->net_proceeds)->toBe(-65.0);
});

it('never applies a fee profile to a non-government seller', function () {
    $seller = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $seller->assignRole('seller');
    // A stray row must not change how a regular seller is billed.
    SellerFeeProfile::create(['user_id' => $seller->id, 'commission_type' => 'none']);

    $settlement = govSettle($seller, 5000);

    expect((float) $settlement->commission_amount)->toBe(500.0) // global 10%
        ->and((float) $settlement->registration_fee)->toBe(50.0)
        ->and($settlement->fee_snapshot)->not->toHaveKey('source');
});

// ── Admin fee profile endpoints ──────────────────────────────────────────────

it('reports zero fees for a government account with no saved profile', function () {
    $gov = govSeller();

    $this->actingAs(govAdmin(), 'sanctum')
        ->getJson("/api/v1/admin/government/{$gov->id}/fees")
        ->assertOk()
        ->assertJsonPath('data.is_configured', false)
        ->assertJsonPath('data.commission_type', 'none')
        ->assertJsonPath('data.registration_fee', 0);
});

it('lets an admin save a government account\'s fees', function () {
    $gov   = govSeller();
    $admin = govAdmin();

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/v1/admin/government/{$gov->id}/fees", [
            'registration_fee' => 0,
            'commission_type'  => 'percent',
            'commission_value' => 5,
            'no_sale_fee'      => 0,
            'notes'            => 'Per county contract 2026-114',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_configured', true)
        ->assertJsonPath('data.commission_value', 5);

    $profile = $gov->fresh()->sellerFeeProfile;
    expect($profile->commission_type)->toBe('percent')
        ->and($profile->updated_by)->toBe($admin->id);
});

it('zeroes the amount when commission is set to none', function () {
    $gov = govSeller();

    $this->actingAs(govAdmin(), 'sanctum')
        ->putJson("/api/v1/admin/government/{$gov->id}/fees", [
            'registration_fee' => 0,
            'commission_type'  => 'none',
            'commission_value' => 12,
            'no_sale_fee'      => 0,
        ])
        ->assertOk()
        ->assertJsonPath('data.commission_value', 0);
});

it('rejects a percentage commission above 100', function () {
    $gov = govSeller();

    $this->actingAs(govAdmin(), 'sanctum')
        ->putJson("/api/v1/admin/government/{$gov->id}/fees", [
            'registration_fee' => 0,
            'commission_type'  => 'percent',
            'commission_value' => 150,
            'no_sale_fee'      => 0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['commission_value']);
});

it('only manages fee profiles for government accounts', function () {
    $dealer = User::factory()->create(['status' => 'active', 'account_type' => 'dealer']);

    $this->actingAs(govAdmin(), 'sanctum')
        ->getJson("/api/v1/admin/government/{$dealer->id}/fees")
        ->assertNotFound();
});

it('keeps fee profile endpoints closed to non-admins', function () {
    $gov = govSeller();

    $this->actingAs($gov, 'sanctum')
        ->getJson("/api/v1/admin/government/{$gov->id}/fees")
        ->assertForbidden();
});

it('does not let an admin swap a government account\'s role', function () {
    $gov = govSeller();

    $this->actingAs(govAdmin(), 'sanctum')
        ->patchJson("/api/v1/admin/users/{$gov->id}/role", ['role' => 'buyer'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'government_role_fixed');

    expect($gov->fresh()->hasRole('government'))->toBeTrue()
        ->and($gov->fresh()->hasRole('buyer'))->toBeFalse();
});

// ── Migration: existing accounts are converted ───────────────────────────────

it('converts existing government buyers to the restricted government role', function () {
    $legacy = User::factory()->create(['status' => 'active', 'account_type' => 'government']);
    $legacy->assignRole('buyer');
    $buyer = User::factory()->create(['status' => 'active', 'account_type' => 'individual']);
    $buyer->assignRole('buyer');

    $migration = require database_path('migrations/2026_09_24_000001_create_government_role_and_convert_accounts.php');
    $migration->up();

    $legacy = User::find($legacy->id);
    $buyer  = User::find($buyer->id);

    expect($legacy->hasRole('government'))->toBeTrue()
        ->and($legacy->hasRole('buyer'))->toBeFalse()
        ->and($legacy->bidding_enabled)->toBeFalse()
        ->and($buyer->hasRole('buyer'))->toBeTrue()
        ->and($buyer->hasRole('government'))->toBeFalse()
        ->and($buyer->bidding_enabled)->toBeTrue();
});
