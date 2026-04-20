<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['stripe_card', 'cash', 'check', 'other'])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes'  => ['nullable', 'string', 'max:500'],

            // Only required for cash/check (admin-recorded offline payments)
            'reference' => [
                Rule::requiredIf(fn () => in_array($this->input('method'), ['check'])),
                'nullable',
                'string',
                'max:200',
            ],
        ];
    }
}
