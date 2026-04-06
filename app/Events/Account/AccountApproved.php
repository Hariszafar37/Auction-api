<?php

namespace App\Events\Account;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccountApproved
{
    use Dispatchable, SerializesModels;

    /** @param string $context 'dealer'|'business'|'seller'|'government' */
    public function __construct(
        public readonly User   $user,
        public readonly string $context,
    ) {}
}
