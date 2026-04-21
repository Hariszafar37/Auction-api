<?php

use App\Models\FeeConfiguration;
use App\Services\Payment\FeeCalculationService;
use Database\Seeders\FeeConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ──────────────────────────────────────────────────────────────────

function makeFlatFee(string $type, float $amount, ?string $location = null): FeeConfiguration
{
    return FeeConfiguration::create([
        'fee_type'         => $type,
        'label'            => ucfirst($type),
        'calculation_type' => 'flat',
        'amount'           => $amount,
        'applies_to'       => 'buyer',
        'is_active'        => true,
        'sort_order'       => 1,
        'location'         => $location,
    ]);
}

function makePctFee(string $type, float $pct, ?string $location = null): FeeConfiguration
{
    return FeeConfiguration::create([
        'fee_type'         => $type,
        'label'            => ucfirst($type),
        'calculation_type' => 'percentage',
        'amount'           => $pct,
        'applies_to'       => 'buyer',
        'is_active'        => true,
        'sort_order'       => 1,
        'location'         => $location,
    ]);
}

// ─── Flat fee ─────────────────────────────────────────────────────────────────

test('flat fee returns correct amount regardless of sale price', function () {
    makeFlatFee('tags', 150.00);

    $result = app(FeeCalculationService::class)->calculate(5000);

    expect($result['tags_amount'])->toBe(150.0);
});

// ─── Percentage fee ───────────────────────────────────────────────────────────

test('percentage fee calculates correctly from sale price', function () {
    makePctFee('tax', 6.0);

    $result = app(FeeCalculationService::class)->calculate(5000);

    // 6% of 5000 = 300.00
    expect($result['tax_amount'])->toBe(300.0);
});

test('percentage fee rounds to two decimal places', function () {
    makePctFee('tax', 6.0);

    // 6% of 3333 = 199.98
    $result = app(FeeCalculationService::class)->calculate(3333);

    expect($result['tax_amount'])->toBe(199.98);
});

// ─── Tiered fee ───────────────────────────────────────────────────────────────

test('tiered fee selects correct tier for every price boundary', function () {
    $this->seed(FeeConfigurationSeeder::class);
    $service = app(FeeCalculationService::class);

    $cases = [
        // [sale_price, expected_buyer_fee]
        [0,     75.00],   // tier $0–$999:   flat $75
        [999,   75.00],
        [1000,  100.00],  // tier $1000–$1999: flat $100
        [1999,  100.00],
        [2000,  200.00],  // tier $2000–$4999: flat $200
        [4999,  200.00],
        [5000,  350.00],  // tier $5000–$9999: flat $350
        [9999,  350.00],
        [10000, 500.00],  // tier $10000–$14999: flat $500
        [14999, 500.00],
        [15000, 525.00],  // tier $15000+: 3.5% of 15000 = 525
        [20000, 700.00],  // 3.5% of 20000 = 700
    ];

    foreach ($cases as [$price, $expectedFee]) {
        $result = $service->calculate($price);
        expect($result['buyer_fee_amount'])
            ->toBe($expectedFee, "Expected buyer_fee={$expectedFee} for sale_price={$price}");
    }
});

// ─── Per-day fee ──────────────────────────────────────────────────────────────

test('per day fee multiplies rate by number of storage days', function () {
    FeeConfiguration::create([
        'fee_type'         => 'storage',
        'label'            => 'Storage',
        'calculation_type' => 'per_day',
        'amount'           => 25.00,
        'applies_to'       => 'buyer',
        'is_active'        => true,
        'sort_order'       => 1,
    ]);

    $result = app(FeeCalculationService::class)->calculate(5000, null, 5);

    expect($result['storage_fee_amount'])->toBe(125.0); // 5 days × $25
    expect($result['storage_days'])->toBe(5);
});

test('per day fee is zero when storage days is zero', function () {
    FeeConfiguration::create([
        'fee_type'         => 'storage',
        'label'            => 'Storage',
        'calculation_type' => 'per_day',
        'amount'           => 25.00,
        'applies_to'       => 'buyer',
        'is_active'        => true,
        'sort_order'       => 1,
    ]);

    $result = app(FeeCalculationService::class)->calculate(5000, null, 0);

    expect($result['storage_fee_amount'])->toBe(0.0);
});

// ─── Flat range (deposit) ─────────────────────────────────────────────────────

test('flat range deposit fee returns minimum amount', function () {
    FeeConfiguration::create([
        'fee_type'         => 'deposit',
        'label'            => 'Deposit',
        'calculation_type' => 'flat_range',
        'amount'           => null,
        'min_amount'       => 300.00,
        'max_amount'       => 500.00,
        'applies_to'       => 'buyer',
        'is_active'        => true,
        'sort_order'       => 1,
    ]);

    $result = app(FeeCalculationService::class)->calculate(5000);

    // flat_range always returns min_amount as the default charge
    expect($result['deposit_amount'])->toBe(300.0);
});

test('flat range fee is never below minimum amount', function () {
    FeeConfiguration::create([
        'fee_type'         => 'deposit',
        'label'            => 'Deposit',
        'calculation_type' => 'flat_range',
        'amount'           => null,
        'min_amount'       => 300.00,
        'max_amount'       => 500.00,
        'applies_to'       => 'buyer',
        'is_active'        => true,
        'sort_order'       => 1,
    ]);

    $result = app(FeeCalculationService::class)->calculate(100); // very low price

    expect($result['deposit_amount'])->toBeGreaterThanOrEqual(300.0);
});

// ─── Location override ────────────────────────────────────────────────────────

test('location specific fee overrides the global fee for that location', function () {
    makeFlatFee('tags', 150.00);               // global
    makeFlatFee('tags', 75.00, 'Baltimore');   // location override

    $globalResult   = app(FeeCalculationService::class)->calculate(5000, null);
    $locationResult = app(FeeCalculationService::class)->calculate(5000, 'Baltimore');

    expect($globalResult['tags_amount'])->toBe(150.0)
        ->and($locationResult['tags_amount'])->toBe(75.0);
});

test('falls back to global fee when no location specific override exists', function () {
    makeFlatFee('tags', 150.00); // global only

    $result = app(FeeCalculationService::class)->calculate(5000, 'NonExistentCity');

    expect($result['tags_amount'])->toBe(150.0);
});

// ─── Total calculation ────────────────────────────────────────────────────────

test('total equals sale price plus all line fees excluding deposit', function () {
    $this->seed(FeeConfigurationSeeder::class);

    $salePrice = 5000;
    $result    = app(FeeCalculationService::class)->calculate($salePrice);

    $expected = $salePrice
        + $result['buyer_fee_amount']
        + $result['tax_amount']
        + $result['tags_amount']
        + $result['storage_fee_amount'];

    expect($result['total_amount'])->toBe($expected);
    // Deposit is a hold — not added to total
    expect($result['total_amount'])->not->toBe($expected + $result['deposit_amount']);
});

// ─── Inactive fees ────────────────────────────────────────────────────────────

test('inactive fee configurations are excluded from calculation', function () {
    FeeConfiguration::create([
        'fee_type'         => 'tags',
        'label'            => 'Tags (disabled)',
        'calculation_type' => 'flat',
        'amount'           => 999.00,
        'applies_to'       => 'buyer',
        'is_active'        => false, // inactive
        'sort_order'       => 1,
    ]);

    $result = app(FeeCalculationService::class)->calculate(5000);

    expect($result['tags_amount'])->toBe(0.0);
});
