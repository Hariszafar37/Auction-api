<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminVehicleResource extends JsonResource
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
            'created_at'       => $this->created_at->toIso8601String(),
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
                    'url'        => $m->getUrl(),
                    'thumb_url'  => $m->hasGeneratedConversion('thumb') ? $m->getUrl('thumb') : null,
                    'file_name'  => $m->file_name,
                    'mime_type'  => $m->mime_type,
                    'collection' => $m->collection_name,
                    'size'       => $m->size,
                    'order'      => $m->order_column,
                ])->all(),
        ];
    }
}
