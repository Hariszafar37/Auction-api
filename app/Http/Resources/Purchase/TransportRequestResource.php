<?php

namespace App\Http\Resources\Purchase;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'lot_id'           => $this->lot_id,
            'buyer_id'         => $this->buyer_id,
            'pickup_location'  => $this->pickup_location,
            'delivery_address' => $this->delivery_address,
            'preferred_dates'  => $this->preferred_dates,
            'notes'            => $this->notes,
            'status'           => $this->status->value,
            'status_label'     => $this->status->label(),
            'quote_amount'     => $this->quote_amount !== null ? (float) $this->quote_amount : null,
            'admin_notes'      => $this->admin_notes,
            'quoted_at'        => $this->quoted_at?->toIso8601String(),
            'arranged_at'      => $this->arranged_at?->toIso8601String(),
            'cancelled_at'     => $this->cancelled_at?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),

            'buyer' => $this->when($this->relationLoaded('buyer'), fn () => [
                'id'    => $this->buyer->id,
                'name'  => $this->buyer->name,
                'email' => $this->buyer->email,
            ]),
        ];
    }
}
