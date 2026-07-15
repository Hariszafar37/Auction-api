<?php

namespace App\Notifications;

use App\Notifications\Concerns\RendersFromTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent to a seller when their Power of Attorney document is rejected by an admin.
 */
class PoaRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable, RendersFromTemplate;

    public function __construct(
        private readonly ?string $adminNotes,
    ) {}

    protected function templateKey(): string
    {
        return 'poa_rejected';
    }

    protected function templateVariables(mixed $notifiable): array
    {
        return ['admin_notes' => $this->adminNotes];
    }

    protected function actionPayload(): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'action_url' => "{$frontendUrl}/poa",
            'meta'       => ['admin_notes' => $this->adminNotes],
        ];
    }
}
