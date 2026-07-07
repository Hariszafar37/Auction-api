<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class MarkSettlementCollectedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'collection_method'    => ['nullable', 'string', 'in:cash,check,card,wire,account_credit,other'],
            'collection_reference' => ['nullable', 'string', 'max:100'],
            'collected_at'         => ['nullable', 'date'],
        ];
    }
}
