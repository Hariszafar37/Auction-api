<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SellerSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->hasRole('admin');

        return [
            'id'                => $this->id,
            'settlement_number' => $this->settlement_number,
            'status'            => $this->status->value,
            'status_label'      => $this->status->label(),
            'outcome'           => $this->outcome,

            // Context
            'lot_id'     => $this->lot_id,
            'auction_id' => $this->auction_id,
            'lot'        => $this->when($this->relationLoaded('lot') && $this->lot, fn () => [
                'id'         => $this->lot->id,
                'lot_number' => $this->lot->lot_number,
            ]),
            'auction' => $this->when($this->relationLoaded('auction') && $this->auction, fn () => [
                'id'       => $this->auction->id,
                'title'    => $this->auction->title,
                'location' => $this->auction->location,
                'ends_at'  => $this->auction->ends_at?->toIso8601String(),
            ]),
            'vehicle' => $this->when($this->relationLoaded('vehicle') && $this->vehicle, fn () => [
                'id'    => $this->vehicle->id,
                'year'  => $this->vehicle->year,
                'make'  => $this->vehicle->make,
                'model' => $this->vehicle->model,
                'trim'  => $this->vehicle->trim,
                'vin'   => $this->vehicle->vin,
            ]),

            // Fee line items
            'sale_price'        => $this->sale_price,
            'registration_fee'  => (float) $this->registration_fee,
            'commission_amount' => (float) $this->commission_amount,
            'no_sale_fee'       => (float) $this->no_sale_fee,
            'adjustments_total' => (float) $this->adjustments_total,
            'net_proceeds'      => (float) $this->net_proceeds,

            // Release & check workflow
            'release_date'    => $this->release_date?->toDateString(),
            'check_number'    => $this->check_number,
            'released_at'     => $this->released_at?->toIso8601String(),
            'check_issued_at' => $this->check_issued_at?->toIso8601String(),
            'paid_at'         => $this->paid_at?->toIso8601String(),
            'collected_at'    => $this->collected_at?->toIso8601String(),
            'collection_method'    => $this->collection_method,
            'collection_reference' => $this->collection_reference,

            'created_at' => $this->created_at?->toIso8601String(),

            'adjustments' => SellerSettlementAdjustmentResource::collection($this->whenLoaded('adjustments')),

            'fee_snapshot' => $this->when($isAdmin, $this->fee_snapshot),
            'notes'        => $this->when($isAdmin, $this->notes),

            // Seller info — only for admin
            'seller' => $this->when($isAdmin && $this->relationLoaded('seller') && $this->seller, fn () => [
                'id'    => $this->seller->id,
                'name'  => $this->seller->name,
                'email' => $this->seller->email,
            ]),
        ];
    }
}
