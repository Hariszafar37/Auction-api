<?php

namespace App\Enums;

enum AuctionStatus: string
{
    case Draft     = 'draft';
    case Scheduled = 'scheduled';
    case Live      = 'live';
    case Ended     = 'ended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Draft     => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Live      => 'Live',
            self::Ended     => 'Ended',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match($this) {
            self::Draft     => in_array($next, [self::Scheduled, self::Cancelled]),
            self::Scheduled => in_array($next, [self::Live, self::Cancelled]),
            self::Live      => in_array($next, [self::Ended, self::Cancelled]),
            self::Ended     => false,
            self::Cancelled => false,
        };
    }
}
