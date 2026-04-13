<?php

namespace App\Support;

/**
 * Pure helper to detect card brand from PAN prefix.
 * Kept as a static helper so it can be reused in tests without booting the app.
 */
class CardBrand
{
    public static function detect(string $number): string
    {
        $n = preg_replace('/\D/', '', $number);

        if (preg_match('/^4/', $n)) {
            return 'visa';
        }
        if (preg_match('/^3[47]/', $n)) {
            return 'amex';
        }
        if (preg_match('/^(5[1-5]|2[2-7])/', $n)) {
            return 'mastercard';
        }
        if (preg_match('/^(6011|65|64[4-9])/', $n)) {
            return 'discover';
        }
        if (preg_match('/^35/', $n)) {
            return 'jcb';
        }
        if (preg_match('/^3[068]/', $n)) {
            return 'diners';
        }

        return 'card';
    }
}
