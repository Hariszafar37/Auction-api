<?php

namespace App\Enums;

/**
 * Classifies every row in the invoice_payments ledger.
 *
 * Two families:
 *   - PAYMENT-SIDE (affect amount credited toward the invoice):
 *       payment  (+)  money received
 *       reversal (−)  undo a prior payment
 *       refund   (−)  money returned to the buyer
 *   - CHARGE-SIDE (affect the invoice's effective total):
 *       adjustment (±) admin correction to what is owed
 *                      (e.g. +$50 late fee, −$500 goodwill discount)
 *       debit      (+) manual additional charge
 *       credit     (−) buyer-credit / refund-due marker recorded against
 *                      an overpayment (informational; never drives balance
 *                      below $0)
 *
 * Ledger rows are immutable and soft-deleted only — never hard-deleted.
 */
enum PaymentTransactionType: string
{
    case Payment    = 'payment';
    case Adjustment = 'adjustment';
    case Reversal   = 'reversal';
    case Refund     = 'refund';
    case Credit     = 'credit';
    case Debit      = 'debit';

    public function label(): string
    {
        return match ($this) {
            self::Payment    => 'Payment',
            self::Adjustment => 'Adjustment',
            self::Reversal   => 'Reversal',
            self::Refund     => 'Refund',
            self::Credit     => 'Credit',
            self::Debit      => 'Debit',
        };
    }

    /** Types whose signed amount counts toward amount_paid (money in/out). */
    public static function paymentSide(): array
    {
        return [self::Payment->value, self::Reversal->value, self::Refund->value];
    }

    /** Types whose signed amount adjusts the invoice's effective total. */
    public static function chargeSide(): array
    {
        return [self::Adjustment->value, self::Debit->value];
    }
}
