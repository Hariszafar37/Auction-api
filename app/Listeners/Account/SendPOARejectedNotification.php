<?php

namespace App\Listeners\Account;

use App\Events\Account\POARejected;
use App\Notifications\PoaRejectedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPOARejectedNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(POARejected $event): void
    {
        $event->user->notify(new PoaRejectedNotification($event->adminNotes));
    }
}
