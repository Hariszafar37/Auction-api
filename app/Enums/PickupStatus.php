<?php

namespace App\Enums;

enum PickupStatus: string
{
    case AwaitingPayment  = 'awaiting_payment';
    case ReadyForPickup   = 'ready_for_pickup';
    case GatePassIssued   = 'gate_pass_issued';
    case PickedUp         = 'picked_up';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'Awaiting Payment',
            self::ReadyForPickup  => 'Ready for Pickup',
            self::GatePassIssued  => 'Gate Pass Issued',
            self::PickedUp        => 'Picked Up',
        };
    }

    /** Returns the next allowed status, or null if terminal. */
    public function next(): ?self
    {
        return match ($this) {
            self::AwaitingPayment => self::ReadyForPickup,
            self::ReadyForPickup  => self::GatePassIssued,
            self::GatePassIssued  => self::PickedUp,
            self::PickedUp        => null,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return $this->next() === $target;
    }
}
