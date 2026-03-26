<?php

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
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
            'odometer'         => $this->mileage,
            'mileage'          => $this->mileage,
            'body_type'        => $this->body_type,
            'transmission'     => $this->transmission,
            'engine'           => $this->engine,
            'fuel_type'        => $this->fuel_type,
            'condition_light'  => $this->condition_light,
            'condition_notes'  => $this->condition_notes,
            'has_title'        => $this->has_title,
            'status'           => $this->status,

            // Vehicle images — populated when media relationship is eager-loaded.
            // Returns [] when vehicle has no uploaded images.
            // Shape matches frontend VehicleImage type: { url, thumbnail }
            'images' => $this->whenLoaded('media', function () {
                return $this->getMedia('images')->map(fn ($m) => [
                    'url'       => $m->getUrl(),
                    'thumbnail' => $m->hasGeneratedConversion('thumb')
                        ? $m->getUrl('thumb')
                        : $m->getUrl(),
                ])->values()->all();
            }, []),
        ];
    }
}
