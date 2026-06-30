<?php

namespace App\Http\Resources\Auction;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'vin'              => $this->vin,
            'asset_number'     => $this->asset_number,
            'inventory_number' => $this->inventory_number,
            'year'             => $this->year,
            'make'             => $this->make,
            'model'            => $this->model,
            'trim'             => $this->trim,
            'exterior_color'   => $this->exterior_color,
            'interior_color'   => $this->interior_color,
            'interior_seating_type' => $this->interior_seating_type,
            'odometer'         => $this->mileage,
            'mileage'          => $this->mileage,
            'odometer_status'  => $this->odometer_status,
            'number_of_keys'   => $this->number_of_keys,
            'number_of_fobs'   => $this->number_of_fobs,
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
                    'url'       => MediaUrl::temporary($m),
                    'thumbnail' => $m->hasGeneratedConversion('thumb')
                        ? MediaUrl::temporary($m, 'thumb')
                        : MediaUrl::temporary($m),
                ])->values()->all();
            }, []),
        ];
    }
}
