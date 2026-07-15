<?php

namespace App\Notifications;

use App\Notifications\Concerns\RendersFromTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent to users when their account application is rejected by an admin.
 * Covers dealer, business, individual seller, and government account types —
 * each is its own template variant, because the copy differs per context.
 */
class AccountRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable, RendersFromTemplate;

    private const CONTEXTS = ['dealer', 'business', 'seller', 'government'];

    /**
     * @param string|null $reason  Admin-provided rejection reason (may be null for some flows)
     * @param string      $context 'dealer'|'business'|'seller'|'government'
     */
    public function __construct(
        private readonly ?string $reason,
        private readonly string  $context,
    ) {}

    protected function templateKey(): string
    {
        $variant = in_array($this->context, self::CONTEXTS, true) ? $this->context : 'default';

        return "account_rejected.{$variant}";
    }

    protected function templateVariables(mixed $notifiable): array
    {
        return ['reason' => $this->reason];
    }

    protected function actionPayload(): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'action_url' => "{$frontendUrl}/dashboard",
            'meta'       => [
                'context' => $this->context,
                'reason'  => $this->reason,
            ],
        ];
    }

    /**
     * The in-app message defaults to the admin's reason. When no reason was given
     * the {{reason}} placeholder renders empty, so fall back to a generic line
     * rather than showing the user a blank notification.
     */
    public function toDatabase(mixed $notifiable): array
    {
        $payload = $this->renderDatabasePayload($notifiable);

        if (trim((string) $payload['message']) === '') {
            $payload['message'] = 'Your application could not be approved at this time.';
        }

        return $payload;
    }
}
