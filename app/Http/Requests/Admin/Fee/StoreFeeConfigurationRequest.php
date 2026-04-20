<?php

namespace App\Http\Requests\Admin\Fee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeeConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'fee_type'         => ['required', Rule::in(['deposit', 'buyer_fee', 'tax', 'tags', 'storage'])],
            'label'            => ['required', 'string', 'max:100'],
            'calculation_type' => ['required', Rule::in(['flat', 'percentage', 'tiered', 'per_day', 'flat_range'])],
            'amount'           => ['nullable', 'numeric', 'min:0'],
            'min_amount'       => ['nullable', 'numeric', 'min:0'],
            'max_amount'       => ['nullable', 'numeric', 'min:0', 'gte:min_amount'],
            'location'         => ['nullable', 'string', 'max:200'],
            'applies_to'       => ['sometimes', Rule::in(['buyer', 'seller', 'both'])],
            'is_active'        => ['sometimes', 'boolean'],
            'sort_order'       => ['sometimes', 'integer', 'min:0'],
            'notes'            => ['nullable', 'string', 'max:1000'],
        ];
    }
}
