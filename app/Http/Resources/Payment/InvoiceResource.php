<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->hasRole('admin');

        return [
            'id'             => $this->id,
            'invoice_number' => $this->invoice_number,
            'status'         => $this->status->value,
            'status_label'   => $this->status->label(),

            // Lot / auction context
            'lot_id'     => $this->lot_id,
            'auction_id' => $this->auction_id,
            'lot'        => $this->when($this->relationLoaded('lot'), fn () => [
                'id'         => $this->lot->id,
                'lot_number' => $this->lot->lot_number,
            ]),
            'auction' => $this->when($this->relationLoaded('auction'), fn () => [
                'id'       => $this->auction->id,
                'title'    => $this->auction->title,
                'location' => $this->auction->location,
            ]),
            'vehicle' => $this->when($this->relationLoaded('vehicle'), fn () => [
                'id'    => $this->vehicle->id,
                'year'  => $this->vehicle->year,
                'make'  => $this->vehicle->make,
                'model' => $this->vehicle->model,
                'trim'  => $this->vehicle->trim,
                'vin'   => $this->vehicle->vin,
            ]),

            // Fee line items
            'sale_price'          => $this->sale_price,
            'deposit_amount'      => (float) $this->deposit_amount,
            'buyer_fee_amount'    => (float) $this->buyer_fee_amount,
            'tax_amount'          => (float) $this->tax_amount,
            'tags_amount'         => (float) $this->tags_amount,
            'storage_days'        => $this->storage_days,
            'storage_fee_amount'  => (float) $this->storage_fee_amount,

            // Totals
            'total_amount'       => (float) $this->total_amount,
            'amount_paid'        => (float) $this->amount_paid,
            'balance_due'        => (float) $this->balance_due,
            'remaining_balance'  => (float) $this->remaining_balance,

            // Dates
            'due_at'     => $this->due_at?->toIso8601String(),
            'paid_at'    => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // Fee snapshot — only admins see the raw snapshot
            'fee_snapshot' => $this->when($isAdmin, $this->fee_snapshot),

            // Payments
            'payments' => InvoicePaymentResource::collection($this->whenLoaded('payments')),

            // Buyer info — only for admin
            'buyer' => $this->when($isAdmin && $this->relationLoaded('buyer'), fn () => [
                'id'    => $this->buyer->id,
                'name'  => $this->buyer->name,
                'email' => $this->buyer->email,
            ]),
        ];
    }
}
