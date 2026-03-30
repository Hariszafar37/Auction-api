<?php

namespace App\Http\Requests\Activation;

use Illuminate\Foundation\Http\FormRequest;

class BusinessInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Business identity
            'legal_business_name'  => ['required', 'string', 'max:255'],
            'dba_name'             => ['nullable', 'string', 'max:255'],

            // Contact
            'primary_contact_name' => ['required', 'string', 'max:255'],
            'contact_title'        => ['required', 'string', 'max:255'],
            'phone'                => ['required', 'string', 'max:30'],
            'office_phone'         => ['nullable', 'string', 'max:30'],

            // Address
            'address'              => ['required', 'string', 'max:255'],
            'suite'                => ['nullable', 'string', 'max:100'],
            'city'                 => ['required', 'string', 'max:100'],
            'state'                => ['required', 'string', 'size:2'],
            'zip'                  => ['required', 'string', 'max:20'],

            // Business details
            'entity_type'          => ['required', 'in:corporation,llc,partnership,nonprofit,other'],
            'state_of_formation'   => ['required', 'string', 'size:2'],

            // Account intent (collected at this step for business accounts)
            'account_intent'       => ['required', 'in:buyer,seller,buyer_and_seller'],
        ];
    }
}
