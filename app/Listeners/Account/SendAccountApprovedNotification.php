<?php

namespace App\Listeners\Account;

use App\Events\Account\AccountApproved;
use App\Notifications\AccountApprovedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendAccountApprovedNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(AccountApproved $event): void
    {
        $event->user->notify(new AccountApprovedNotification($event->context));
    }
}
