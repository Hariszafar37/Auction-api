<?php

namespace App\Services\Payment;

use App\Models\FeeConfiguration;
use App\Models\FeeTier;

class FeeCalculationService
{
    /**
     * Calculate all buyer fees for a won lot.
     *
     * @param  int         $salePrice   Winning bid amount (dollars)
     * @param  string|null $location    Auction location — used for location-specific overrides
     * @param  int         $storageDays Days in storage (0 if not yet applicable)
     * @return array{
     *   deposit_amount: float,
     *   buyer_fee_amount: float,
     *   tax_amount: float,
     *   tags_amount: float,
     *   storage_days: int,
     *   storage_fee_amount: float,
     *   total_amount: float,
     *   snapshot: array
     * }
     */
    public function calculate(int $salePrice, ?string $location = null, int $storageDays = 0): array
    {
        $feeTypes = ['deposit', 'buyer_fee', 'tax', 'tags', 'storage'];
        $snapshot = [];
        $results  = [
            'deposit_amount'     => 0.0,
            'buyer_fee_amount'   => 0.0,
            'tax_amount'         => 0.0,
            'tags_amount'        => 0.0,
            'storage_days'       => $storageDays,
            'storage_fee_amount' => 0.0,
        ];

        foreach ($feeTypes as $type) {
            $config = $this->resolveConfig($type, $location);

            if (! $config) {
                continue;
            }

            $amount = $this->computeAmount($config, $salePrice, $storageDays);

            $snapshot[$type] = [
                'label'            => $config->label,
                'calculation_type' => $config->calculation_type,
                'amount'           => $config->amount,
                'min_amount'       => $config->min_amount,
                'max_amount'       => $config->max_amount,
                'computed'         => $amount,
                'config_id'        => $config->id,
            ];

            match($type) {
                'deposit'   => $results['deposit_amount']     = $amount,
                'buyer_fee' => $results['buyer_fee_amount']   = $amount,
                'tax'       => $results['tax_amount']         = $amount,
                'tags'      => $results['tags_amount']        = $amount,
                'storage'   => $results['storage_fee_amount'] = $amount,
            };
        }

        $results['total_amount'] = (float) $salePrice
            + $results['buyer_fee_amount']
            + $results['tax_amount']
            + $results['tags_amount']
            + $results['storage_fee_amount'];
        // Deposit is collected separately — included in total as a line item, NOT added again
        $results['snapshot'] = $snapshot;

        return $results;
    }

    /**
     * Preview fee breakdown without creating an invoice (for admin fee calculator).
     */
    public function preview(int $salePrice, ?string $location = null): array
    {
        return $this->calculate($salePrice, $location);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    private function resolveConfig(string $feeType, ?string $location): ?FeeConfiguration
    {
        // Location-specific rule takes precedence over global
        if ($location) {
            $config = FeeConfiguration::where('fee_type', $feeType)
                ->where('location', $location)
                ->where('is_active', true)
                ->first();

            if ($config) {
                return $config;
            }
        }

        return FeeConfiguration::where('fee_type', $feeType)
            ->whereNull('location')
            ->where('is_active', true)
            ->first();
    }

    private function computeAmount(FeeConfiguration $config, int $salePrice, int $storageDays): float
    {
        return match($config->calculation_type) {
            'flat'       => (float) $config->amount,
            'percentage' => round($salePrice * ((float) $config->amount / 100), 2),
            'per_day'    => round((float) $config->amount * $storageDays, 2),
            'flat_range' => (float) $config->min_amount,  // deposit defaults to minimum
            'tiered'     => $this->computeTieredAmount($config, $salePrice),
            default      => 0.0,
        };
    }

    private function computeTieredAmount(FeeConfiguration $config, int $salePrice): float
    {
        // Load tiers eagerly (already ordered by sort_order, sale_price_from)
        $tier = $config->tiers()
            ->where('sale_price_from', '<=', $salePrice)
            ->where(function ($q) use ($salePrice) {
                $q->whereNull('sale_price_to')
                  ->orWhere('sale_price_to', '>=', $salePrice);
            })
            ->orderByDesc('sale_price_from')
            ->first();

        if (! $tier) {
            return 0.0;
        }

        return match($tier->fee_calculation_type) {
            'flat'       => (float) $tier->fee_amount,
            'percentage' => round($salePrice * ((float) $tier->fee_amount / 100), 2),
            default      => 0.0,
        };
    }
}
