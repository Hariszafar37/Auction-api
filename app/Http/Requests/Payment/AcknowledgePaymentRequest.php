<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcknowledgePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::in(['wire', 'cash', 'check'])],
            // The buyer must affirmatively accept the requirements to proceed.
            'acknowledged'   => ['required', 'accepted'],
        ];
    }
}
