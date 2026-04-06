<?php

namespace App\Notifications;

use App\Notifications\Concerns\HasBroadcastPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a seller when their Power of Attorney document is rejected by an admin.
 */
class PoaRejectedNotification extends Notification implements ShouldQueue
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
            ->subject('Action Required: Your Power of Attorney Was Not Approved')
            ->greeting('Hello ' . ($notifiable->first_name ?? $notifiable->name) . ',')
            ->line('Your Power of Attorney (POA) document has been reviewed and could not be approved.');

        if ($this->adminNotes) {
            $mail->line('**Admin Notes:** ' . $this->adminNotes);
        }

        return $mail
            ->line('Please re-upload a corrected POA document to proceed with vehicle submissions.')
            ->action('Re-upload POA', "{$frontendUrl}/activation/power-of-attorney")
            ->line('Contact our support team if you need assistance.');
    }

    public function toDatabase(mixed $notifiable): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'type'       => 'poa_rejected',
            'title'      => 'Power of Attorney not approved',
            'message'    => 'Your POA could not be approved — please re-upload a corrected document.',
            'action_url' => "{$frontendUrl}/activation/power-of-attorney",
            'meta'       => ['admin_notes' => $this->adminNotes],
        ];
    }
}
