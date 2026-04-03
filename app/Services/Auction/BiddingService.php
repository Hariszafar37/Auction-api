<?php

namespace App\Services\Auction;

use App\Enums\BidType;
use App\Enums\LotStatus;
use App\Events\Auction\BidPlaced;
use App\Events\Auction\CountdownExtended;
use App\Events\Auction\LotStatusChanged;
use App\Events\Auction\OutbidNotification;
use App\Models\AuctionLot;
use App\Models\Bid;
use App\Models\BidIncrement;
use App\Models\ProxyBid;
use App\Models\User;
use App\Notifications\OutbidEmailNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BiddingService
{
    public function __construct(
        private readonly ProxyBidService $proxyBidService,
    ) {}

    // ─── Manual Bid ─────────────────────────────────────────────────────────────

    /**
     * Place a manual bid on behalf of a user.
     *
     * @throws ValidationException
     */
    public function placeBid(AuctionLot $lot, User $user, int $amount): Bid
    {
        return DB::transaction(function () use ($lot, $user, $amount) {
            // Lock the lot to prevent race conditions
            $lot = AuctionLot::lockForUpdate()->find($lot->id);

            $this->validateBid($lot, $user, $amount);

            $previousWinnerId = $lot->current_winner_id;

            // Deactivate current winning bid
            Bid::query()
                ->where('auction_lot_id', $lot->id)
                ->where('is_winning', true)
                ->update(['is_winning' => false]);

            $bid = Bid::create([
                'auction_lot_id' => $lot->id,
                'user_id'        => $user->id,
                'amount'         => $amount,
                'type'           => BidType::Manual,
                'is_winning'     => true,
                'ip_address'     => request()->ip(),
                'placed_at'      => now(),
            ]);

            $lot->update([
                'current_bid'       => $amount,
                'current_winner_id' => $user->id,
                'bid_count'         => $lot->bid_count + 1,
            ]);

            $lot->refresh();

            // Capture bid_count after the manual bid so we can detect proxy auto-bids below
            $bidCountAfterManual = $lot->bid_count;

            // Anti-sniping: manual bid during countdown → extend timer
            if ($lot->status === LotStatus::Countdown) {
                $this->extendCountdown($lot);
                $lot->refresh();
            }

            // Broadcast new bid to all watchers
            broadcast(new BidPlaced($lot, $bid));

            // Notify previous winner they've been outbid (real-time broadcast + email)
            if ($previousWinnerId && $previousWinnerId !== $user->id) {
                $outbidUser = User::find($previousWinnerId);
                if ($outbidUser) {
                    broadcast(new OutbidNotification($lot, $outbidUser));
                    $outbidUser->notify(new OutbidEmailNotification($lot, $amount));
                }
            }

            // Trigger proxy resolution — a competing proxy may auto-bid above the manual bid
            $this->proxyBidService->resolveAfterManualBid($lot->fresh(), $user);

            $lot->refresh();

            // Anti-sniping: if a proxy auto-bid landed during countdown, extend the timer again
            if ($lot->status === LotStatus::Countdown && $lot->bid_count > $bidCountAfterManual) {
                $this->extendCountdown($lot);
            }

            return $bid;
        });
    }

    // ─── Proxy Bid ──────────────────────────────────────────────────────────────

    /**
     * Set or update a proxy (max) bid for a user.
     *
     * @throws ValidationException
     */
    public function setProxyBid(AuctionLot $lot, User $user, int $maxAmount): array
    {
        $this->validateProxyBid($lot, $user, $maxAmount);

        $previousWinnerId = $lot->current_winner_id;

        $bid = $this->proxyBidService->setProxyBid($lot, $user, $maxAmount);

        $lot->refresh();

        // Anti-sniping: proxy bid resolution during countdown counts as a new bid — extend timer
        if ($lot->status === LotStatus::Countdown) {
            $this->extendCountdown($lot);
            $lot->refresh();
        }

        // Broadcast the resulting bid
        broadcast(new BidPlaced($lot, $bid));

        // If someone was outbid by proxy resolution, notify them (real-time broadcast + email)
        $outbidUser = $bid->getAttribute('_outbid_user');
        if ($outbidUser) {
            broadcast(new OutbidNotification($lot, $outbidUser));
            $outbidUser->notify(new OutbidEmailNotification($lot, $lot->current_bid));
        } elseif ($previousWinnerId && $previousWinnerId !== $lot->current_winner_id) {
            $outbid = User::find($previousWinnerId);
            if ($outbid) {
                broadcast(new OutbidNotification($lot, $outbid));
                $outbid->notify(new OutbidEmailNotification($lot, $lot->current_bid));
            }
        }

        return [
            'lot'        => $lot->fresh(),
            'bid'        => $bid,
            'is_winning' => $lot->current_winner_id === $user->id,
        ];
    }

    // ─── Validation ─────────────────────────────────────────────────────────────

    /**
     * @throws ValidationException
     */
    private function validateBid(AuctionLot $lot, User $user, int $amount): void
    {
        $errors = [];

        if (! $lot->status->isActive()) {
            $errors['lot'] = ['This lot is not currently accepting bids.'];
        }

        if ($lot->vehicle->seller_id === $user->id) {
            $errors['lot'] = ['You cannot bid on your own vehicle.'];
        }

        $minBid = $lot->nextMinimumBid();
        if ($amount < $minBid) {
            $errors['amount'] = ["Minimum bid is \${$minBid}."];
        }

        // Dealer-only lot: only users with dealer or admin role may bid
        if ($lot->dealer_only) {
            if (! $user->hasRole('dealer') && ! $user->hasRole('admin')) {
                $errors['lot'] = ['This lot is available to approved dealers only.'];
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @throws ValidationException
     */
    private function validateProxyBid(AuctionLot $lot, User $user, int $maxAmount): void
    {
        $errors = [];

        if ($lot->status->isTerminal()) {
            $errors['lot'] = ['This lot is no longer accepting bids.'];
        }

        if ($lot->vehicle->seller_id === $user->id) {
            $errors['lot'] = ['You cannot bid on your own vehicle.'];
        }

        $minBid = $lot->nextMinimumBid();
        if ($maxAmount < $minBid) {
            $errors['max_amount'] = ["Max bid must be at least \${$minBid}."];
        }

        // If user already has a proxy, the new max must be higher
        $existing = ProxyBid::query()
            ->where('auction_lot_id', $lot->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if ($existing && $maxAmount <= $existing->max_amount) {
            $errors['max_amount'] = ['New max bid must be higher than your current max bid.'];
        }

        // Dealer-only lot: only users with dealer or admin role may bid
        if ($lot->dealer_only) {
            if (! $user->hasRole('dealer') && ! $user->hasRole('admin')) {
                $errors['lot'] = ['This lot is available to approved dealers only.'];
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    // ─── Countdown helpers ──────────────────────────────────────────────────────

    private function extendCountdown(AuctionLot $lot): void
    {
        $lot->update([
            'countdown_ends_at'    => now()->addSeconds($lot->countdown_seconds),
            'countdown_extensions' => $lot->countdown_extensions + 1,
        ]);

        broadcast(new CountdownExtended($lot->fresh()));
    }

    /**
     * Transition an open lot into countdown mode (called by auctioneer or auto-trigger).
     */
    public function startCountdown(AuctionLot $lot): void
    {
        if ($lot->status !== LotStatus::Open) {
            throw ValidationException::withMessages([
                'lot' => ['Lot must be open to start countdown.'],
            ]);
        }

        $previous = $lot->status->value;

        $lot->update([
            'status'           => LotStatus::Countdown,
            'countdown_ends_at'=> now()->addSeconds($lot->countdown_seconds),
        ]);

        broadcast(new LotStatusChanged($lot->fresh(), $previous));
    }
}
