<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a seller when their Power of Attorney document is rejected by an admin.
 */
class PoaRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ?string $adminNotes,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', 'database'];
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
        return [
            'type'    => 'poa_rejected',
            'notes'   => $this->adminNotes,
            'message' => 'Power of Attorney not approved — please re-upload.',
        ];
    }
}
