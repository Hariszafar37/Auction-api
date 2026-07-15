<?php

namespace App\Notifications;

use App\Notifications\Concerns\RendersFromTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent to users when their account application is approved by an admin.
 * Covers dealer, business, individual seller, and government account types —
 * each is its own template variant, because the copy differs per context.
 */
class AccountApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable, RendersFromTemplate;

    private const CONTEXTS = ['dealer', 'business', 'seller', 'government'];

    /** @param string $context 'dealer'|'business'|'seller'|'government' */
    public function __construct(
        private readonly string $context,
    ) {}

    protected function templateKey(): string
    {
        $variant = in_array($this->context, self::CONTEXTS, true) ? $this->context : 'default';

        return "account_approved.{$variant}";
    }

    protected function templateVariables(mixed $notifiable): array
    {
        return [];
    }

    protected function actionPayload(): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        // Approved sellers land on the vehicle form; everyone else on the dashboard.
        $path = $this->context === 'seller' ? '/vehicles/add' : '/dashboard';

        return [
            'action_url' => "{$frontendUrl}{$path}",
            'meta'       => ['context' => $this->context],
        ];
    }
}
