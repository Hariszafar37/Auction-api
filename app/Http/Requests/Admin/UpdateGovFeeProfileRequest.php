<?php

namespace App\Http\Requests\Admin;

use App\Models\SellerFeeProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGovFeeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    public function rules(): array
    {
        // A percentage commission is capped at 100%; a flat one is dollars.
        $commissionMax = $this->input('commission_type') === SellerFeeProfile::COMMISSION_PERCENT
            ? 100
            : 100000;

        return [
            'registration_fee' => ['required', 'numeric', 'min:0', 'max:100000'],
            'commission_type'  => ['required', Rule::in(SellerFeeProfile::COMMISSION_TYPES)],
            'commission_value' => ['required', 'numeric', 'min:0', 'max:' . $commissionMax],
            'no_sale_fee'      => ['required', 'numeric', 'min:0', 'max:100000'],
            'notes'            => ['nullable', 'string', 'max:2000'],
        ];
    }
}
