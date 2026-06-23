<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full settings payload for the admin Payment Settings screen.
 * (Buyer-facing instructions are served separately, per-method, by the
 * payment-options endpoint so buyers only ever see enabled methods.)
 */
class PaymentSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'business_days_to_pay'  => (int) $this->business_days_to_pay,
            'late_fee_amount'       => (float) $this->late_fee_amount,

            'method_stripe_enabled' => (bool) $this->method_stripe_enabled,
            'method_wire_enabled'   => (bool) $this->method_wire_enabled,
            'method_cash_enabled'   => (bool) $this->method_cash_enabled,
            'method_check_enabled'  => (bool) $this->method_check_enabled,

            'payment_location'      => $this->payment_location,
            'office_hours'          => $this->office_hours,

            'check_payable_to'      => $this->check_payable_to,
            'check_mailing_address' => $this->check_mailing_address,
            'accepted_carriers'     => $this->accepted_carriers ?? [],

            'wire_beneficiary_name' => $this->wire_beneficiary_name,
            'wire_bank_name'        => $this->wire_bank_name,
            'wire_routing_number'   => $this->wire_routing_number,
            'wire_account_number'   => $this->wire_account_number,
            'wire_swift_code'       => $this->wire_swift_code,
            'wire_business_address' => $this->wire_business_address,
            'wire_notes'            => $this->wire_notes,

            'payment_deadline_notice' => $this->payment_deadline_notice,
            'acknowledgment_text'     => $this->acknowledgment_text,

            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
