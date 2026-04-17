<?php

namespace App\Http\Resources\Auction;

use App\Support\FormatsDates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuctionResource extends JsonResource
{
    use FormatsDates;

    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'location'    => $this->location,
            'timezone'    => $this->timezone,
            'starts_at'   => $this->safeIso($this->starts_at),
            'ends_at'     => $this->safeIso($this->ends_at),
            'status'      => $this->status->value,
            'status_label'=> $this->status->label(),
            'notes'       => $this->when($request->user()?->hasRole('admin'), $this->notes),
            'lot_count'   => $this->lots_count ?? $this->whenLoaded('lots', fn () => $this->lots->count()),
            'created_at'  => $this->safeIso($this->created_at),

            'lots' => AuctionLotResource::collection($this->whenLoaded('lots')),
        ];
    }
}
