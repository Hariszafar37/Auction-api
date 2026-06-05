<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload that persists a reusable Stripe PaymentMethod.
 *
 * The card itself is collected and confirmed client-side via Stripe Elements +
 * a SetupIntent; only the resulting `payment_method_id` (pm_...) and the
 * cardholder name reach the backend. Raw PAN / CVV are never transmitted.
 */
class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'string', 'starts_with:pm_', 'max:100'],
            'cardholder_name'   => ['nullable', 'string', 'min:2', 'max:100'],
        ];
    }
}
