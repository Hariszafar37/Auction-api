<?php

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuctionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'location'    => $this->location,
            'timezone'    => $this->timezone,
            'starts_at'   => $this->starts_at?->toIso8601String(),
            'ends_at'     => $this->ends_at?->toIso8601String(),
            'status'      => $this->status->value,
            'status_label'=> $this->status->label(),
            'notes'       => $this->when($request->user()?->hasRole('admin'), $this->notes),
            'lot_count'   => $this->lots_count ?? $this->whenLoaded('lots', fn () => $this->lots->count()),
            'created_at'  => $this->created_at->toIso8601String(),

            'lots' => AuctionLotResource::collection($this->whenLoaded('lots')),
        ];
    }
}
