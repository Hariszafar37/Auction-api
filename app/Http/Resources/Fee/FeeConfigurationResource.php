<?php

namespace App\Http\Resources\Fee;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeeConfigurationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'fee_type'         => $this->fee_type,
            'label'            => $this->label,
            'calculation_type' => $this->calculation_type,
            'amount'           => $this->amount,
            'min_amount'       => $this->min_amount,
            'max_amount'       => $this->max_amount,
            'location'         => $this->location,
            'applies_to'       => $this->applies_to,
            'is_active'        => $this->is_active,
            'sort_order'       => $this->sort_order,
            'notes'            => $this->notes,
            'tiers'            => FeeTierResource::collection($this->whenLoaded('tiers')),
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
