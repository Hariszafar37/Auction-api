<?php

namespace App\Services\Auction;

use App\Enums\BidType;
use App\Enums\LotStatus;
use App\Events\Auction\BidPlaced;
use App\Events\Auction\LotStatusChanged;
use App\Events\Auction\UserWonLot;
use App\Jobs\Auction\NotifyAuctionWinner;
use App\Mail\IfSaleNotificationMail;
use App\Models\AuctionLot;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuctionLotService
{
    // ─── Lot state transitions ───────────────────────────────────────────────────

    /**
     * Auctioneer opens a pending lot for bidding.
     */
    public function openLot(AuctionLot $lot): AuctionLot
    {
        if ($lot->status !== LotStatus::Pending) {
            throw ValidationException::withMessages([
                'lot' => ['Only pending lots can be opened.'],
            ]);
        }

        $previous = $lot->status->value;

        $lot->update([
            'status'    => LotStatus::Open,
            'opened_at' => now(),
        ]);

        broadcast(new LotStatusChanged($lot->fresh(), $previous));

        return $lot->fresh();
    }

    /**
     * Close a lot whose countdown has expired.
     * Determines outcome: sold, if_sale, reserve_not_met, or no_sale.
     */
    public function closeLot(AuctionLot $lot): AuctionLot
    {
        return DB::transaction(function () use ($lot) {
            $lot = AuctionLot::lockForUpdate()->find($lot->id);

            if ($lot->isTerminal()) {
                return $lot; // already closed, idempotent
            }

            $previous = $lot->status->value;

            // No bids placed
            if (! $lot->current_bid || ! $lot->current_winner_id) {
                $lot->update([
                    'status'    => LotStatus::NoSale,
                    'closed_at' => now(),
                ]);
                broadcast(new LotStatusChanged($lot->fresh(), $previous));
                return $lot->fresh();
            }

            // Reserve not met
            if (! $lot->hasReserveMet()) {
                if ($lot->requires_seller_approval) {
                    return $this->triggerIfSale($lot, $previous);
                }

                $lot->update([
                    'status'    => LotStatus::ReserveNotMet,
                    'closed_at' => now(),
                ]);
                broadcast(new LotStatusChanged($lot->fresh(), $previous));
                return $lot->fresh();
            }

            // Reserve met — check if seller approval required
            if ($lot->requires_seller_approval) {
                return $this->triggerIfSale($lot, $previous);
            }

            // Straight sale
            return $this->confirmSale($lot, $previous);
        });
    }

    /**
     * Admin/seller approves an if_sale lot.
     */
    public function approveIfSale(AuctionLot $lot): AuctionLot
    {
        if ($lot->status !== LotStatus::IfSale) {
            throw ValidationException::withMessages([
                'lot' => ['Lot is not in if_sale status.'],
            ]);
        }

        $previous = $lot->status->value;

        return $this->confirmSale($lot, $previous);
    }

    /**
     * Admin/seller rejects an if_sale lot.
     */
    public function rejectIfSale(AuctionLot $lot): AuctionLot
    {
        if ($lot->status !== LotStatus::IfSale) {
            throw ValidationException::withMessages([
                'lot' => ['Lot is not in if_sale status.'],
            ]);
        }

        $previous = $lot->status->value;

        $lot->update([
            'status'    => LotStatus::ReserveNotMet,
            'closed_at' => now(),
        ]);

        $lot->vehicle?->markAsAvailable();

        broadcast(new LotStatusChanged($lot->fresh(), $previous));

        return $lot->fresh();
    }

    /**
     * Auto-expire if_sale lots whose decision deadline has passed (no seller response).
     */
    public function expireIfSale(AuctionLot $lot): AuctionLot
    {
        if ($lot->status !== LotStatus::IfSale) {
            return $lot;
        }

        return $this->rejectIfSale($lot);
    }

    /**
     * Current winner increases their bid during if_sale.
     * Resets the seller decision deadline and re-notifies the seller.
     */
    public function increaseIfSaleBid(AuctionLot $lot, User $user, int $amount): AuctionLot
    {
        return DB::transaction(function () use ($lot, $user, $amount) {
            $lot = AuctionLot::lockForUpdate()->find($lot->id);

            if ($lot->status !== LotStatus::IfSale) {
                throw ValidationException::withMessages([
                    'lot' => ['Lot is not in if_sale status.'],
                ]);
            }

            if ($lot->current_winner_id !== $user->id) {
                throw ValidationException::withMessages([
                    'lot' => ['Only the current winner can increase the bid.'],
                ]);
            }

            $minBid = $lot->nextMinimumBid();
            if ($amount < $minBid) {
                throw ValidationException::withMessages([
                    'amount' => ["Minimum bid is \${$minBid}."],
                ]);
            }

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
                'current_bid'              => $amount,
                'bid_count'                => $lot->bid_count + 1,
                'seller_notified_at'       => now(),
                'seller_decision_deadline' => now()->addHours(48),
            ]);

            $freshLot = $lot->fresh(['vehicle', 'auction']);

            // Broadcast updated bid to lot channel watchers
            broadcast(new BidPlaced($freshLot, $bid));

            // Re-notify seller of the increased bid
            if ($freshLot->vehicle?->seller) {
                Mail::to($freshLot->vehicle->seller->email)
                    ->send(new IfSaleNotificationMail($freshLot));
            }

            return $lot->fresh();
        });
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    private function triggerIfSale(AuctionLot $lot, string $previous): AuctionLot
    {
        $lot->update([
            'status'                   => LotStatus::IfSale,
            'closed_at'                => now(),
            'seller_notified_at'       => now(),
            // 48 business hours — simplified to 48 calendar hours for now
            'seller_decision_deadline' => now()->addHours(48),
        ]);

        broadcast(new LotStatusChanged($lot->fresh(), $previous));

        // Notify seller they have 48 hours to approve or reject
        $lot->load(['vehicle.seller', 'auction']);
        if ($lot->vehicle?->seller) {
            \Illuminate\Support\Facades\Mail::to($lot->vehicle->seller->email)
                ->send(new \App\Mail\IfSaleNotificationMail($lot));
        }

        return $lot->fresh();
    }

    private function confirmSale(AuctionLot $lot, string $previous): AuctionLot
    {
        $lot->update([
            'status'           => LotStatus::Sold,
            'sold_price'       => $lot->current_bid,
            'buyer_id'         => $lot->current_winner_id,
            'seller_approved_at' => now(),
            'closed_at'        => $lot->closed_at ?? now(),
        ]);

        $lot->vehicle?->markAsSold();

        $soldLot = $lot->fresh(['vehicle', 'auction']);

        broadcast(new LotStatusChanged($soldLot, $previous));

        // Real-time in-app win notification to the buyer's private channel
        broadcast(new UserWonLot($soldLot));

        // Queue winner email notification
        dispatch(new NotifyAuctionWinner($soldLot));

        return $lot->fresh();
    }
}
