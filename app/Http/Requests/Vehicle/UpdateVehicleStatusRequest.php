<?php

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:available,in_auction,sold,withdrawn'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Status must be one of: available, in_auction, sold, withdrawn.',
        ];
    }
}
