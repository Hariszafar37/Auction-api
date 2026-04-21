<?php

use App\Enums\LotStatus;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Payment\FeeCalculationService;
use App\Services\Payment\InvoiceService;
use Database\Seeders\FeeConfigurationSeeder;
use Database\Seeders\RolePermissionSeeder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(FeeConfigurationSeeder::class);
});

test('preview matches real invoice for same lot value', function () {
    $salePrice = 5000;

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    // Get preview result via the admin fee calculator endpoint
    $preview = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/admin/fees/preview?sale_price={$salePrice}")
        ->assertOk()
        ->json('data');

    // Create a real invoice using InvoiceService directly (bypasses controller)
    $invoice = createInvoiceForLotValue($salePrice);

    // Every fee component must match exactly
    expect((float) $invoice->buyer_fee_amount)->toBe((float) $preview['buyer_fee_amount'])
        ->and((float) $invoice->tax_amount)->toBe((float) $preview['tax_amount'])
        ->and((float) $invoice->tags_amount)->toBe((float) $preview['tags_amount'])
        ->and((float) $invoice->total_amount)->toBe((float) $preview['total_amount']);
});

test('fee calculation is location-independent when no location override exists', function () {
    $salePrice = 8000;

    // Calling calculate() with and without location should return the same totals
    // if no location-specific override is seeded
    $feeService = app(FeeCalculationService::class);

    $withoutLocation = $feeService->calculate($salePrice);
    $withLocation    = $feeService->calculate($salePrice, 'Unknown Location XYZ');

    expect($withoutLocation['total_amount'])->toBe($withLocation['total_amount'])
        ->and($withoutLocation['buyer_fee_amount'])->toBe($withLocation['buyer_fee_amount']);
});

// ─── Private helper ───────────────────────────────────────────────────────────

function createInvoiceForLotValue(int $salePrice): Invoice
{
    $buyer   = User::factory()->create(['status' => 'active']);
    $creator = User::factory()->create(['status' => 'active']);

    $auction = Auction::create([
        'title'      => 'Consistency Test Auction',
        'location'   => 'Baltimore, MD',
        'starts_at'  => now()->subHour(),
        'status'     => 'live',
        'created_by' => $creator->id,
    ]);

    $vehicle = Vehicle::create([
        'seller_id' => $creator->id,
        'vin'       => strtoupper(fake()->unique()->lexify('?????????????????')),
        'year'      => 2023,
        'make'      => 'Toyota',
        'model'     => 'RAV4',
        'status'    => 'in_auction',
    ]);

    $lot = AuctionLot::create([
        'auction_id'               => $auction->id,
        'vehicle_id'               => $vehicle->id,
        'lot_number'               => 1,
        'status'                   => LotStatus::Countdown,
        'starting_bid'             => 1000,
        'current_bid'              => $salePrice,
        'sold_price'               => $salePrice,
        'buyer_id'                 => $buyer->id,
        'current_winner_id'        => $buyer->id,
        'requires_seller_approval' => false,
    ]);

    // Load relations required by InvoiceService
    $lot->load(['auction', 'vehicle', 'buyer']);

    return app(InvoiceService::class)->createForLot($lot);
}
