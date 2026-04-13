<?php

namespace App\Http\Requests\User;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a card submission for the payment-method endpoint.
 *
 * IMPORTANT: card_number and cvv are validated here but MUST NEVER be
 * persisted by the controller. Only derived metadata (brand, last four,
 * expiry) is stored. See PaymentMethodController::store().
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
            'cardholder_name' => ['required', 'string', 'min:2', 'max:100'],
            'card_number'     => ['required', 'string'],
            'expiry_month'    => ['required', 'integer', 'between:1,12'],
            'expiry_year'     => ['required', 'integer', 'digits:4', 'gte:' . now()->year],
            'cvv'             => ['required', 'string'],
        ];
    }

    /**
     * Additional checks: Luhn, length, CVV length, and not-expired.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $number = preg_replace('/\D/', '', (string) $this->input('card_number', ''));
            $cvv    = (string) $this->input('cvv', '');
            $month  = (int) $this->input('expiry_month', 0);
            $year   = (int) $this->input('expiry_year', 0);

            if (strlen($number) < 13 || strlen($number) > 19) {
                $v->errors()->add('card_number', 'Card number must be between 13 and 19 digits.');
            } elseif (! self::passesLuhn($number)) {
                $v->errors()->add('card_number', 'Card number is invalid.');
            }

            if (! preg_match('/^\d{3,4}$/', $cvv)) {
                $v->errors()->add('cvv', 'CVV must be 3 or 4 digits.');
            }

            // Reject cards whose month has already passed in the current year.
            if ($month && $year) {
                $now = now();
                if ($year === $now->year && $month < $now->month) {
                    $v->errors()->add('expiry_month', 'Card is expired.');
                }
            }
        });
    }

    /**
     * Luhn mod-10 checksum.
     */
    private static function passesLuhn(string $number): bool
    {
        $sum    = 0;
        $alt    = false;
        $digits = str_split(strrev($number));

        foreach ($digits as $d) {
            $n = (int) $d;
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }
}
