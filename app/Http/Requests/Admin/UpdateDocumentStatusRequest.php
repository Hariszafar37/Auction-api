<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'status'      => ['required', 'in:approved,rejected,needs_resubmission'],
            'admin_notes' => ['nullable', 'string'],
        ];
    }
}
