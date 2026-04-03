<?php

namespace App\Http\Requests\Activation;

use Illuminate\Foundation\Http\FormRequest;

class BillingInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_address'          => ['required', 'string', 'max:500'],
            'billing_country'          => ['required', 'string', 'size:2'],
            'billing_city'             => ['required', 'string', 'max:100'],
            'billing_state'            => ['nullable', 'string', 'max:100'],
            'billing_zip_postal_code'  => ['required', 'string', 'max:20'],
        ];
    }
}
