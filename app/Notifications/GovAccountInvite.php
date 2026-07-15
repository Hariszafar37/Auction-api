<?php

namespace App\Notifications;

use App\Notifications\Concerns\RendersFromTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to government/charity/repo accounts when admin issues an invitation.
 *
 * Mail-only. The invite URL is built here, never in the template — a mistyped link
 * in admin-authored copy would silently break account setup.
 */
class GovAccountInvite extends Notification
{
    use Queueable, RendersFromTemplate;

    public function __construct(
        private readonly string $inviteToken,
    ) {}

    protected function templateKey(): string
    {
        return 'gov_account_invite';
    }

    protected function templateVariables(mixed $notifiable): array
    {
        return [];
    }

    protected function actionPayload(): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'action_url' => "{$frontendUrl}/accept-invite?token={$this->inviteToken}",
            'meta'       => [],
        ];
    }
}
