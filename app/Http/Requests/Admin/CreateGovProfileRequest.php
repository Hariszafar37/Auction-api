<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CreateGovProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'entity_name'           => ['required', 'string', 'max:255'],
            'entity_subtype'        => ['required', 'in:government,charity,repo'],
            'department_division'   => ['nullable', 'string', 'max:255'],
            'point_of_contact_name' => ['required', 'string', 'max:255'],
            'contact_title'         => ['nullable', 'string', 'max:255'],
            'email'                 => ['required', 'email', 'unique:users,email'],
            'phone'                 => ['required', 'string', 'max:30'],
            'office_phone'          => ['nullable', 'string', 'max:30'],
            'address'               => ['required', 'string', 'max:500'],
            'city'                  => ['required', 'string', 'max:100'],
            'state'                 => ['required', 'string', 'max:100'],
            'zip'                   => ['required', 'string', 'max:20'],
        ];
    }
}
