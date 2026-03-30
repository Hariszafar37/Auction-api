<?php

namespace App\Http\Requests\Activation;

use Illuminate\Foundation\Http\FormRequest;

class AccountTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // government is reserved for admin-created accounts only — not selectable via public endpoint
            'account_type' => ['required', 'in:individual,dealer,business'],
        ];
    }
}
