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
use App\Models\Location;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Payment\SellerSettlementService;
use App\Support\AuctionTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuctionService
{
    public function __construct(
        private readonly AuctionLotService $lotService,
        private readonly SellerSettlementService $settlements,
    ) {}

    // ─── Auction CRUD ────────────────────────────────────────────────────────────

    public function createAuction(array $data, User $creator): Auction
    {
        [$locationId, $locationStr] = $this->resolveLocation($data);

        return Auction::create([
            'title'            => $data['title'],
            'description'      => $data['description'] ?? null,
            'location'         => $locationStr,
            'location_id'      => $locationId,
            'timezone'         => $data['timezone'] ?? 'America/New_York',
            'starts_at'        => $data['starts_at'],
            'scheduled_end_at' => $data['scheduled_end_at'] ?? null,
            'status'           => AuctionStatus::Draft,
            'created_by'       => $creator->id,
            'notes'            => $data['notes'] ?? null,
        ]);
    }

    public function updateAuction(Auction $auction, array $data): Auction
    {
        $isLive = $auction->status === AuctionStatus::Live;

        if (! $isLive && ! in_array($auction->status, [AuctionStatus::Draft, AuctionStatus::Scheduled])) {
            throw ValidationException::withMessages([
                'auction' => ['Only draft, scheduled or live auctions can be updated.'],
            ]);
        }

        // A live auction is mid-sale: only its closing time may still be moved.
        // Everything else (title, location, start time) is frozen once bidding
        // is under way.
        if ($isLive) {
            $auction->update($this->resolveScheduledEnd($auction, $data));

            return $auction->fresh();
        }

        $updates = array_filter([
            'title'     => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'timezone'  => $data['timezone'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'notes'     => $data['notes'] ?? null,
        ], fn ($v) => $v !== null);

        // location_id takes precedence over free-text location string
        if (isset($data['location_id'])) {
            $location = Location::find($data['location_id']);
            $updates['location_id'] = $data['location_id'];
            $updates['location']    = $location?->name ?? $auction->location;
        } elseif (isset($data['location'])) {
            $updates['location']    = $data['location'];
            $updates['location_id'] = null;
        }

        $updates += $this->resolveScheduledEnd($auction, $data);
        $updates += $this->reinterpretOnTimezoneChange($auction, $data, $updates);

        $auction->update($updates);

        return $auction->fresh();
    }

    /**
     * Keep the wall clock when only the timezone changes.
     *
     * An admin who switches the dropdown from Eastern to Pacific without
     * retyping the times means "10:00 PM Pacific" — the form still shows 10:00
     * PM, so that had better be what it means. Holding the instant instead
     * would silently redisplay the auction as 7:00 PM.
     *
     * Only fills fields the client did not send: anything submitted alongside
     * the new zone was already converted with it in the FormRequest.
     *
     * @return array<string, string|null>
     */
    private function reinterpretOnTimezoneChange(Auction $auction, array $data, array $updates): array
    {
        $newTz = $updates['timezone'] ?? null;

        if (! $newTz || $newTz === $auction->timezone) {
            return [];
        }

        $carried = [];

        if (! array_key_exists('starts_at', $updates) && $auction->starts_at) {
            $carried['starts_at'] = AuctionTime::reinterpret(
                $auction->starts_at, $auction->timezone, $newTz,
            );
        }

        if (! array_key_exists('scheduled_end_at', $data) && $auction->scheduled_end_at) {
            $carried['scheduled_end_at'] = AuctionTime::reinterpret(
                $auction->scheduled_end_at, $auction->timezone, $newTz,
            );
        }

        return $carried;
    }

    /**
     * Validate and normalise a scheduled_end_at change.
     *
     * Returns [] when the key was absent (leave the stored value alone), or
     * ['scheduled_end_at' => <value|null>] when it was supplied — sending null
     * explicitly clears the schedule.
     *
     * @throws ValidationException closing time is not after the auction start
     */
    private function resolveScheduledEnd(Auction $auction, array $data): array
    {
        if (! array_key_exists('scheduled_end_at', $data)) {
            return [];
        }

        $value = $data['scheduled_end_at'];

        if ($value === null) {
            return ['scheduled_end_at' => null];
        }

        $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $auction->starts_at;

        if ($startsAt && Carbon::parse($value)->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'scheduled_end_at' => ['The closing time must be after the auction start time.'],
            ]);
        }

        return ['scheduled_end_at' => $value];
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

        $this->openPendingLots($auction);

        broadcast(new AuctionStarted($auction));

        return $auction->fresh();
    }

    /**
     * Open every lot still sitting in pending so bidding can begin.
     *
     * Called the moment an auction goes live — whether an admin pressed Start
     * or the scheduler transitioned it — because a pending lot accepts no bids
     * and cannot enter its countdown.
     *
     * Done as one mass update rather than a loop over openLot(). LotStatusChanged
     * is ShouldBroadcastNow, so opening lots individually would fire one
     * synchronous Reverb call per lot inside the request that started the
     * auction. It would also be redundant: clients refetch the whole lot list
     * when the AuctionStarted broadcast that follows this call arrives.
     *
     * @return int number of lots opened
     */
    public function openPendingLots(Auction $auction): int
    {
        return $auction->lots()
            ->where('status', LotStatus::Pending->value)
            ->update([
                'status'    => LotStatus::Open->value,
                'opened_at' => now(),
            ]);
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
                    // Cancelled before finalization — void the seeded settlement.
                    $this->settlements->voidForLot($lot);
                });

            $auction->update(['status' => AuctionStatus::Cancelled]);
        });

        return $auction->fresh();
    }

    // ─── Lot management (admin) ──────────────────────────────────────────────────

    public function addLot(Auction $auction, Vehicle $vehicle, array $data, ?User $requestingUser = null): AuctionLot
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

        // ── Load seller for compliance checks ────────────────────────────────────
        $seller = $vehicle->seller()->with('dealerProfile')->first();
        $isAdmin = $requestingUser && $requestingUser->hasRole('admin');

        if (! $isAdmin) {
            // POA check: all seller-enabled accounts (individual, dealer, business) need an approved POA
            if ($seller && $seller->hasSellIntent()) {
                if (! $seller->hasApprovedPoa()) {
                    throw ValidationException::withMessages([
                        'poa' => ['An approved Power of Attorney is required before submitting a vehicle to auction.'],
                    ]);
                }
            }

            // Dealer classification compliance
            if ($seller && $seller->dealerProfile) {
                $cls = $seller->dealerProfile->dealer_classification;

                if ($cls === 'maryland_retail') {
                    if (! $vehicle->title_received) {
                        throw ValidationException::withMessages([
                            'vehicle' => ['Maryland Retail: title must be received before listing.'],
                        ]);
                    }
                    if (! $seller->dealerProfile->inspection_passed) {
                        throw ValidationException::withMessages([
                            'vehicle' => ['Maryland Retail: vehicle inspection must be passed before listing.'],
                        ]);
                    }
                } elseif ($cls === 'out_of_state_retail') {
                    if (! $vehicle->title_received) {
                        throw ValidationException::withMessages([
                            'vehicle' => ['Out-of-State Retail: title must be received before listing.'],
                        ]);
                    }
                    if (! $seller->dealerProfile->bill_of_sale_received) {
                        throw ValidationException::withMessages([
                            'vehicle' => ['Out-of-State Retail: bill of sale must be received before listing.'],
                        ]);
                    }
                }
                // Wholesale: no pre-listing compliance required
            }
        }

        // ── Determine dealer_only flag ────────────────────────────────────────────
        $dealerOnly = false;
        if ($seller && $seller->dealerProfile) {
            if ($seller->dealerProfile->can_sell_to_public === false) {
                $dealerOnly = true;
            } elseif (in_array($seller->dealerProfile->dealer_classification, ['maryland_wholesale', 'out_of_state_wholesale'], true)) {
                $dealerOnly = true;
            }
        }

        return DB::transaction(function () use ($auction, $vehicle, $data, $dealerOnly): AuctionLot {
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
                'scheduled_close_at'       => $data['scheduled_close_at'] ?? null,
                'requires_seller_approval' => $data['requires_seller_approval'] ?? false,
                'dealer_only'              => $dealerOnly,
                'status'                   => LotStatus::Pending,
            ]);

            $vehicle->markAsInAuction();

            // Seller financials begin here: seed the settlement so the $50
            // registration fee attaches exactly once at registration time.
            $this->settlements->seedForRegistration($lot);

            // Notify any subscribers who signed up for "Notify Me" on this vehicle.
            NotifyVehicleSubscribers::dispatch($vehicle->id, $auction->id);

            return $lot->load(['vehicle', 'auction']);
        });
    }

    public function updateLot(AuctionLot $lot, array $data): AuctionLot
    {
        // Once a lot has closed nothing about it may change. While it is open or
        // counting down only its scheduled closing time can still be moved — the
        // money fields (starting bid, reserve) are frozen the moment bidding can
        // begin.
        if ($lot->isTerminal()) {
            throw ValidationException::withMessages([
                'lot' => ['A closed lot can no longer be updated.'],
            ]);
        }

        $updates = [];

        if ($lot->status === LotStatus::Pending) {
            $updates = array_filter([
                'lot_number'               => $data['lot_number'] ?? null,
                'starting_bid'             => $data['starting_bid'] ?? null,
                'reserve_price'            => $data['reserve_price'] ?? null,
                'countdown_seconds'        => $data['countdown_seconds'] ?? null,
                'requires_seller_approval' => $data['requires_seller_approval'] ?? null,
            ], fn ($v) => $v !== null);
        }

        // Sending the key with null explicitly clears the lot's own schedule,
        // dropping it back to the auction-wide closing time.
        if (array_key_exists('scheduled_close_at', $data)) {
            $updates['scheduled_close_at'] = $data['scheduled_close_at'];
        }

        if ($updates !== []) {
            $lot->update($updates);
        }

        return $lot->fresh(['vehicle', 'auction']);
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

        // Never went to auction — reverse the seeded registration fee.
        $this->settlements->voidForLot($lot);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    /** Returns [location_id, location_string] pair from validated request data. */
    private function resolveLocation(array $data): array
    {
        $locationId = $data['location_id'] ?? null;
        if ($locationId !== null) {
            $location = Location::find($locationId);
            return [$locationId, $location?->name];
        }
        return [null, $data['location'] ?? null];
    }

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
