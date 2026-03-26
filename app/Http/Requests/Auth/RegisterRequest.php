<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'              => ['required', 'email', 'max:255', 'unique:users,email'],
            'email_confirmation' => ['required', 'same:email'],
            'first_name'         => ['required', 'string', 'max:100'],
            'last_name'          => ['required', 'string', 'max:100'],
            'primary_phone'      => ['required', 'string', 'regex:/^[\+]?[\d\s\-\(\)]{7,30}$/', 'max:30'],
            'secondary_phone'    => ['nullable', 'string', 'regex:/^[\+]?[\d\s\-\(\)]{7,30}$/', 'max:30'],
            'consent_marketing'  => ['nullable', 'boolean'],
            'agree_terms'        => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'             => 'An account with this email already exists.',
            'email_confirmation.same'  => 'The email addresses do not match.',
            'agree_terms.accepted'     => 'You must accept the terms and conditions to register.',
            'primary_phone.regex'      => 'Please provide a valid phone number.',
        ];
    }
}
