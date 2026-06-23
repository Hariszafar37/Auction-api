<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitNonCardPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['wire', 'cash', 'check'])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes'  => ['nullable', 'string', 'max:500'],

            // Confirmation / check number so staff can match the funds. Required
            // for wire and check, optional for cash (paid in person).
            'reference' => [
                Rule::requiredIf(fn () => in_array($this->input('method'), ['wire', 'check'])),
                'nullable',
                'string',
                'max:200',
            ],
        ];
    }
}
