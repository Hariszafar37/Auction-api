<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-service edit of a business account's company information.
 *
 * Unlike the activation step, this deliberately omits `account_intent` —
 * changing buy/sell intent post-activation has downstream POA/compliance
 * implications and is not a simple profile edit. The business approval
 * snapshot (BusinessProfile) is left untouched.
 */
class UpdateBusinessInformationRequest extends FormRequest
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
        ];
    }
}
