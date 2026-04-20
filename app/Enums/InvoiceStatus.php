<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft   = 'draft';
    case Pending = 'pending';
    case Partial = 'partial';
    case Paid    = 'paid';
    case Void    = 'void';

    public function label(): string
    {
        return match($this) {
            self::Draft   => 'Draft',
            self::Pending => 'Pending Payment',
            self::Partial => 'Partially Paid',
            self::Paid    => 'Paid',
            self::Void    => 'Void',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Partial]);
    }
}
