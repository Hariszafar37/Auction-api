<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Response shape for a lot won by the authenticated user.
 * Does NOT expose reserve price, other bidders, or admin fields.
 */
class WonLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $vehicle = $this->vehicle;
        $auction = $this->auction;

        return [
            'id'          => $this->id,
            'lot_number'  => $this->lot_number,
            'sold_price'  => $this->sold_price,
            'bid_count'   => $this->bid_count,
            'closed_at'   => $this->closed_at,
            'auction'     => $auction ? [
                'id'        => $auction->id,
                'title'     => $auction->title,
                'location'  => $auction->location,
                'starts_at' => $auction->starts_at,
            ] : null,
            'vehicle'     => $vehicle ? [
                'id'           => $vehicle->id,
                'vin'          => $vehicle->vin,
                'year'         => $vehicle->year,
                'make'         => $vehicle->make,
                'model'        => $vehicle->model,
                'trim'         => $vehicle->trim,
                'color'        => $vehicle->color,
                'mileage'      => $vehicle->mileage,
                'body_type'    => $vehicle->body_type,
                'condition_light' => $vehicle->condition_light,
            ] : null,
        ];
    }
}
