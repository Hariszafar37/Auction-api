<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                'pending_email_verification',
                'pending_password',
                'pending_activation',
                'active',
                'suspended',
                'blocked',
            ])],
            // Optional admin note captured on the account-action audit row.
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
