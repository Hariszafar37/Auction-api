<?php

namespace Database\Seeders;

use App\Models\FeeConfiguration;
use Illuminate\Database\Seeder;

class FeeConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        // ── Deposit ──────────────────────────────────────────────────────────────
        // Auto-charged on winning. Min is the default charge; max is the ceiling.
        $deposit = FeeConfiguration::updateOrCreate(
            ['fee_type' => 'deposit', 'location' => null],
            [
                'label'            => 'Buyer Deposit',
                'calculation_type' => 'flat_range',
                'amount'           => null,
                'min_amount'       => 300.00,
                'max_amount'       => 500.00,
                'applies_to'       => 'buyer',
                'is_active'        => true,
                'sort_order'       => 1,
                'notes'            => 'Automatically applied to invoice on winning bid.',
            ]
        );

        // ── Buyer Fee (sliding-scale tiered) ─────────────────────────────────────
        $buyerFee = FeeConfiguration::updateOrCreate(
            ['fee_type' => 'buyer_fee', 'location' => null],
            [
                'label'            => 'Buyer Fee',
                'calculation_type' => 'tiered',
                'amount'           => null,
                'applies_to'       => 'buyer',
                'is_active'        => true,
                'sort_order'       => 2,
                'notes'            => 'Sliding-scale fee based on sale price.',
            ]
        );

        // Wipe and re-seed tiers (idempotent)
        $buyerFee->tiers()->delete();
        $tiers = [
            ['from' => 0,     'to' => 999,   'type' => 'flat', 'amount' => 75.00],
            ['from' => 1000,  'to' => 1999,  'type' => 'flat', 'amount' => 100.00],
            ['from' => 2000,  'to' => 4999,  'type' => 'flat', 'amount' => 200.00],
            ['from' => 5000,  'to' => 9999,  'type' => 'flat', 'amount' => 350.00],
            ['from' => 10000, 'to' => 14999, 'type' => 'flat', 'amount' => 500.00],
            ['from' => 15000, 'to' => null,  'type' => 'percentage', 'amount' => 3.50],
        ];
        foreach ($tiers as $i => $tier) {
            $buyerFee->tiers()->create([
                'sale_price_from'      => $tier['from'],
                'sale_price_to'        => $tier['to'],
                'fee_calculation_type' => $tier['type'],
                'fee_amount'           => $tier['amount'],
                'sort_order'           => $i,
            ]);
        }

        // ── Tax ──────────────────────────────────────────────────────────────────
        FeeConfiguration::updateOrCreate(
            ['fee_type' => 'tax', 'location' => null],
            [
                'label'            => 'Sales Tax',
                'calculation_type' => 'percentage',
                'amount'           => 6.00,
                'applies_to'       => 'buyer',
                'is_active'        => true,
                'sort_order'       => 3,
                'notes'            => '6% default — override per location as needed.',
            ]
        );

        // ── Tags ─────────────────────────────────────────────────────────────────
        FeeConfiguration::updateOrCreate(
            ['fee_type' => 'tags', 'location' => null],
            [
                'label'            => 'Tags / Title Fees',
                'calculation_type' => 'flat',
                'amount'           => 150.00,
                'applies_to'       => 'buyer',
                'is_active'        => true,
                'sort_order'       => 4,
                'notes'            => 'State title and registration tag fees.',
            ]
        );

        // ── Storage ──────────────────────────────────────────────────────────────
        FeeConfiguration::updateOrCreate(
            ['fee_type' => 'storage', 'location' => null],
            [
                'label'            => 'Storage Fee',
                'calculation_type' => 'per_day',
                'amount'           => 25.00,
                'applies_to'       => 'buyer',
                'is_active'        => true,
                'sort_order'       => 5,
                'notes'            => '$25 per day after vehicle pickup deadline.',
            ]
        );
    }
}
