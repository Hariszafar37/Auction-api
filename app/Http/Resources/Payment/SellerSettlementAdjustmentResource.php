<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SellerSettlementAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'amount'     => (float) $this->amount,
            'reason'     => $this->reason,
            'created_by' => $this->when($this->relationLoaded('author') && $this->author, fn () => [
                'id'   => $this->author->id,
                'name' => $this->author->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
