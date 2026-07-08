<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class SettlementAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            // Signed: positive credits the seller, negative deducts. Cannot be zero.
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
