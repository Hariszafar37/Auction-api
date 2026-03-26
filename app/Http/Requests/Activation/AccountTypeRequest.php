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
            'account_type' => ['required', 'in:individual,dealer'],
        ];
    }
}
