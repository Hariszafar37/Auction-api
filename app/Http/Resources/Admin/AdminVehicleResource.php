<?php

namespace App\Http\Resources\Admin;

use App\Support\FormatsDates;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminVehicleResource extends JsonResource
{
    use FormatsDates;

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
            'mileage'          => $this->mileage,
            'odometer_status'  => $this->odometer_status,
            'number_of_keys'   => $this->number_of_keys,
            'number_of_fobs'   => $this->number_of_fobs,
            'body_type'        => $this->body_type,
            'transmission'     => $this->transmission,
            'engine'           => $this->engine,
            'fuel_type'        => $this->fuel_type,
            'drivetrain'       => $this->drivetrain,
            'condition_light'      => $this->condition_light,
            'condition_notes'      => $this->condition_notes,
            'condition_report_url' => $this->condition_report_url,
            'additional_info'      => $this->additional_info,
            'has_title'            => $this->has_title,
            'title_state'      => $this->title_state,
            'title_received'   => (bool) $this->title_received,
            'status'           => $this->status,
            'created_at'       => $this->safeIso($this->created_at),
            'seller'           => $this->whenLoaded('seller', fn () => [
                'id'    => $this->seller->id,
                'name'  => $this->seller->name,
                'email' => $this->seller->email,
            ]),
            // Media gallery — normalised shape used by both admin UI and media manager.
            // Always included; returns [] when no media has been uploaded.
            'media' => $this->getMedia('images')
                ->merge($this->getMedia('videos'))
                ->sortBy('order_column')
                ->values()
                ->map(fn ($m) => [
                    'id'         => $m->id,
                    'type'       => $m->collection_name === 'videos' ? 'video' : 'image',
                    'url'        => MediaUrl::temporary($m),
                    'thumb_url'  => $m->hasGeneratedConversion('thumb') ? MediaUrl::temporary($m, 'thumb') : null,
                    'file_name'  => $m->file_name,
                    'mime_type'  => $m->mime_type,
                    'collection' => $m->collection_name,
                    'size'       => $m->size,
                    'order'      => $m->order_column,
                ])->all(),
        ];
    }
}
