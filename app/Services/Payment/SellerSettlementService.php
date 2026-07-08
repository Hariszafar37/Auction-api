<?php

namespace App\Services\Payment;

use App\Enums\SellerSettlementStatus;
use App\Models\AuctionLot;
use App\Models\PaymentSetting;
use App\Models\SellerSettlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Seller-side financial engine — the mirror of the buyer InvoiceService.
 *
 * A single SellerSettlement row exists per registered lot (unique on lot_id).
 * Lifecycle:
 *   1. seedForRegistration()  — when the vehicle is added to an auction: the
 *      $50 registration fee attaches, status = pending, outcome = null.
 *   2. finalizeSold() / finalizeNoSale() — when the lot closes: commission or
 *      no-sale fee is applied and net proceeds / release date are computed.
 *   3. markReadyForRelease() → issueCheck() → markPaid() — admin check workflow.
 *
 * Every write is idempotent, so re-running generation never duplicates a fee.
 */
class SellerSettlementService
{
    /**
     * Seed a settlement when a vehicle is registered into an auction.
     * Attaches the registration fee exactly once (firstOrCreate on lot_id).
     */
    public function seedForRegistration(AuctionLot $lot): SellerSettlement
    {
        $lot->loadMissing('vehicle');
        $sellerId = $lot->vehicle?->seller_id;

        // A vehicle without a seller (data anomaly) gets no settlement.
        if (! $sellerId) {
            // Return an unsaved instance so callers never hit a null; not persisted.
            return new SellerSettlement();
        }

        $settings = PaymentSetting::current();

        return DB::transaction(function () use ($lot, $sellerId, $settings) {
            $existing = SellerSettlement::where('lot_id', $lot->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            return SellerSettlement::create([
                'settlement_number' => $this->generateSettlementNumber(),
                'lot_id'            => $lot->id,
                'auction_id'        => $lot->auction_id,
                'seller_id'         => $sellerId,
                'vehicle_id'        => $lot->vehicle_id,
                'outcome'           => null,
                'registration_fee'  => $settings->seller_registration_fee,
                'net_proceeds'      => 0,
                'status'            => SellerSettlementStatus::Pending,
            ]);
        });
    }

    /**
     * Finalize a sold lot: apply commission and compute net proceeds + release date.
     * Idempotent — a settlement already finalized (outcome set) is returned as-is.
     */
    public function finalizeSold(AuctionLot $lot): SellerSettlement
    {
        $settlement = $this->seedForRegistration($lot);
        if (! $settlement->exists) {
            return $settlement;
        }
        if ($settlement->isFinalized()) {
            return $settlement;
        }

        $settings   = PaymentSetting::current();
        $salePrice  = (int) $lot->sold_price;
        $commission = $this->commissionFor($salePrice, $settings);

        $lot->loadMissing('auction');
        $releaseDate = $this->releaseDateFor($lot, $settings);

        return DB::transaction(function () use ($settlement, $salePrice, $commission, $releaseDate, $settings) {
            $regFee = (float) $settlement->registration_fee;
            $net    = round($salePrice - $commission - $regFee + (float) $settlement->adjustments_total, 2);

            $settlement->update([
                'outcome'           => 'sold',
                'sale_price'        => $salePrice,
                'commission_amount' => $commission,
                'no_sale_fee'       => 0,
                'net_proceeds'      => $net,
                'release_date'      => $releaseDate,
                'status'            => SellerSettlementStatus::Pending,
                'fee_snapshot'      => $this->snapshot($settings),
            ]);

            return $settlement->fresh();
        });
    }

    /**
     * Finalize a lot that did not sell: apply the no-sale fee.
     * Net proceeds go negative (registration + no-sale fees owed by the seller).
     * Idempotent.
     */
    public function finalizeNoSale(AuctionLot $lot): SellerSettlement
    {
        $settlement = $this->seedForRegistration($lot);
        if (! $settlement->exists) {
            return $settlement;
        }
        if ($settlement->isFinalized()) {
            return $settlement;
        }

        $settings  = PaymentSetting::current();
        $noSaleFee = (float) $settings->seller_no_sale_fee;

        return DB::transaction(function () use ($settlement, $noSaleFee, $settings) {
            $regFee = (float) $settlement->registration_fee;
            $net    = round(-1 * ($regFee + $noSaleFee) + (float) $settlement->adjustments_total, 2);

            $settlement->update([
                'outcome'           => 'no_sale',
                'sale_price'        => null,
                'commission_amount' => 0,
                'no_sale_fee'       => $noSaleFee,
                'net_proceeds'      => $net,
                'status'            => SellerSettlementStatus::NoSale,
                'fee_snapshot'      => $this->snapshot($settings),
            ]);

            return $settlement->fresh();
        });
    }

    /**
     * Void a seeded settlement whose lot was removed or whose auction was
     * cancelled before finalization. Never voids a finalized (sold/no-sale) row.
     */
    public function voidForLot(AuctionLot $lot): void
    {
        $settlement = SellerSettlement::where('lot_id', $lot->id)->first();
        if ($settlement && ! $settlement->isFinalized()) {
            $settlement->update(['status' => SellerSettlementStatus::Void]);
        }
    }

    // ─── Admin check workflow ─────────────────────────────────────────────────────

    public function markReadyForRelease(SellerSettlement $settlement): SellerSettlement
    {
        if (! $settlement->isSold()) {
            throw ValidationException::withMessages([
                'settlement' => ['Only a finalized sold settlement can be released.'],
            ]);
        }

        $this->assertTransition($settlement, SellerSettlementStatus::ReadyForRelease);

        $settlement->update([
            'status'      => SellerSettlementStatus::ReadyForRelease,
            'released_at' => now(),
        ]);

        return $settlement->fresh();
    }

    public function issueCheck(SellerSettlement $settlement, ?string $checkNumber): SellerSettlement
    {
        $this->assertTransition($settlement, SellerSettlementStatus::CheckIssued);

        $settlement->update([
            'status'          => SellerSettlementStatus::CheckIssued,
            'check_number'    => $checkNumber,
            'check_issued_at' => now(),
        ]);

        return $settlement->fresh();
    }

    public function markPaid(SellerSettlement $settlement, ?string $paidAt = null): SellerSettlement
    {
        $this->assertTransition($settlement, SellerSettlementStatus::Paid);

        $settlement->update([
            'status'  => SellerSettlementStatus::Paid,
            'paid_at' => $paidAt ? \Illuminate\Support\Carbon::parse($paidAt) : now(),
        ]);

        return $settlement->fresh();
    }

    /**
     * Interim no-sale fee collection: mark a no-sale settlement's outstanding
     * fees as collected from the seller, recording how and by whom. (Automated
     * deduction from a future payout is a later phase.)
     */
    public function markCollected(
        SellerSettlement $settlement,
        ?User $collector = null,
        ?string $method = null,
        ?string $reference = null,
        ?string $collectedAt = null,
    ): SellerSettlement {
        $this->assertTransition($settlement, SellerSettlementStatus::Collected);

        $settlement->update([
            'status'               => SellerSettlementStatus::Collected,
            'collected_at'         => $collectedAt ? \Illuminate\Support\Carbon::parse($collectedAt) : now(),
            'collection_method'    => $method,
            'collection_reference' => $reference,
            'collected_by'         => $collector?->id,
        ]);

        return $settlement->fresh();
    }

    /**
     * Apply a signed manual adjustment to a settlement (e.g. a deduction for
     * carried no-sale fees, or a discretionary credit). Recorded as an immutable
     * audit row; net proceeds are re-derived from the fee lines + all adjustments.
     */
    public function applyAdjustment(
        SellerSettlement $settlement,
        float $amount,
        string $reason,
        User $admin,
    ): SellerSettlement {
        if (in_array($settlement->status, [SellerSettlementStatus::Void], true)) {
            throw ValidationException::withMessages([
                'settlement' => ['Adjustments cannot be applied to a void settlement.'],
            ]);
        }
        if (! $settlement->isFinalized()) {
            throw ValidationException::withMessages([
                'settlement' => ['Adjustments can only be applied once the settlement is finalized.'],
            ]);
        }

        return DB::transaction(function () use ($settlement, $amount, $reason, $admin) {
            $settlement->adjustments()->create([
                'amount'     => $amount,
                'reason'     => $reason,
                'created_by' => $admin->id,
            ]);

            $settlement->recalculateNet();

            return $settlement->fresh();
        });
    }

    /**
     * Aggregate KPI figures over a (already-filtered, already-cloneable) query.
     * Finalized settlements only — seeded/void rows never count toward money.
     */
    public function summarize(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $base = (clone $query)->whereNotNull('outcome');

        $sold    = (clone $base)->where('outcome', 'sold');
        $noSale  = (clone $base)->where('outcome', 'no_sale');

        return [
            'total_settlements'    => (clone $base)->count(),
            'sold_count'           => (clone $sold)->count(),
            'no_sale_count'        => (clone $noSale)->count(),
            'total_gross_sales'    => round((float) (clone $sold)->sum('sale_price'), 2),
            'total_commission'     => round((float) (clone $sold)->sum('commission_amount'), 2),
            'total_registration'   => round((float) (clone $base)->sum('registration_fee'), 2),
            'total_net_proceeds'   => round((float) (clone $sold)->sum('net_proceeds'), 2),
            // Money still owed to sellers, by payout stage
            'pending_payout'       => round((float) (clone $sold)
                ->whereIn('status', [SellerSettlementStatus::Pending->value, SellerSettlementStatus::ReadyForRelease->value])
                ->sum('net_proceeds'), 2),
            'ready_for_release'    => round((float) (clone $sold)
                ->where('status', SellerSettlementStatus::ReadyForRelease->value)
                ->sum('net_proceeds'), 2),
            'check_issued_amount'  => round((float) (clone $sold)
                ->where('status', SellerSettlementStatus::CheckIssued->value)
                ->sum('net_proceeds'), 2),
            'paid_amount'          => round((float) (clone $sold)
                ->where('status', SellerSettlementStatus::Paid->value)
                ->sum('net_proceeds'), 2),
            // No-sale fees (stored as negative net); reported as positive owed/collected
            'no_sale_fees_outstanding' => round((float) (clone $noSale)
                ->where('status', SellerSettlementStatus::NoSale->value)
                ->sum('net_proceeds') * -1, 2),
            'no_sale_fees_collected'   => round((float) (clone $noSale)
                ->where('status', SellerSettlementStatus::Collected->value)
                ->sum('net_proceeds') * -1, 2),
        ];
    }

    // ─── Private helpers ──────────────────────────────────────────────────────────

    /**
     * Commission rule:
     *   sale price > threshold  → rate% of sale price
     *   sale price <= threshold → flat fee
     */
    public function commissionFor(int $salePrice, PaymentSetting $settings): float
    {
        if ($salePrice > (int) $settings->seller_commission_threshold) {
            return round($salePrice * ((float) $settings->seller_commission_rate / 100), 2);
        }

        return (float) $settings->seller_commission_flat;
    }

    private function releaseDateFor(AuctionLot $lot, PaymentSetting $settings): \Illuminate\Support\Carbon
    {
        $auctionDate = $lot->auction?->ends_at
            ?? $lot->auction?->starts_at
            ?? now();

        return \Illuminate\Support\Carbon::parse($auctionDate)
            ->addDays((int) $settings->seller_release_days)
            ->startOfDay();
    }

    private function snapshot(PaymentSetting $settings): array
    {
        return [
            'registration_fee'     => (float) $settings->seller_registration_fee,
            'commission_rate'      => (float) $settings->seller_commission_rate,
            'commission_threshold' => (int) $settings->seller_commission_threshold,
            'commission_flat'      => (float) $settings->seller_commission_flat,
            'no_sale_fee'          => (float) $settings->seller_no_sale_fee,
            'release_days'         => (int) $settings->seller_release_days,
        ];
    }

    private function assertTransition(SellerSettlement $settlement, SellerSettlementStatus $target): void
    {
        if (! $settlement->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition settlement from {$settlement->status->value} to {$target->value}."],
            ]);
        }
    }

    private function generateSettlementNumber(): string
    {
        $year = now()->year;
        $last = SellerSettlement::whereYear('created_at', $year)->lockForUpdate()->count();
        $seq  = str_pad($last + 1, 5, '0', STR_PAD_LEFT);

        return "SS-{$year}-{$seq}";
    }
}
