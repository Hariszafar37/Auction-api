<?php

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuctionLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->hasRole('admin');

        return [
            'id'                => $this->id,
            'auction_id'        => $this->auction_id,
            'lot_number'        => $this->lot_number,
            'status'            => $this->status->value,
            'status_label'      => $this->status->label(),
            'starting_bid'      => $this->starting_bid,
            'current_bid'       => $this->current_bid,
            'next_minimum_bid'  => $this->nextMinimumBid(),
            'bid_count'         => $this->bid_count,

            // Current user is the winning bidder
            'is_winner' => $request->user() !== null && $this->current_winner_id !== null
                ? $request->user()->id === $this->current_winner_id
                : false,

            // Reserve: only show "met/not met" to public — never the value.
            // Returns null (not omitted) when no bids placed yet, so frontend can distinguish
            // "unknown" (null) from "not met" (false).
            'reserve_met'       => $this->current_bid !== null ? $this->hasReserveMet() : null,
            // Admins can see the actual reserve price
            'reserve_price'     => $this->when($isAdmin, $this->reserve_price),

            // Countdown
            'countdown_ends_at'    => $this->countdown_ends_at?->toIso8601String(),
            'countdown_seconds'    => $this->countdown_seconds,
            'countdown_extensions' => $this->countdown_extensions,

            'dealer_only'              => $this->dealer_only,

            // If Sale
            'requires_seller_approval' => $this->requires_seller_approval,
            'seller_decision_deadline' => $this->when(
                $isAdmin || $this->status->value === 'if_sale',
                $this->seller_decision_deadline?->toIso8601String()
            ),

            // Sale outcome
            'sold_price'  => $this->when($this->status->value === 'sold', $this->sold_price),
            'opened_at'   => $this->opened_at?->toIso8601String(),
            'closed_at'   => $this->closed_at?->toIso8601String(),

            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'bids'    => BidResource::collection($this->whenLoaded('bids')),
        ];
    }
}
