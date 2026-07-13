<?php

namespace App\Http\Controllers\Api\V1\Dealer;

use App\Enums\LotStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auction\BidResource;
use App\Http\Resources\Dealer\DealerLotResource;
use App\Models\AuctionLot;
use App\Services\Auction\AuctionLotService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Seller-facing "If Sale" decision flow.
 *
 * When a lot closes and the seller's approval is required, it lands in if_sale
 * status and the seller has 48 hours to approve or reject the highest bid before
 * ProcessIfSaleExpiry auto-rejects it. Until now that decision was only reachable
 * from the admin routes; these endpoints give the vehicle's owner the same actions
 * for their own lots.
 *
 * Routes are gated on permission:inventory.create so both dealers (role:dealer) and
 * approved individual sellers (role:seller) reach them — the same rule /my/vehicles
 * already uses. Ownership is enforced per-lot below, not by the middleware.
 */
class SellerLotDecisionController extends Controller
{
    public function __construct(
        private readonly AuctionLotService $lotService,
    ) {}

    /**
     * GET /api/v1/my/lots/pending-decision
     * Lots of mine awaiting an approve/reject decision, soonest deadline first.
     */
    public function pending(Request $request): JsonResponse
    {
        $lots = AuctionLot::query()
            ->where('status', LotStatus::IfSale->value)
            ->whereHas('vehicle', fn ($q) => $q->where('seller_id', $request->user()->id))
            ->with(['vehicle', 'auction'])
            ->orderBy('seller_decision_deadline')
            ->get();

        return $this->success(DealerLotResource::collection($lots));
    }

    /**
     * GET /api/v1/my/lots/{lot}/bids
     * Bid history for a lot I own, highest first.
     *
     * BidResource withholds bidder identity from anyone but the bidder and admins,
     * so the seller sees amounts and non-identifying bidder numbers only.
     */
    public function bids(Request $request, AuctionLot $lot): JsonResponse
    {
        if (! $this->owns($request, $lot)) {
            return $this->error('You do not own the vehicle for this lot.', 403, 'forbidden');
        }

        $bids = $lot->bids()
            ->with('user:id,bidder_number')
            ->orderByDesc('amount')
            ->orderByDesc('placed_at')
            ->get();

        return $this->success(BidResource::collection($bids));
    }

    /**
     * POST /api/v1/my/lots/{lot}/if-sale/approve
     * Seller accepts the highest bid — the lot sells at current_bid.
     */
    public function approve(Request $request, AuctionLot $lot): JsonResponse
    {
        return $this->decide(
            fn () => $this->lotService->approveIfSale($lot, $request->user()),
            'Sale confirmed.',
        );
    }

    /**
     * POST /api/v1/my/lots/{lot}/if-sale/reject
     * Seller declines the highest bid — the lot closes as reserve_not_met.
     */
    public function reject(Request $request, AuctionLot $lot): JsonResponse
    {
        return $this->decide(
            fn () => $this->lotService->rejectIfSale($lot, $request->user()),
            'Bid rejected.',
        );
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    /**
     * Runs an if_sale decision, mapping the service's guards onto HTTP responses.
     * Ownership is checked inside the service, so it holds for any caller.
     */
    private function decide(callable $action, string $message): JsonResponse
    {
        try {
            $lot = $action();
        } catch (AuthorizationException $e) {
            return $this->error($e->getMessage(), 403, 'forbidden');
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, 'if_sale_failed', $e->errors());
        }

        return $this->success(
            new DealerLotResource($lot->load(['vehicle', 'auction'])),
            $message,
        );
    }

    private function owns(Request $request, AuctionLot $lot): bool
    {
        return $lot->vehicle?->seller_id === $request->user()->id;
    }
}
