<?php

namespace App\Listeners\Account;

use App\Events\Account\POARevisionRequested;
use App\Notifications\PoaRevisionRequestedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPOARevisionRequestedNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(POARevisionRequested $event): void
    {
        $event->user->notify(new PoaRevisionRequestedNotification($event->adminNotes));
    }
}
