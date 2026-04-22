<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Models\PurchaseDetail;
use App\Models\User;

class PurchasePolicy
{
    public function view(User $user, PurchaseDetail $purchase): bool
    {
        return $user->id === $purchase->buyer_id;
    }

    public function downloadGatePass(User $user, PurchaseDetail $purchase): bool
    {
        return $user->id === $purchase->buyer_id
            && $purchase->invoice?->status === InvoiceStatus::Paid;
    }

    public function requestTransport(User $user, PurchaseDetail $purchase): bool
    {
        return $user->id === $purchase->buyer_id;
    }
}
