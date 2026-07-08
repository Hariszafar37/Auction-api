<?php

namespace App\Services\Auction;

use App\Enums\BidType;
use App\Models\AuctionLot;
use App\Models\Bid;
use App\Models\BidIncrement;
use App\Models\ProxyBid;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Handles all proxy (max-bid) logic.
 *
 * Resolution rules:
 *  - New proxy > existing winner's proxy  → new user wins at (old_max + increment)
 *  - New proxy < existing winner's proxy  → existing user stays, bid raised to (new_max + increment)
 *  - New proxy = existing winner's proxy  → first-placed proxy wins (no change)
 */
class ProxyBidService
{
    /**
     * Set or update a proxy bid for a user on a lot.
     * Returns the resulting Bid record (auto or proxy type).
     */
    public function setProxyBid(AuctionLot $lot, User $user, int $maxAmount): Bid
    {
        return DB::transaction(function () use ($lot, $user, $maxAmount) {
            // Lock the lot row to prevent race conditions
            $lot = AuctionLot::lockForUpdate()->find($lot->id);

            // Upsert proxy record
            $proxy = ProxyBid::updateOrCreate(
                ['auction_lot_id' => $lot->id, 'user_id' => $user->id],
                ['max_amount' => $maxAmount, 'is_active' => true, 'cancelled_at' => null]
            );

            return $this->resolveProxies($lot, $proxy);
        });
    }

    /**
     * Called after any manual bid lands — re-run proxy resolution so the
     * highest proxy auto-bids above the manual amount if needed.
     */
    public function resolveAfterManualBid(AuctionLot $lot, User $manualBidder): void
    {
        DB::transaction(function () use ($lot, $manualBidder) {
            $lot = AuctionLot::lockForUpdate()->find($lot->id);

            // Find the active proxy with highest max (excluding the manual bidder themselves)
            $topProxy = ProxyBid::query()
                ->where('auction_lot_id', $lot->id)
                ->where('user_id', '!=', $manualBidder->id)
                ->where('is_active', true)
                ->orderByDesc('max_amount')
                ->first();

            if (! $topProxy) {
                return; // no proxy to trigger
            }

            $currentBid   = $lot->current_bid ?? $lot->starting_bid;
            $increment    = BidIncrement::forAmount($currentBid);
            $neededAmount = $currentBid + $increment;

            if ($topProxy->max_amount >= $neededAmount) {
                // Auto-bid on behalf of this proxy holder, one full increment up.
                $bidAmount = min($topProxy->max_amount, $neededAmount);
                $this->placeBidOnBehalf($lot, $topProxy->user, $bidAmount, BidType::Auto);
            } elseif (
                $topProxy->max_amount >= $currentBid
                && $lot->current_winner_id !== $topProxy->user_id
            ) {
                // The manual bid tied (or came within less than a full increment
                // of) the proxy holder's ceiling. That proxy was registered
                // BEFORE this manual bid, so under first-max-wins it retains
                // priority and reclaims the lead at its maximum — an exact
                // tie-reclaim when equal, a partial increment when the remaining
                // headroom is under one step. Without this, a later manual bid
                // equal to an earlier proxy max would incorrectly lead.
                $this->placeBidOnBehalf($lot, $topProxy->user, $topProxy->max_amount, BidType::Auto);
            }
        });
    }

    // ─── Private helpers ────────────────────────────────────────────────────────

