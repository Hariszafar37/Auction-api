<?php

namespace App\Enums;

enum LotStatus: string
{
    case Pending        = 'pending';
    case Open           = 'open';
    case Countdown      = 'countdown';
    case IfSale         = 'if_sale';
    case Sold           = 'sold';
    case ReserveNotMet  = 'reserve_not_met';
    case NoSale         = 'no_sale';
    case Cancelled      = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Pending       => 'Pending',
            self::Open          => 'Open',
            self::Countdown     => 'Countdown',
            self::IfSale        => 'If Sale',
            self::Sold          => 'Sold',
            self::ReserveNotMet => 'Reserve Not Met',
            self::NoSale        => 'No Sale',
            self::Cancelled     => 'Cancelled',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::Countdown]);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Sold, self::ReserveNotMet, self::NoSale, self::Cancelled]);
    }

    public function canTransitionTo(self $next): bool
    {
        return match($this) {
            self::Pending    => in_array($next, [self::Open, self::Cancelled]),
            self::Open       => in_array($next, [self::Countdown, self::Cancelled]),
            self::Countdown  => in_array($next, [
                self::Open, // new bid resets countdown
                self::IfSale,
                self::Sold,
                self::ReserveNotMet,
                self::NoSale,
            ]),
            self::IfSale     => in_array($next, [self::Sold, self::ReserveNotMet]),
            default          => false,
        };
    }
}
