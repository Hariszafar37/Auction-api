<?php

namespace App\Events\Account;

use App\Models\PowerOfAttorney;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class POARejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User             $user,
        public readonly PowerOfAttorney  $poa,
        public readonly string|null      $adminNotes = null,
    ) {}
}
