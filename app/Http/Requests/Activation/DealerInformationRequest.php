<?php

namespace App\Http\Requests\Activation;

use Illuminate\Foundation\Http\FormRequest;

class DealerInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name'              => ['required', 'string', 'max:255'],
            'dba_name'                  => ['nullable', 'string', 'max:255'],
            'owner_name'                => ['required', 'string', 'max:255'],
            'phone'                     => ['required', 'string', 'regex:/^[\+]?[\d\s\-\(\)]{7,30}$/', 'max:30'],
            'office_phone'              => ['nullable', 'string', 'max:30'],
            'primary_contact'           => ['required', 'string', 'max:255'],
            'license_number'            => ['required', 'string', 'max:100'],
            'license_expiration_date'   => ['required', 'date_format:Y-m-d', 'after:today'],
            'tax_id_number'             => ['nullable', 'string', 'max:50'],
            'dealer_address'            => ['required', 'string', 'max:500'],
            'dealer_country'            => ['required', 'string', 'size:2'],
            'dealer_city'               => ['required', 'string', 'max:100'],
            // Nullable in DB for backward compat with existing rows; frontend treats as required.
            'dealer_state'              => ['nullable', 'string', 'max:100'],
            'dealer_zip_code'           => ['required', 'string', 'max:20'],
            'dealer_type'               => ['required', 'in:retail,wholesale'],
            'dealer_classification'     => ['required', 'in:maryland_retail,maryland_wholesale,out_of_state_retail,out_of_state_wholesale'],
            'salesman_name'             => ['nullable', 'string', 'max:255'],
            'salesman_license_number'   => ['nullable', 'string', 'max:100'],
            'salesman_license_state'    => ['nullable', 'string', 'max:100'],
            'salesman_license_expiry'   => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'license_expiration_date.after' => 'Dealer license must not be expired.',
        ];
    }
}
