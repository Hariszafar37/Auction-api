<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class LedgerAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            // Signed: positive increases the balance (a charge), negative reduces
            // it (a credit/discount). Cannot be zero.
            'amount' => ['required', 'numeric', 'not_in:0'],
            // Customer-facing title for the adjustment (e.g. "Late Payment Fee").
            // Display-only — the internal transaction_type stays 'adjustment'.
            'fee_type' => ['required', 'string', 'max:100'],
            // Secondary detail only; kept as the adjustment's notes/reason.
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
