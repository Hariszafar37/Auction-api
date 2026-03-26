<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class DealerRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'               => ['required', 'string', 'max:255'],
            'email'              => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'           => ['required', 'string', 'min:8', 'confirmed'],
            'phone'              => ['nullable', 'string', 'max:20'],
            'company_name'       => ['required', 'string', 'max:255'],
            'dealer_license'     => ['required', 'string', 'max:100'],
            'packet_accepted'    => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'packet_accepted.accepted' => 'You must accept the dealer packet to register.',
        ];
    }
}
