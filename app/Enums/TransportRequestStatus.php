<?php

namespace App\Enums;

enum TransportRequestStatus: string
{
    case Pending   = 'pending';
    case Quoted    = 'quoted';
    case Arranged  = 'arranged';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Quoted    => 'Quoted',
            self::Arranged  => 'Arranged',
            self::Cancelled => 'Cancelled',
        };
    }
}
