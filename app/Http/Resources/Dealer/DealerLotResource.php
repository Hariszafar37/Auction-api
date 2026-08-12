<?php

namespace App\Http\Resources\Dealer;

use App\Support\FormatsDates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealerLotResource extends JsonResource
{
    use FormatsDates;

    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'lot_number'   => $this->lot_number,
            'status'       => $this->status->value,
            'status_label' => $this->status->label(),
            'starting_bid' => $this->starting_bid,
            'current_bid'  => $this->current_bid,
            'bid_count'    => $this->bid_count,
            'reserve_met'  => $this->current_bid !== null ? $this->hasReserveMet() : null,
            'sold_price'   => $this->sold_price,
            'dealer_only'  => $this->dealer_only,

            // If Sale decision fields. This resource is only ever returned to the
            // seller who owns the vehicle, so the reserve price — hidden from bidders
            // in AuctionLotResource — is safe to expose here: it is their own number.
            'requires_seller_approval' => $this->requires_seller_approval,
            'reserve_price'            => $this->reserve_price,
            'seller_decision_deadline' => $this->safeIso($this->seller_decision_deadline),
            'seller_approved_at'       => $this->safeIso($this->seller_approved_at),

            // Null-safe: guards against orphaned lots where the related vehicle
            // or auction was removed without cascading to the lot record.
            'vehicle' => $this->vehicle ? [
                'id'    => $this->vehicle->id,
                'year'  => $this->vehicle->year,
                'make'  => $this->vehicle->make,
                'model' => $this->vehicle->model,
                'trim'  => $this->vehicle->trim,
                'vin'   => $this->vehicle->vin,
            ] : null,

            'auction' => $this->auction ? [
                'id'        => $this->auction->id,
                'title'     => $this->auction->title,
                'status'    => $this->auction->status->value,
                'location'  => $this->auction->location,
                'starts_at' => $this->auction->starts_at->toIso8601String(),
                // Ships alongside every auction time so the client can render it
                // on the auction's clock rather than the reader's.
                'timezone'  => $this->auction->timezone,
            ] : null,

            'opened_at'  => $this->safeIso($this->opened_at),
            'closed_at'  => $this->safeIso($this->closed_at),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