    private function resolveProxies(AuctionLot $lot, ProxyBid $newProxy): Bid
    {
        $newUser = $newProxy->user ?? User::find($newProxy->user_id);

        // Collect all active proxies except the one just set, ordered by max desc
        $competingProxies = ProxyBid::query()
            ->where('auction_lot_id', $lot->id)
            ->where('user_id', '!=', $newUser->id)
            ->where('is_active', true)
            ->orderByDesc('max_amount')
            ->get();

        $topCompeting = $competingProxies->first();

        // Case 1 — No competition.
        if (! $topCompeting) {
            // If this user is ALREADY the high bidder, there is nobody to outbid.
            // Record/raise their max for future defense but do NOT place a bid that
            // would raise the price against themselves (self-outbid bug).
            if ($lot->current_winner_id === $newUser->id && ($winning = $this->currentWinningBid($lot))) {
                return $winning;
            }

            // Take the lead at current + increment (capped at the user's own max).
            $amount = max($lot->starting_bid, ($lot->current_bid ?? 0) + BidIncrement::forAmount($lot->current_bid ?? $lot->starting_bid));
            // Don't exceed new user's own max
            $amount = min($amount, $newProxy->max_amount);

            return $this->placeBidOnBehalf($lot, $newUser, $amount, BidType::Proxy);
        }

        // Case 2 — New proxy WINS (new max > top competing max)
        if ($newProxy->max_amount > $topCompeting->max_amount) {
            // New user wins at topCompeting->max + 1 increment
            $increment = BidIncrement::forAmount($topCompeting->max_amount);
            $amount    = min($newProxy->max_amount, $topCompeting->max_amount + $increment);
            $rival     = $topCompeting->user ?? User::find($topCompeting->user_id);

            // Self-raise guard: the new user is ALREADY the high bidder and the
            // resolved price would not move (they merely raised their own max
            // above an already-beaten rival). Record the new max for future
            // defense but do NOT place a duplicate self-bid or re-notify the
            // rival — that is the "bidding against yourself" bug.
            if (
                $lot->current_winner_id === $newUser->id
                && ($lot->current_bid ?? 0) >= $amount
                && ($winning = $this->currentWinningBid($lot))
            ) {
                return $winning;
            }

            // Visible progression: record the rival being driven up to their own
            // max (now the contested second price) so the bid history shows the
            // duel happened, then the new user wins one increment above. Skipped
            // when the price already sits at/above the rival's ceiling.
            if (($lot->current_bid ?? 0) < $topCompeting->max_amount) {
                $this->recordContestedBid($lot, $rival, $topCompeting->max_amount, BidType::Auto);
            }

            // Notify the outbid user (done in BiddingService after this returns)
            return $this->placeBidOnBehalf($lot, $newUser, $amount, BidType::Proxy, outbidUser: $rival);
        }

        // Case 3 — New proxy LOSES (new max <= top competing max)
        // Existing winner raises to new_max + 1 increment
        $increment = BidIncrement::forAmount($newProxy->max_amount);
        $amount    = min($topCompeting->max_amount, $newProxy->max_amount + $increment);

        $winnerUser = $topCompeting->user ?? User::find($topCompeting->user_id);

        // The competing proxy holder is already the high bidder. Only re-bid if the
        // incoming max actually forces the price up — otherwise we would place a
        // redundant bid (and risk creeping the price toward the max needlessly).
        if (
            $lot->current_winner_id === $winnerUser->id
            && ($lot->current_bid ?? 0) >= $amount
            && ($winning = $this->currentWinningBid($lot))
        ) {
            return $winning;
        }

        // Visible progression: record the new (losing) bidder at their own max —
        // they pushed the price up to their ceiling — then the existing winner
        // defends one increment above. Skipped when the price already sits at/
        // above the new bidder's ceiling (no real competition occurred).
        if (($lot->current_bid ?? 0) < $newProxy->max_amount) {
            $this->recordContestedBid($lot, $newUser, $newProxy->max_amount, BidType::Proxy);
        }

        return $this->placeBidOnBehalf($lot, $winnerUser, $amount, BidType::Auto, outbidUser: $newUser);
    }

    /**
     * Record a non-winning historical bid at a contested ceiling so the bid
     * history reflects a proxy duel without exposing the eventual winner's max.
     * Does not change the lot's current winner — it only adds a visible rung to
     * the ladder and bumps the bid count. The defending/winning bid that follows
     * supersedes it.
     */
    private function recordContestedBid(AuctionLot $lot, User $user, int $amount, BidType $type): void
    {
        Bid::create([
            'auction_lot_id' => $lot->id,
            'user_id'        => $user->id,
            'amount'         => $amount,
            'type'           => $type,
            'is_winning'     => false,
            'placed_at'      => now(),
        ]);

        $lot->update(['bid_count' => $lot->bid_count + 1]);
    }

    /**
     * The current winning bid for a lot, if one exists.
     */
    private function currentWinningBid(AuctionLot $lot): ?Bid
    {
        return Bid::query()
            ->where('auction_lot_id', $lot->id)
            ->where('is_winning', true)
            ->latest('placed_at')
            ->first();
    }

    /**
     * Record a bid placed by the proxy engine and update the lot state.
     */
    private function placeBidOnBehalf(
        AuctionLot $lot,
        User $winner,
        int $amount,
        BidType $type,
        ?User $outbidUser = null
    ): Bid {
        // Deactivate current winning bid
        Bid::query()
            ->where('auction_lot_id', $lot->id)
            ->where('is_winning', true)
            ->update(['is_winning' => false]);

        $bid = Bid::create([
            'auction_lot_id' => $lot->id,
            'user_id'        => $winner->id,
            'amount'         => $amount,
            'type'           => $type,
            'is_winning'     => true,
            'placed_at'      => now(),
        ]);

        $lot->update([
            'current_bid'       => $amount,
            'current_winner_id' => $winner->id,
            'bid_count'         => $lot->bid_count + 1,
        ]);

        if ($outbidUser) {
            $bid->setAttribute('_outbid_user', $outbidUser);
        }

        return $bid;
    }
}
