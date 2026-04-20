<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case StripeCard = 'stripe_card';
    case Cash       = 'cash';
    case Check      = 'check';
    case Deposit    = 'deposit';
    case Other      = 'other';

    public function label(): string
    {
        return match($this) {
            self::StripeCard => 'Credit / Debit Card',
            self::Cash       => 'Cash',
            self::Check      => 'Check',
            self::Deposit    => 'Deposit',
            self::Other      => 'Other',
        };
    }
}
