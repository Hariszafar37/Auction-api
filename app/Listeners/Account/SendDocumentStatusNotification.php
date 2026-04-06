<?php

namespace App\Listeners\Account;

use App\Events\Account\DocumentStatusUpdated;
use App\Notifications\DocumentStatusUpdatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendDocumentStatusNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(DocumentStatusUpdated $event): void
    {
        $user = $event->document->user;
        if (! $user) {
            return;
        }

        $user->notify(new DocumentStatusUpdatedNotification($event->document));
    }
}
