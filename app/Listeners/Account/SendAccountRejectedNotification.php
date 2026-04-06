<?php

namespace App\Listeners\Account;

use App\Events\Account\AccountRejected;
use App\Notifications\AccountRejectedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendAccountRejectedNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(AccountRejected $event): void
    {
        $event->user->notify(new AccountRejectedNotification($event->reason, $event->context));
    }
}
