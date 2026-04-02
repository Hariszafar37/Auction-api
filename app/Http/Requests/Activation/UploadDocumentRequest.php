<?php

namespace App\Http\Requests\Activation;

use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'in:id,dealer_license,salesman_license,articles_of_incorporation,authority_letter,power_of_attorney,bill_of_sale,other'],
            'file'          => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max'   => 'File size must not exceed 2MB.',
            'file.mimes' => 'Only JPG, PNG, and PDF files are accepted.',
        ];
    }
}
