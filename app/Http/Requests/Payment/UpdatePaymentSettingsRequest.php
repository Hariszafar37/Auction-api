<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'business_days_to_pay'  => ['sometimes', 'integer', 'min:0', 'max:60'],
            'late_fee_amount'       => ['sometimes', 'numeric', 'min:0', 'max:100000'],

            // Seller settlement fee rules
            'seller_registration_fee'     => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'seller_commission_rate'      => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'seller_commission_threshold' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'seller_commission_flat'      => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'seller_no_sale_fee'          => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'seller_release_days'         => ['sometimes', 'integer', 'min:0', 'max:365'],

            'method_stripe_enabled' => ['sometimes', 'boolean'],
            'method_wire_enabled'   => ['sometimes', 'boolean'],
            'method_cash_enabled'   => ['sometimes', 'boolean'],
            'method_check_enabled'  => ['sometimes', 'boolean'],

            'payment_location'      => ['sometimes', 'nullable', 'string', 'max:1000'],
            'office_hours'          => ['sometimes', 'nullable', 'string', 'max:1000'],

            'check_payable_to'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'check_mailing_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'accepted_carriers'     => ['sometimes', 'nullable', 'array'],
            'accepted_carriers.*'   => ['string', 'max:50'],

            'wire_beneficiary_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'wire_bank_name'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'wire_routing_number'   => ['sometimes', 'nullable', 'string', 'max:50'],
            'wire_account_number'   => ['sometimes', 'nullable', 'string', 'max:50'],
            'wire_swift_code'       => ['sometimes', 'nullable', 'string', 'max:50'],
            'wire_business_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'wire_notes'            => ['sometimes', 'nullable', 'string', 'max:2000'],

            'payment_deadline_notice' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'acknowledgment_text'     => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
