<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit of the government ID block held on user_account_information
 * (id_type / id_number / issuing country / issuing state / expiry).
 *
 * Split out of UpdateAccountInformationRequest so the ID details can be saved
 * on their own — the residential address and date of birth live in the same
 * table row but are edited from a separate section, and requiring the full
 * address payload just to correct an ID number would fail validation for any
 * user whose address block is incomplete.
 *
 * Used by both the self-service profile editor and the admin user-detail page.
 */
class UpdateGovernmentIdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_type'            => ['required', 'in:driver_license,state_id,passport'],
            'id_number'          => ['required', 'string', 'max:100'],
            'id_issuing_country' => ['nullable', 'string', 'max:2'],
            // A passport is issued nationally — no state applies.
            'id_issuing_state'   => ['nullable', 'string', 'max:100'],
            'id_expiry'          => ['nullable', 'date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'id_type'            => 'ID type',
            'id_number'          => 'ID number',
            'id_issuing_country' => 'issuing country',
            'id_issuing_state'   => 'issuing state',
            'id_expiry'          => 'ID expiry',
        ];
    }
}
