<?php

namespace App\Http\Requests\Activation;

use Illuminate\Foundation\Http\FormRequest;

class AccountInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_of_birth'                  => ['required', 'date_format:Y-m-d', 'before:-18 years'],
            'address'                        => ['required', 'string', 'max:500'],
            'country'                        => ['required', 'string', 'size:2'],
            'state'                          => ['required', 'string', 'max:100'],
            'county'                         => ['nullable', 'string', 'max:100'],
            'city'                           => ['required', 'string', 'max:100'],
            'zip_postal_code'                => ['required', 'string', 'max:20'],
            'driver_license_number'          => ['required', 'string', 'max:100'],
            'driver_license_expiration_date' => ['required', 'date_format:Y-m-d', 'after:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_of_birth.before'                     => 'You must be at least 18 years old.',
            'driver_license_expiration_date.after'     => 'Driver license must not be expired.',
        ];
    }
}
