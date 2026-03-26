<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ActivateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isDealer = $this->input('account_type') === 'dealer';

        return [
            // ── Account type ────────────────────────────────────────────
            'account_type' => ['required', 'in:buyer,dealer'],

            // ── Personal / identity ──────────────────────────────────────
            'dob'                            => ['required', 'date_format:Y-m-d', 'before:-18 years'],
            'driver_license_number'          => ['required', 'string', 'max:50'],
            'driver_license_expiration_date' => ['required', 'date_format:Y-m-d', 'after:today'],

            // ── Personal address ─────────────────────────────────────────
            'address'              => ['required', 'array'],
            'address.address1'     => ['required', 'string', 'max:255'],
            'address.address2'     => ['nullable', 'string', 'max:255'],
            'address.city'         => ['required', 'string', 'max:100'],
            'address.state'        => ['required', 'string', 'max:100'],
            'address.postal_code'  => ['required', 'string', 'max:20'],
            'address.country'      => ['required', 'string', 'size:2'],

            // ── ID documents ─────────────────────────────────────────────
            'id_document_front' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'id_document_back'  => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],

            // ── Dealer fields (required only when account_type=dealer) ───
            'company_name'                        => [$isDealer ? 'required' : 'nullable', 'string', 'max:255'],
            'owner_name'                          => [$isDealer ? 'required' : 'nullable', 'string', 'max:255'],
            'phone_primary'                       => [$isDealer ? 'required' : 'nullable', 'string', 'max:20'],
            'primary_contact'                     => [$isDealer ? 'required' : 'nullable', 'string', 'max:255'],
            'dealer_license_number'               => [$isDealer ? 'required' : 'nullable', 'string', 'max:100'],
            'dealer_license_expiration_date'      => [$isDealer ? 'required' : 'nullable', 'date_format:Y-m-d', 'after:today'],
            'tax_id_number'                       => [$isDealer ? 'required' : 'nullable', 'string', 'max:50'],
            'dealer_address'                      => [$isDealer ? 'required' : 'nullable', 'array'],
            'dealer_address.address1'             => [$isDealer ? 'required' : 'nullable', 'string', 'max:255'],
            'dealer_address.address2'             => ['nullable', 'string', 'max:255'],
            'dealer_address.city'                 => [$isDealer ? 'required' : 'nullable', 'string', 'max:100'],
            'dealer_address.state'                => [$isDealer ? 'required' : 'nullable', 'string', 'max:100'],
            'dealer_address.postal_code'          => [$isDealer ? 'required' : 'nullable', 'string', 'max:20'],
            'dealer_address.country'              => [$isDealer ? 'required' : 'nullable', 'string', 'size:2'],
            'dealer_license_document'             => [$isDealer ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'dob.before'                             => 'You must be at least 18 years old.',
            'driver_license_expiration_date.after'   => 'Driver license must not be expired.',
            'dealer_license_expiration_date.after'   => 'Dealer license must not be expired.',
            'id_document_front.required'             => 'A front image of your ID is required.',
            'dealer_license_document.required'       => 'Dealer license document is required for dealer accounts.',
        ];
    }
}
