<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

class ApplyToSellRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'application_notes' => ['required', 'string', 'max:2000'],
            'vehicle_types'     => ['nullable', 'string', 'max:500'],
            'packet_accepted'   => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'packet_accepted.accepted' => 'You must accept the seller terms to apply.',
        ];
    }
}
