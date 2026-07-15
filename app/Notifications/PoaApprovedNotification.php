<?php

namespace App\Notifications;

use App\Notifications\Concerns\RendersFromTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent to a seller when their Power of Attorney document is approved by an admin.
 */
class PoaApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable, RendersFromTemplate;

    protected function templateKey(): string
    {
        return 'poa_approved';
    }

    protected function templateVariables(mixed $notifiable): array
    {
        return [];
    }

    protected function actionPayload(): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'action_url' => "{$frontendUrl}/vehicles",
            'meta'       => [],
        ];
    }
}
