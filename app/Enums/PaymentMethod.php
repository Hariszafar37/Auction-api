<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case StripeCard = 'stripe_card';
    case Wire       = 'wire';
    case Cash       = 'cash';
    case Check      = 'check';
    case Deposit    = 'deposit';
    case Other      = 'other';

    public function label(): string
    {
        return match($this) {
            self::StripeCard => 'Credit / Debit Card',
            self::Wire       => 'Wire Transfer',
            self::Cash       => 'Cash',
            self::Check      => "Cashier's Check",
            self::Deposit    => 'Deposit',
            self::Other      => 'Other',
        };
    }

    /**
     * Non-card methods that follow the manual acknowledge → submit → verify
     * workflow (funds arrive off-platform and are recorded/verified by staff).
     */
    public function isNonCard(): bool
    {
        return in_array($this, [self::Wire, self::Cash, self::Check], true);
    }
}
