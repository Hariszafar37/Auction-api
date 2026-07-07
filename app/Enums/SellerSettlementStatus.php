<?php

namespace App\Enums;

enum SellerSettlementStatus: string
{
    case Pending         = 'pending';          // sold lot, awaiting release date
    case ReadyForRelease = 'ready_for_release'; // release date reached, ready to pay
    case CheckIssued     = 'check_issued';      // check written to the seller
    case Paid            = 'paid';              // check cleared / seller paid
    case NoSale          = 'no_sale';           // lot did not sell — fees due
    case Collected       = 'collected';         // no-sale fees collected from seller
    case Void            = 'void';              // lot removed / auction cancelled

    public function label(): string
    {
        return match ($this) {
            self::Pending         => 'Pending',
            self::ReadyForRelease => 'Ready For Release',
            self::CheckIssued     => 'Check Issued',
            self::Paid            => 'Paid',
            self::NoSale          => 'No Sale',
            self::Collected       => 'Fees Collected',
            self::Void            => 'Void',
        };
    }

    /**
     * Allowed forward transitions for the admin check workflow.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending         => in_array($target, [self::ReadyForRelease, self::Void], true),
            self::ReadyForRelease => in_array($target, [self::CheckIssued, self::Void], true),
            self::CheckIssued     => in_array($target, [self::Paid], true),
            self::NoSale          => in_array($target, [self::Collected], true),
            default               => false,
        };
    }

    /** Sold-lot settlements that still flow through the payout workflow. */
    public function isPayable(): bool
    {
        return in_array($this, [self::Pending, self::ReadyForRelease, self::CheckIssued], true);
    }
}
