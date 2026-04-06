<?php

namespace App\Listeners\Account;

use App\Events\Account\POAApproved;
use App\Notifications\PoaApprovedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPOAApprovedNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(POAApproved $event): void
    {
        $event->user->notify(new PoaApprovedNotification());
    }
}
