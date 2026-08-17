<?php

namespace App\Http\Requests\Vehicle;

use App\Rules\VehicleMediaFile;
use App\Support\MediaUploadLimits;
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
            'files'   => ['required', 'array', 'min:1', 'max:' . MediaUploadLimits::MAX_FILES_PER_REQUEST],
            // Format and size are checked by VehicleMediaFile, which knows the
            // per-type ceilings and can name the file in its message. The stock
            // file/mimetypes/max chain could do neither.
            //
            // Deliberately no 'required' here. When any implicit or file rule is
            // present, Laravel short-circuits an invalid UploadedFile straight to
            // its own 'uploaded' message ("The files.0 failed to upload.") and
            // never reaches this rule — which is precisely the unhelpful wording
            // that made a php.ini limit look like bad user data. The parent
            // 'required|array|min:1' already guarantees at least one entry, and
            // VehicleMediaFile rejects anything that is not a usable file.
            'files.*' => [new VehicleMediaFile()],
        ];
    }

    public function messages(): array
    {
        return [
            'files.required' => 'Please choose at least one photo or video to upload.',
            'files.array'    => 'Please choose at least one photo or video to upload.',
            'files.min'      => 'Please choose at least one photo or video to upload.',
            'files.max'      => 'You can upload up to ' . MediaUploadLimits::MAX_FILES_PER_REQUEST . ' files at a time.',
        ];
    }
}
