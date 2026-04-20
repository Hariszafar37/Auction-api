<?php

namespace App\Http\Resources\Fee;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeeTierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'fee_configuration_id' => $this->fee_configuration_id,
            'sale_price_from'      => $this->sale_price_from,
            'sale_price_to'        => $this->sale_price_to,
            'fee_calculation_type' => $this->fee_calculation_type,
            'fee_amount'           => $this->fee_amount,
            'sort_order'           => $this->sort_order,
        ];
    }
}
