<?php

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;

class ReorderVehicleMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:media,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required'  => 'A list of media IDs is required.',
            'ids.*.exists'  => 'One or more media IDs are invalid.',
        ];
    }
}
