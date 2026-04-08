<?php

namespace App\Policies;

use App\Models\PowerOfAttorney;
use App\Models\User;

/**
 * Authorization rules for Power of Attorney uploads. Same ownership model as
 * UserDocumentPolicy — owners see their own POA, admins see any.
 */
class PowerOfAttorneyPolicy
{
    public function view(User $user, PowerOfAttorney $poa): bool
    {
        if ($user->id === $poa->user_id) {
            return true;
        }

        return $user->hasRole('admin');
    }
}
