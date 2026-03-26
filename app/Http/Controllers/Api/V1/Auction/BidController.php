<?php

namespace App\Http\Controllers\Api\V1\Auction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\IncreaseIfSaleBidRequest;
use App\Http\Requests\Auction\PlaceBidRequest;
use App\Http\Requests\Auction\PlaceProxyBidRequest;
use App\Http\Resources\Auction\AuctionLotResource;
use App\Http\Resources\Auction\BidResource;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Services\Auction\AuctionLotService;
use App\Services\Auction\BiddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BidController extends Controller
{
    public function __construct(
        private readonly BiddingService    $biddingService,
        private readonly AuctionLotService $auctionLotService,
    ) {}

    /**
     * POST /api/v1/auctions/{auction}/lots/{lot}/bids
     * Place a manual bid.
     */
    public function placeBid(PlaceBidRequest $request, Auction $auction, AuctionLot $lot): JsonResponse
    {
        if ($lot->auction_id !== $auction->id) {
            return $this->error('Lot does not belong to this auction.', 404, 'not_found');
        }

        $user = $request->user();

        if ($user->status !== 'active') {
            return $this->error('Your account must be active to place bids.', 403, 'account_inactive');
        }

        try {
            $bid = $this->biddingService->placeBid($lot, $user, $request->integer('amount'));
        } catch (ValidationException $e) {
            return $this->error('Bid validation failed.', 422, 'bid_invalid', $e->errors());
        }

        return $this->success(
            [
                'bid' => new BidResource($bid),
                'lot' => new AuctionLotResource($lot->fresh('vehicle')),
            ],
            'Bid placed successfully.'
        );
    }

    /**
     * POST /api/v1/auctions/{auction}/lots/{lot}/proxy-bid
     * Set or update a proxy (max) bid.
     */
    public function setProxyBid(PlaceProxyBidRequest $request, Auction $auction, AuctionLot $lot): JsonResponse
    {
        if ($lot->auction_id !== $auction->id) {
            return $this->error('Lot does not belong to this auction.', 404, 'not_found');
        }

        $user = $request->user();

        if ($user->status !== 'active') {
            return $this->error('Your account must be active to place bids.', 403, 'account_inactive');
        }

        try {
            $result = $this->biddingService->setProxyBid($lot, $user, $request->integer('max_amount'));
        } catch (ValidationException $e) {
            return $this->error('Proxy bid validation failed.', 422, 'bid_invalid', $e->errors());
        }

        return $this->success(
            [
                'lot'        => new AuctionLotResource($result['lot']->load('vehicle')),
                'is_winning' => $result['is_winning'],
            ],
            $result['is_winning']
                ? 'You are the current high bidder.'
                : 'Proxy bid set. You were outbid by another proxy.'
        );
    }

    /**
     * POST /api/v1/auctions/{auction}/lots/{lot}/if-sale/increase-bid
     * Current winner increases their bid during an if_sale period.
     */
    public function increaseIfSaleBid(IncreaseIfSaleBidRequest $request, Auction $auction, AuctionLot $lot): JsonResponse
    {
        if ($lot->auction_id !== $auction->id) {
            return $this->error('Lot does not belong to this auction.', 404, 'not_found');
        }

        $user = $request->user();

        if ($user->status !== 'active') {
            return $this->error('Your account must be active to place bids.', 403, 'account_inactive');
        }

        try {
            $updatedLot = $this->auctionLotService->increaseIfSaleBid($lot, $user, $request->integer('amount'));
        } catch (ValidationException $e) {
            return $this->error('Bid increase failed.', 422, 'bid_invalid', $e->errors());
        }

        return $this->success(
            ['lot' => new AuctionLotResource($updatedLot->load('vehicle'))],
            'Bid increased successfully.'
        );
    }

    /**
     * GET /api/v1/auctions/{auction}/lots/{lot}/bids
     * Bid history for a lot (public — amounts and types only, no bidder identity).
     */
    public function bidHistory(Request $request, Auction $auction, AuctionLot $lot): JsonResponse
    {
        if ($lot->auction_id !== $auction->id) {
            return $this->error('Lot does not belong to this auction.', 404, 'not_found');
        }

        $bids = $lot->bids()
            ->orderByDesc('placed_at')
            ->paginate($request->integer('per_page', 20));

        return $this->success(
            BidResource::collection($bids),
            meta: [
                'current_page' => $bids->currentPage(),
                'last_page'    => $bids->lastPage(),
                'per_page'     => $bids->perPage(),
                'total'        => $bids->total(),
            ]
        );
    }

    /**
     * GET /api/v1/my/bids
     * Authenticated user's bid history across all auctions.
     */
    public function myBids(Request $request): JsonResponse
    {
        $bids = $request->user()
            ->bids()
            ->with('auctionLot.auction', 'auctionLot.vehicle')
            ->orderByDesc('placed_at')
            ->paginate($request->integer('per_page', 20));

        return $this->success(
            BidResource::collection($bids),
            meta: [
                'current_page' => $bids->currentPage(),
                'last_page'    => $bids->lastPage(),
                'per_page'     => $bids->perPage(),
                'total'        => $bids->total(),
            ]
        );
    }
}
