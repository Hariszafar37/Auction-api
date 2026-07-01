<?php

namespace App\Http\Resources\Payment;

use App\Enums\PaymentTransactionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoicePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->hasRole('admin');

        $isAdjustment = $this->transaction_type === PaymentTransactionType::Adjustment;

        return [
            'id'           => $this->id,
            'invoice_id'   => $this->invoice_id,
            'method'       => $this->method->value,
            'method_label' => $this->method->label(),
            'transaction_type'       => $this->transaction_type->value,
            'transaction_type_label' => $this->transaction_type->label(),

            // Customer-facing fee type for adjustments (e.g. "Late Payment Fee").
            // display_label is the title to render for adjustment rows — it falls
            // back to the reason/notes for legacy rows, then a generic label.
            // Null for non-adjustment rows (the client keeps its existing title).
            'fee_type'      => $this->when($isAdjustment, $this->fee_type),
            'display_label' => $isAdjustment ? $this->adjustmentTitle() : null,

            'amount'       => (float) $this->amount,
            'reference'    => $this->when($isAdmin, $this->reference),
            'status'       => $this->status,
            'notes'         => $this->when($isAdmin, $this->notes),
            'processed_at'  => $this->processed_at?->toIso8601String(),
            'received_at'   => $this->received_at?->toIso8601String(),
            'created_at'    => $this->created_at?->toIso8601String(),

            // Who authored the entry (buyer self-report vs. staff) — admin only
            'created_by'    => $this->when($isAdmin && $this->created_by, fn () => [
                'id'   => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
            ]),

            // FIX 3: audit trail for offline payment approvals
            'approved_by'   => $this->when($isAdmin && $this->approved_by, fn () => [
                'id'   => $this->approvedBy?->id,
                'name' => $this->approvedBy?->name,
            ]),
            'approved_at'   => $this->approved_at?->toIso8601String(),
            'approval_note' => $this->when($isAdmin, $this->approval_note),

            // Stripe client_secret — sent only to the payment owner, only once
            'stripe_client_secret' => $this->when(
                $request->user()?->id === $this->user_id && $this->status === 'pending' && $this->stripe_client_secret,
                $this->stripe_client_secret
            ),
        ];
    }
}
