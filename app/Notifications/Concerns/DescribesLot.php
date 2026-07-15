<?php

namespace App\Notifications\Concerns;

use App\Models\AuctionLot;

/**
 * Shared formatting for lot-related notifications. Every one of them needs to name
 * a vehicle (falling back to the lot number when the vehicle is missing) and format
 * a bid amount, so the logic lives here rather than being copy-pasted four times.
 */
trait DescribesLot
{
    protected function vehicleName(AuctionLot $lot): string
    {
        $vehicle = $lot->vehicle;

        return $vehicle
            ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}"
            : "Lot {$lot->lot_number}";
    }

    protected function money(int|float|null $amount): string
    {
        if ($amount === null) {
            return '';
        }

        return '$' . number_format((int) $amount);
    }
}
