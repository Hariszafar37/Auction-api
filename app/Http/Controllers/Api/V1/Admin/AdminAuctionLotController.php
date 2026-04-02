<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auction\CreateAuctionLotRequest;
use App\Http\Requests\Auction\UpdateAuctionLotRequest;
use App\Http\Resources\Auction\AuctionLotResource;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Vehicle;
use App\Services\Auction\AuctionLotService;
use App\Services\Auction\AuctionService;
use App\Services\Auction\BiddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AdminAuctionLotController extends Controller
{
    public function __construct(
        private readonly AuctionService    $auctionService,
        private readonly AuctionLotService $lotService,
        private readonly BiddingService    $biddingService,
    ) {}

    /**
     * POST /api/v1/admin/auctions/{auction}/lots
     * Add a vehicle as a lot in an auction.
     */
    public function store(CreateAuctionLotRequest $request, Auction $auction): JsonResponse
    {
        $vehicle = Vehicle::find($request->integer('vehicle_id'));

        try {
            $lot = $this->auctionService->addLot($auction, $vehicle, $request->validated(), $request->user());
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, 'lot_add_failed', $e->errors());
        }

        return $this->success(new AuctionLotResource($lot), 'Lot added to auction.', 201);
    }

    /**
     * PATCH /api/v1/admin/auctions/{auction}/lots/{lot}
     */
    public function update(UpdateAuctionLotRequest $request, Auction $auction, AuctionLot $lot): JsonResponse
    {
        if ($lot->auction_id !== $auction->id) {
            return $this->error('Lot does not belong to this auction.', 404, 'not_found');
        }

        try {
            $lot = $this->auctionService->updateLot($lot, $request->validated());
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, 'lot_update_failed', $e->errors());
        }

        return $this->success(new AuctionLotResource($lot), 'Lot updated.');
    }

    /**
     * DELETE /api/v1/admin/auctions/{auction}/lots/{lot}
     */
    public function destroy(Auction $auction, AuctionLot $lot): JsonResponse
    {
        if ($lot->auction_id !== $auction->id) {
            return $this->error('Lot does not belong to this auction.', 404, 'not_found');
        }

        try {
            $this->auctionService->removeLot($lot);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, 'lot_remove_failed', $e->errors());
        }

        return $this->success(null, 'Lot removed from auction.');
    }

    /**
     * POST /api/v1/admin/lots/{lot}/open
     * Auctioneer opens a pending lot for bidding.
     */
    public function open(AuctionLot $lot): JsonResponse
    {
        try {
            $lot = $this->lotService->openLot($lot);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, 'lot_open_failed', $e->errors());
        }

        return $this->success(new AuctionLotResource($lot->load('vehicle')), 'Lot is now open for bidding.');
    }

    /**
     * POST /api/v1/admin/lots/{lot}/countdown
     * Auctioneer manually triggers the final countdown for a lot.
     */
    public function startCountdown(AuctionLot $lot): JsonResponse
    {
        try {
            $this->biddingService->startCountdown($lot);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, 'countdown_failed', $e->errors());
        }

        return $this->success(
            new AuctionLotResource($lot->fresh('vehicle')),
            'Countdown started.'
        );
    }

    /**
     * POST /api/v1/admin/lots/{lot}/close
     * Manually force-close a lot (process outcome).
     */
    public function close(AuctionLot $lot): JsonResponse
    {
        try {
            $lot = $this->lotService->closeLot($lot);
        } catch (\Throwable $e) {
            return $this->error('Failed to close lot.', 500, 'lot_close_failed');
        }

        return $this->success(new AuctionLotResource($lot->load('vehicle')), 'Lot closed.');
    }

    /**
     * POST /api/v1/admin/lots/{lot}/if-sale/approve
     * Seller/admin approves an if_sale lot.
     */
    public function approveIfSale(AuctionLot $lot): JsonResponse
    {
        try {
            $lot = $this->lotService->approveIfSale($lot);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, 'if_sale_failed', $e->errors());
        }

        return $this->success(new AuctionLotResource($lot->load('vehicle')), 'Sale confirmed.');
    }

    /**
     * POST /api/v1/admin/lots/{lot}/if-sale/reject
     * Seller/admin rejects an if_sale lot.
     */
    public function rejectIfSale(AuctionLot $lot): JsonResponse
    {
        try {
            $lot = $this->lotService->rejectIfSale($lot);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, 'if_sale_failed', $e->errors());
        }

        return $this->success(new AuctionLotResource($lot->load('vehicle')), 'Sale rejected.');
    }
}
