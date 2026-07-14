<?php

namespace App\Services\Auction;

use App\Enums\LotStatus;
use App\Events\Auction\LotDidNotSell;
use App\Events\Auction\LotStatusChanged;
use App\Events\Auction\UserWonLot;
use App\Jobs\Auction\NotifyAuctionWinner;
use App\Models\AuctionLot;
use App\Models\User;
use App\Notifications\LotAwaitingSellerDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
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
                $fresh = $lot->fresh();
                broadcast(new LotStatusChanged($fresh, $previous));
                event(new LotDidNotSell($fresh));
                return $fresh;
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
                $fresh = $lot->fresh();
                broadcast(new LotStatusChanged($fresh, $previous));
                event(new LotDidNotSell($fresh));
                return $fresh;
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
     *
     * $actor is optional: admin callers pass null (the route's role:admin middleware
     * is the authority). Seller callers pass the authenticated user, which enforces
     * that they own the vehicle behind the lot.
     */
    public function approveIfSale(AuctionLot $lot, ?User $actor = null): AuctionLot
    {
        $this->assertCanDecide($lot, $actor);

        $previous = $lot->status->value;

        return $this->confirmSale($lot, $previous);
    }

    /**
     * Admin/seller rejects an if_sale lot.
     */
    public function rejectIfSale(AuctionLot $lot, ?User $actor = null): AuctionLot
    {
        $this->assertCanDecide($lot, $actor);

        $previous = $lot->status->value;

        $lot->update([
            'status'    => LotStatus::ReserveNotMet,
            'closed_at' => now(),
        ]);

        $lot->vehicle?->markAsAvailable();

        $fresh = $lot->fresh();
        broadcast(new LotStatusChanged($fresh, $previous));
        // Rejected/expired if-sale lot did not sell — trigger the no-sale fee.
        event(new LotDidNotSell($fresh));

        return $fresh;
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
     * If Sale bidding is disabled platform-wide.
     *
     * Once a lot's live auction closes and it enters if_sale status, ALL bidding
     * actions — including a winner increasing their own bid — are rejected. This
     * method is the authoritative server-side guard: it always throws, so the
     * behaviour holds even for direct API calls from a client that still exposes
     * the action (e.g. an un-updated mobile build). The seller now approves or
     * rejects the if_sale lot at the price reached when the lot closed.
     *
     * @throws ValidationException always
     */
    public function increaseIfSaleBid(AuctionLot $lot, User $user, int $amount): AuctionLot
    {
        throw ValidationException::withMessages([
            'lot' => ['Bidding is closed for this lot. If Sale bid increases are no longer permitted.'],
        ]);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    /**
     * Guard shared by approveIfSale() / rejectIfSale().
     *
     * The lot must be awaiting a decision, and — when an $actor is supplied — that
     * actor must either be an admin or the seller who owns the vehicle. Passing no
     * actor skips the ownership check, which is what the admin routes rely on.
     *
     * @throws ValidationException   lot is not in if_sale status
     * @throws AuthorizationException actor does not own the lot
     */
    private function assertCanDecide(AuctionLot $lot, ?User $actor): void
    {
        if ($lot->status !== LotStatus::IfSale) {
            throw ValidationException::withMessages([
                'lot' => ['Lot is not in if_sale status.'],
            ]);
        }

        if ($actor === null || $actor->hasRole('admin')) {
            return;
        }

        if ($lot->vehicle?->seller_id !== $actor->id) {
            throw new AuthorizationException('You do not own the vehicle for this lot.');
        }
    }

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

            // In-app companion to the email above, so the decision also surfaces in
            // the seller's notification bell. Database-only — the email is already sent.
            $lot->vehicle->seller->notify(new LotAwaitingSellerDecision($lot->fresh()));
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

        // Dispatch UserWonLot: broadcasts to buyer's private channel (ShouldBroadcast)
        // and triggers SendAuctionWonNotification listener (database + broadcast notification).
        event(new UserWonLot($soldLot));

        // Queue winner email notification
        dispatch(new NotifyAuctionWinner($soldLot));

        return $lot->fresh();
    }
}
