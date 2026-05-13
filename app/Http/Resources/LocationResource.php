<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'code'         => $this->code,
            'address'      => $this->address,
            'city'         => $this->city,
            'state'        => $this->state,
            'zip_code'     => $this->zip_code,
            'phone'        => $this->phone,
            'manager_name' => $this->manager_name,
            'is_active'    => $this->is_active,
            'created_at'   => $this->created_at->toIso8601String(),
        ];
    }
}
