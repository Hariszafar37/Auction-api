<?php

namespace App\Policies;

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
        // Released only when fully paid with no payment awaiting verification,
        // or when an admin has overridden the release restriction.
        return $user->id === $purchase->buyer_id
            && $purchase->canRelease();
    }

    public function requestTransport(User $user, PurchaseDetail $purchase): bool
    {
        return $user->id === $purchase->buyer_id;
    }
}
