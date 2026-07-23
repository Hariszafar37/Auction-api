<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-service edit of the authenticated user's personal/contact information
 * (the residential / "shipping" address and date of birth).
 *
 * Mirrors the address half of Activation\AccountInformationRequest but is used
 * by active users editing their own profile — the activation wizard's
 * isActive() guard does NOT apply here.
 *
 * The government ID fields live on the same table row but are edited through
 * their own section — see UpdateGovernmentIdRequest. Any id_* key posted here
 * is ignored rather than written, so editing an address can never blank out an
 * ID on file.
 */
class UpdateAccountInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_of_birth'      => ['required', 'date_format:Y-m-d', 'before:-18 years'],
            'address'            => ['required', 'string', 'max:500'],
            'country'            => ['required', 'string', 'size:2'],
            'state'              => ['required', 'string', 'max:100'],
            'county'             => ['nullable', 'string', 'max:100'],
            'city'               => ['required', 'string', 'max:100'],
            'zip_postal_code'    => ['required', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_of_birth.before' => 'You must be at least 18 years old.',
        ];
    }
}
