<?php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-facing Vehicle resource.
 *
 * Used by:
 *   GET /api/v1/vehicles          (index)
 *   GET /api/v1/vehicles/{id}     (show)
 *
 * Intentionally omits seller identity (privacy).
 * Includes media gallery and active lot summary when loaded.
 */
class PublicVehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'vin'              => $this->vin,
            'year'             => $this->year,
            'make'             => $this->make,
            'model'            => $this->model,
            'trim'             => $this->trim,
            'color'            => $this->color,
            'mileage'          => $this->mileage,
            'body_type'        => $this->body_type,
            'transmission'     => $this->transmission,
            'engine'           => $this->engine,
            'fuel_type'        => $this->fuel_type,
            'condition_light'  => $this->condition_light,
            'condition_notes'  => $this->condition_notes,
            'has_title'        => $this->has_title,
            'title_state'      => $this->title_state,
            'status'           => $this->status,

            // ── Active auction lot ─────────────────────────────────────────────
            // Present when the vehicle is currently in_auction and the activeLot
            // relationship was eager-loaded.
            'active_lot' => $this->whenLoaded('activeLot', function () {
                $lot = $this->activeLot;

                if (! $lot) {
                    return null;
                }

                return [
                    'id'                => $lot->id,
                    'lot_number'        => $lot->lot_number,
                    'auction_id'        => $lot->auction_id,
                    'auction_title'     => optional($lot->auction)->title,
                    'auction_starts_at' => optional($lot->auction)?->starts_at?->toIso8601String(),
                    'current_bid'       => $lot->current_bid,
                    'next_minimum_bid'  => $lot->nextMinimumBid(),
                    'status'            => $lot->status->value,
                ];
            }, null),

            // ── Media ─────────────────────────────────────────────────────────
            // Images + videos attached via spatie/laravel-medialibrary.
            // Returns an empty array when no media has been uploaded.
            'media' => $this->getMedia('images')->merge($this->getMedia('videos'))
                ->sortBy('order_column')
                ->values()
                ->map(fn ($m) => [
                    'id'         => $m->id,
                    'url'        => $m->getUrl(),
                    'thumb_url'  => $m->hasGeneratedConversion('thumb') ? $m->getUrl('thumb') : null,
                    'mime_type'  => $m->mime_type,
                    'collection' => $m->collection_name,
                    'order'      => $m->order_column,
                ])->all(),

            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
