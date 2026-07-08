<?php

namespace App\Notifications;

use App\Notifications\Concerns\HasBroadcastPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a seller when an admin requests a revision to their Power of Attorney.
 *
 * Distinct from PoaRejectedNotification: a revision request signals the document
 * is recoverable and the user is expected to re-submit a corrected version,
 * mirroring the user_documents `needs_resubmission` loop.
 */
class PoaRevisionRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable, HasBroadcastPayload;

    public function __construct(
        private readonly ?string $adminNotes,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        $mail = (new MailMessage)
            ->subject('Action Required: Please Revise Your Power of Attorney')
            ->greeting('Hello ' . ($notifiable->first_name ?? $notifiable->name) . ',')
            ->line('Your Power of Attorney (POA) document has been reviewed and a revision is required before it can be approved.');

        if ($this->adminNotes) {
            $mail->line('**What needs to change:** ' . $this->adminNotes);
        }

        return $mail
            ->line('Please submit a revised POA document to proceed with vehicle submissions.')
            ->action('Revise POA', "{$frontendUrl}/poa")
            ->line('Contact our support team if you need assistance.');
    }

    public function toDatabase(mixed $notifiable): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'type'       => 'poa_revision_requested',
            'title'      => 'Power of Attorney revision requested',
            'message'    => 'A revision to your POA has been requested — please submit a corrected document.',
            'action_url' => "{$frontendUrl}/poa",
            'meta'       => ['admin_notes' => $this->adminNotes],
        ];
    }
}
