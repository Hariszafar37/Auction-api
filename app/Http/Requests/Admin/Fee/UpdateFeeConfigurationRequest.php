<?php

namespace App\Http\Requests\Admin\Fee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeeConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'label'            => ['sometimes', 'string', 'max:100'],
            'calculation_type' => ['sometimes', Rule::in(['flat', 'percentage', 'tiered', 'per_day', 'flat_range'])],
            'amount'           => ['nullable', 'numeric', 'min:0'],
            'min_amount'       => ['nullable', 'numeric', 'min:0'],
            'max_amount'       => ['nullable', 'numeric', 'min:0'],
            'location'         => ['nullable', 'string', 'max:200'],
            'applies_to'       => ['sometimes', Rule::in(['buyer', 'seller', 'both'])],
            'is_active'        => ['sometimes', 'boolean'],
            'sort_order'       => ['sometimes', 'integer', 'min:0'],
            'notes'            => ['nullable', 'string', 'max:1000'],

            // Tiers (full replace when provided)
            'tiers'                           => ['sometimes', 'array'],
            'tiers.*.sale_price_from'         => ['required_with:tiers', 'integer', 'min:0'],
            'tiers.*.sale_price_to'           => ['nullable', 'integer', 'min:0'],
            'tiers.*.fee_calculation_type'    => ['required_with:tiers', Rule::in(['flat', 'percentage'])],
            'tiers.*.fee_amount'              => ['required_with:tiers', 'numeric', 'min:0'],
            'tiers.*.sort_order'              => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
