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

            $increment    = BidIncrement::forAmount($lot->current_bid ?? $lot->starting_bid);
            $neededAmount = ($lot->current_bid ?? $lot->starting_bid) + $increment;

            if ($topProxy->max_amount >= $neededAmount) {
                // Auto-bid on behalf of this proxy holder
                $bidAmount = min($topProxy->max_amount, $neededAmount);
                $this->placeBidOnBehalf($lot, $topProxy->user, $bidAmount, BidType::Auto);
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

        // Case 1 — No competition: place a bid at starting bid (or current + increment)
        if (! $topCompeting) {
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

            // Notify the outbid user (done in BiddingService after this returns)
            return $this->placeBidOnBehalf($lot, $newUser, $amount, BidType::Auto, outbidUser: $topCompeting->user ?? User::find($topCompeting->user_id));
        }

        // Case 3 — New proxy LOSES (new max <= top competing max)
        // Existing winner raises to new_max + 1 increment
        $increment = BidIncrement::forAmount($newProxy->max_amount);
        $amount    = min($topCompeting->max_amount, $newProxy->max_amount + $increment);

        $winnerUser = $topCompeting->user ?? User::find($topCompeting->user_id);
        return $this->placeBidOnBehalf($lot, $winnerUser, $amount, BidType::Auto, outbidUser: $newUser);
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
