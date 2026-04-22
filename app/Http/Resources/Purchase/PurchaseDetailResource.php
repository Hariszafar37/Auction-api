<?php

namespace App\Http\Resources\Purchase;

use App\Http\Resources\Payment\InvoiceResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->hasRole('admin');
        $lot     = $this->lot;
        $vehicle = $lot?->vehicle;
        $auction = $lot?->auction;

        return [
            'id'         => $this->id,
            'lot_id'     => $this->lot_id,
            'buyer_id'   => $this->buyer_id,

            // Lot / auction context
            'lot' => $lot ? [
                'id'         => $lot->id,
                'lot_number' => $lot->lot_number,
                'sold_price' => $lot->sold_price,
            ] : null,

            'auction' => $auction ? [
                'id'        => $auction->id,
                'title'     => $auction->title,
                'location'  => $auction->location,
                'starts_at' => $auction->starts_at?->toIso8601String(),
            ] : null,

            'vehicle' => $vehicle ? [
                'id'               => $vehicle->id,
                'year'             => $vehicle->year,
                'make'             => $vehicle->make,
                'model'            => $vehicle->model,
                'trim'             => $vehicle->trim,
                'vin'              => $vehicle->vin,
                'mileage'          => $vehicle->mileage,
                'condition_light'  => $vehicle->condition_light,
                'condition_notes'  => $vehicle->condition_notes,
                'thumbnail_url'    => $vehicle->getFirstMediaUrl('images'),
                'photo_urls'       => $vehicle->getMedia('images')->map(fn ($m) => $m->getUrl())->values()->all(),
            ] : null,

            // Invoice summary (full resource when loaded)
            'invoice' => $this->when(
                $this->relationLoaded('invoice') && $this->invoice !== null,
                fn () => new InvoiceResource($this->invoice),
            ),

            // Pickup lifecycle
            'pickup_status'       => $this->pickup_status->value,
            'pickup_status_label' => $this->pickup_status->label(),
            'pickup_notes'        => $this->pickup_notes,
            'picked_up_at'        => $this->picked_up_at?->toIso8601String(),

            // Gate pass — token only exposed if the invoice is paid
            'gate_pass_token'        => $this->when(
                $this->relationLoaded('invoice') && $this->invoice?->isPaid() && $this->gate_pass_token,
                $this->gate_pass_token,
            ),
            'gate_pass_generated_at' => $this->gate_pass_generated_at?->toIso8601String(),
            'gate_pass_revoked_at'   => $this->gate_pass_revoked_at?->toIso8601String(),
            'revocation_reason'      => $this->revocation_reason,

            // Document tracker milestones
            'title_received_at' => $this->title_received_at?->toIso8601String(),
            'title_verified_at' => $this->title_verified_at?->toIso8601String(),
            'title_released_at' => $this->title_released_at?->toIso8601String(),

            // Transport requests
            'transport_requests' => TransportRequestResource::collection(
                $this->whenLoaded('transportRequests'),
            ),

            // Buyer info — only for admin
            'buyer' => $this->when($isAdmin && $this->relationLoaded('buyer'), fn () => [
                'id'    => $this->buyer->id,
                'name'  => $this->buyer->name,
                'email' => $this->buyer->email,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
