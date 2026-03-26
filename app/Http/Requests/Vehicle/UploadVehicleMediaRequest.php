<?php

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;

class UploadVehicleMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // admin route guarded by role:admin middleware
    }

    public function rules(): array
    {
        return [
            'files'   => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => [
                'required',
                'file',
                // Images: jpeg, jpg, png, webp  |  Videos: mp4, mov, webm
                'mimetypes:image/jpeg,image/jpg,image/png,image/webp,video/mp4,video/quicktime,video/webm',
                'max:51200', // 50 MB per file
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'files.required'       => 'At least one file is required.',
            'files.max'            => 'You may upload at most 20 files at a time.',
            'files.*.mimetypes'    => 'Accepted types: JPEG, PNG, WebP, MP4, MOV, WebM.',
            'files.*.max'          => 'Each file must be smaller than 50 MB.',
        ];
    }
}
