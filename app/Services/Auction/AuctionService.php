<?php

namespace App\Services\Auction;

use App\Enums\AuctionStatus;
use App\Enums\LotStatus;
use App\Events\Auction\AuctionEnded;
use App\Events\Auction\AuctionStarted;
use App\Events\Auction\LotStatusChanged;
use App\Jobs\Auction\NotifyVehicleSubscribers;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuctionService
{
    public function __construct(
        private readonly AuctionLotService $lotService,
    ) {}

    // ─── Auction CRUD ────────────────────────────────────────────────────────────

    public function createAuction(array $data, User $creator): Auction
    {
        return Auction::create([
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'location'    => $data['location'] ?? null,
            'timezone'    => $data['timezone'] ?? 'America/New_York',
            'starts_at'   => $data['starts_at'],
            'status'      => AuctionStatus::Draft,
            'created_by'  => $creator->id,
            'notes'       => $data['notes'] ?? null,
        ]);
    }

    public function updateAuction(Auction $auction, array $data): Auction
    {
        if (! in_array($auction->status, [AuctionStatus::Draft, AuctionStatus::Scheduled])) {
            throw ValidationException::withMessages([
                'auction' => ['Only draft or scheduled auctions can be updated.'],
            ]);
        }

        $auction->update(array_filter([
            'title'       => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'location'    => $data['location'] ?? null,
            'timezone'    => $data['timezone'] ?? null,
            'starts_at'   => $data['starts_at'] ?? null,
            'notes'       => $data['notes'] ?? null,
        ], fn ($v) => $v !== null));

        return $auction->fresh();
    }

    // ─── Lifecycle transitions ───────────────────────────────────────────────────

    public function publishAuction(Auction $auction): Auction
    {
        if (! $auction->status->canTransitionTo(AuctionStatus::Scheduled)) {
            throw ValidationException::withMessages([
                'auction' => ['Auction cannot be published from its current state.'],
            ]);
        }

        if ($auction->lots()->count() === 0) {
            throw ValidationException::withMessages([
                'auction' => ['Cannot publish an auction with no lots.'],
            ]);
        }

        $auction->update(['status' => AuctionStatus::Scheduled]);

        return $auction->fresh();
    }

    public function startAuction(Auction $auction): Auction
    {
        if (! $auction->status->canTransitionTo(AuctionStatus::Live)) {
            throw ValidationException::withMessages([
                'auction' => ['Auction cannot be started from its current state.'],
            ]);
        }

        $auction->update(['status' => AuctionStatus::Live]);

        broadcast(new AuctionStarted($auction));

        return $auction->fresh();
    }

    public function endAuction(Auction $auction): Auction
    {
        if (! $auction->status->canTransitionTo(AuctionStatus::Ended)) {
            throw ValidationException::withMessages([
                'auction' => ['Auction cannot be ended from its current state.'],
            ]);
        }

        DB::transaction(function () use ($auction) {
            // Close all still-open/countdown lots
            $auction->lots()
                ->whereIn('status', [LotStatus::Open->value, LotStatus::Countdown->value])
                ->each(fn (AuctionLot $lot) => $this->lotService->closeLot($lot));

            $auction->update([
                'status'  => AuctionStatus::Ended,
                'ends_at' => now(),
            ]);
        });

        $summary = $this->buildAuctionSummary($auction->fresh());
        broadcast(new AuctionEnded($auction->fresh(), $summary));

        return $auction->fresh();
    }

    public function cancelAuction(Auction $auction): Auction
    {
        if (! $auction->status->canTransitionTo(AuctionStatus::Cancelled)) {
            throw ValidationException::withMessages([
                'auction' => ['Auction cannot be cancelled from its current state.'],
            ]);
        }

        DB::transaction(function () use ($auction) {
            // Release all vehicles back to available
            $auction->lots()
                ->with('vehicle')
                ->whereNotIn('status', [LotStatus::Sold->value])
                ->each(function (AuctionLot $lot) {
                    $lot->update(['status' => LotStatus::Cancelled]);
                    $lot->vehicle?->markAsAvailable();
                });

            $auction->update(['status' => AuctionStatus::Cancelled]);
        });

        return $auction->fresh();
    }

    // ─── Lot management (admin) ──────────────────────────────────────────────────

    public function addLot(Auction $auction, Vehicle $vehicle, array $data): AuctionLot
    {
        if (! in_array($auction->status, [AuctionStatus::Draft, AuctionStatus::Scheduled])) {
            throw ValidationException::withMessages([
                'auction' => ['Lots can only be added to draft or scheduled auctions.'],
            ]);
        }

        if (! $vehicle->isAvailable()) {
            throw ValidationException::withMessages([
                'vehicle_id' => ['This vehicle is not available for auction.'],
            ]);
        }

        return DB::transaction(function () use ($auction, $vehicle, $data): AuctionLot {
            // Re-check availability inside the transaction to prevent a race condition
            // where two concurrent requests both pass the check above simultaneously.
            if (! $vehicle->fresh()->isAvailable()) {
                throw ValidationException::withMessages([
                    'vehicle_id' => ['This vehicle is not available for auction.'],
                ]);
            }

            // Determine next lot number (inside transaction — serialises concurrent inserts)
            $nextLotNumber = ($auction->lots()->max('lot_number') ?? 0) + 1;

            $lot = AuctionLot::create([
                'auction_id'               => $auction->id,
                'vehicle_id'               => $vehicle->id,
                'lot_number'               => $data['lot_number'] ?? $nextLotNumber,
                'starting_bid'             => $data['starting_bid'] ?? 100,
                'reserve_price'            => $data['reserve_price'] ?? null,
                'countdown_seconds'        => $data['countdown_seconds'] ?? 30,
                'requires_seller_approval' => $data['requires_seller_approval'] ?? false,
                'status'                   => LotStatus::Pending,
            ]);

            $vehicle->markAsInAuction();

            // Notify any subscribers who signed up for "Notify Me" on this vehicle.
            NotifyVehicleSubscribers::dispatch($vehicle->id, $auction->id);

            return $lot->load('vehicle');
        });
    }

    public function updateLot(AuctionLot $lot, array $data): AuctionLot
    {
        if (! in_array($lot->status, [LotStatus::Pending])) {
            throw ValidationException::withMessages([
                'lot' => ['Only pending lots can be updated.'],
            ]);
        }

        $lot->update(array_filter([
            'lot_number'               => $data['lot_number'] ?? null,
            'starting_bid'             => $data['starting_bid'] ?? null,
            'reserve_price'            => $data['reserve_price'] ?? null,
            'countdown_seconds'        => $data['countdown_seconds'] ?? null,
            'requires_seller_approval' => $data['requires_seller_approval'] ?? null,
        ], fn ($v) => $v !== null));

        return $lot->fresh('vehicle');
    }

    public function removeLot(AuctionLot $lot): void
    {
        if (! in_array($lot->status, [LotStatus::Pending, LotStatus::Cancelled])) {
            throw ValidationException::withMessages([
                'lot' => ['Only pending lots can be removed.'],
            ]);
        }

        $lot->vehicle?->markAsAvailable();
        $lot->update(['status' => LotStatus::Cancelled]);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    private function buildAuctionSummary(Auction $auction): array
    {
        $lots      = $auction->lots;
        $soldLots  = $lots->where('status', LotStatus::Sold);
        $totalSold = $soldLots->sum('sold_price');

        return [
            'total_lots'     => $lots->count(),
            'sold_count'     => $soldLots->count(),
            'no_sale_count'  => $lots->where('status', LotStatus::NoSale)->count(),
            'if_sale_count'  => $lots->where('status', LotStatus::IfSale)->count(),
            'cancelled_count'=> $lots->where('status', LotStatus::Cancelled)->count(),
            'total_sold_value' => $totalSold,
        ];
    }
}
