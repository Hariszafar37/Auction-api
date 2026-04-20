<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoicePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->hasRole('admin');

        return [
            'id'           => $this->id,
            'invoice_id'   => $this->invoice_id,
            'method'       => $this->method->value,
            'method_label' => $this->method->label(),
            'amount'       => (float) $this->amount,
            'reference'    => $this->when($isAdmin, $this->reference),
            'status'       => $this->status,
            'notes'        => $this->when($isAdmin, $this->notes),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),

            // Stripe client_secret — sent only to the payment owner, only once
            'stripe_client_secret' => $this->when(
                $request->user()?->id === $this->user_id && $this->status === 'pending' && $this->stripe_client_secret,
                $this->stripe_client_secret
            ),
        ];
    }
}
