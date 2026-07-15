<?php

namespace App\Notifications;

use App\Models\NotificationTemplate;
use App\Notifications\Concerns\RendersFromTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An admin-composed announcement, sent manually to an audience.
 *
 * Unlike the system notifications, an announcement carries its own template row
 * (the one the admin authored), so it renders that row directly rather than
 * looking one up by key. Everything else — channel gating, {{first_name}} /
 * {{app_name}} substitution, the broadcast payload — is the shared trait.
 */
class AnnouncementNotification extends Notification implements ShouldQueue
{
    use Queueable, RendersFromTemplate;

    public function __construct(
        private readonly NotificationTemplate $announcement,
    ) {}

    /** Render the injected row instead of resolving one by key. */
    protected function template(): NotificationTemplate
    {
        return $this->announcement;
    }

    protected function templateKey(): string
    {
        return $this->announcement->key;
    }

    protected function templateVariables(mixed $notifiable): array
    {
        // Announcements only ever use the base variables (first_name / app_name),
        // which the trait already supplies.
        return [];
    }

    protected function actionPayload(): array
    {
        // No CTA button: announcements are informational, and an admin-typed link
        // is not something we let through the composer. A null action_url makes the
        // trait skip the button.
        return [
            'action_url' => null,
            'meta'       => ['announcement_id' => $this->announcement->id],
        ];
    }
}
