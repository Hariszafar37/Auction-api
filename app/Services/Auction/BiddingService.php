<?php

namespace App\Services\Auction;

use App\Enums\BidType;
use App\Enums\LotStatus;
use App\Events\Auction\BidPlaced;
use App\Events\Auction\CountdownExtended;
use App\Events\Auction\LotStatusChanged;
use App\Events\Auction\OutbidNotification;
use App\Exceptions\BidNotAllowedException;
use App\Models\AuctionLot;
use App\Models\Bid;
use App\Models\BidIncrement;
use App\Models\ProxyBid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BiddingService
{
    public function __construct(
        private readonly ProxyBidService $proxyBidService,
        private readonly AuctionTermsService $auctionTerms,
    ) {}

    // ─── Manual Bid ─────────────────────────────────────────────────────────────

    /**
     * Place a manual bid on behalf of a user.
     *
     * @throws ValidationException
     * @throws BidNotAllowedException
     */
    public function placeBid(AuctionLot $lot, User $user, int $amount): Bid
    {
        // Unified eligibility gate — delegates to User::canBid() so the rule
        // set stays in one place. Admins never place real bids via this path.
        $this->requireBidEligibility($user);

        // Auction-scoped entry gate — bypass-proof Terms acceptance check.
        $this->requireTermsAccepted($lot, $user);

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

            // Dispatch BidPlaced event — broadcasts to watchers + triggers seller notification listener.
            event(new BidPlaced($lot, $bid));

            // Dispatch OutbidNotification event — broadcasts to outbid user in real-time
            // AND triggers SendOutbidEmailNotification listener for email + DB notification.
            if ($previousWinnerId && $previousWinnerId !== $user->id) {
                $outbidUser = User::find($previousWinnerId);
                if ($outbidUser) {
                    event(new OutbidNotification($lot, $outbidUser));
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
     * @throws BidNotAllowedException
     */
    public function setProxyBid(AuctionLot $lot, User $user, int $maxAmount): array
    {
        $this->requireBidEligibility($user);

        // Auction-scoped entry gate — bypass-proof Terms acceptance check.
        $this->requireTermsAccepted($lot, $user);

        $this->validateProxyBid($lot, $user, $maxAmount);

        $previousWinnerId = $lot->current_winner_id;

        $bid = $this->proxyBidService->setProxyBid($lot, $user, $maxAmount);

        $lot->refresh();

        // Anti-sniping: proxy bid resolution during countdown counts as a new bid — extend timer
        if ($lot->status === LotStatus::Countdown) {
            $this->extendCountdown($lot);
            $lot->refresh();
        }

        // Dispatch BidPlaced — broadcasts to watchers + triggers seller notification listener.
        event(new BidPlaced($lot, $bid));

        // Dispatch OutbidNotification — broadcasts in real-time + triggers email/DB listener.
        $outbidUser = $bid->getAttribute('_outbid_user');
        if ($outbidUser) {
            event(new OutbidNotification($lot, $outbidUser));
        } elseif ($previousWinnerId && $previousWinnerId !== $lot->current_winner_id) {
            $outbid = User::find($previousWinnerId);
            if ($outbid) {
                event(new OutbidNotification($lot, $outbid));
            }
        }

        return [
            'lot'        => $lot->fresh(),
            'bid'        => $bid,
            'is_winning' => $lot->current_winner_id === $user->id,
        ];
    }

    // ─── Eligibility gate ───────────────────────────────────────────────────────

    /**
     * Unified pre-bid check. Delegates to User::canBid() and throws a
     * reason-carrying BidNotAllowedException so the frontend can route the
     * user to the correct remediation (payment page, support, etc.).
     *
     * @throws BidNotAllowedException
     */
    private function requireBidEligibility(User $user): void
    {
        if ($user->canBid()) {
            return;
        }

        throw new BidNotAllowedException(
            reason: $user->getBidIneligibilityReason() ?? 'not_eligible',
        );
    }

    /**
     * Auction-scoped entry gate. A participant must have accepted the current
     * Terms version for this lot's auction before any bid is accepted. This is
     * the server-side enforcement that prevents bypassing the frontend modal
     * by hitting the bid endpoints directly.
     *
     * @throws BidNotAllowedException
     */
    private function requireTermsAccepted(AuctionLot $lot, User $user): void
    {
        if (! $this->auctionTerms->requiresTerms($user)) {
            return;
        }

        if ($this->auctionTerms->hasAcceptedCurrent($user, $lot->auction)) {
            return;
        }

        throw new BidNotAllowedException(reason: 'terms_not_accepted');
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

        // Countdown has elapsed but the auctioneer hasn't closed the lot yet:
        // bidding is frozen while the result is being finalized.
        if ($this->isCountdownElapsed($lot)) {
            $errors['lot'] = ['Bidding has closed for this lot. Results are being finalized.'];
        }

        if ($lot->vehicle->seller_id === $user->id) {
            $errors['lot'] = ['You cannot bid on your own vehicle.'];
        }

        // A bidder must never bid against themselves. The current high bidder
        // cannot place another standard bid — to raise their ceiling they must
        // increase their maximum (proxy) bid instead.
        if ($lot->current_winner_id === $user->id) {
            $errors['amount'] = ['You are already the highest bidder. Increase your maximum (proxy) bid to raise your limit.'];
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

        // Countdown has elapsed but the auctioneer hasn't closed the lot yet:
        // bidding is frozen while the result is being finalized.
        if ($this->isCountdownElapsed($lot)) {
            $errors['lot'] = ['Bidding has closed for this lot. Results are being finalized.'];
        }

        if ($lot->vehicle->seller_id === $user->id) {
            $errors['lot'] = ['You cannot bid on your own vehicle.'];
        }

        // A maximum at or below the current live bid can never win, so reject it
        // with a clear message before falling back to the increment-based minimum.
        $minBid     = $lot->nextMinimumBid();
        $currentBid = $lot->current_bid ?? 0;
        if ($currentBid > 0 && $maxAmount <= $currentBid) {
            $errors['max_amount'] = ['Your maximum bid must be greater than the current bid.'];
        } elseif ($maxAmount < $minBid) {
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

    /**
     * True when the lot is in countdown status but its timer has already run
     * out. In this window the result is being finalized and no further bids
     * are accepted, even though the auctioneer has not yet transitioned the
     * lot to a terminal status.
     */
    private function isCountdownElapsed(AuctionLot $lot): bool
    {
        return $lot->status === LotStatus::Countdown
            && $lot->countdown_ends_at
            && ! $lot->countdown_ends_at->isFuture();
    }

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
