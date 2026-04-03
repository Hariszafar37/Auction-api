<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a seller when their Power of Attorney document is approved by an admin.
 */
class PoaApprovedNotification extends Notification
{
    use Queueable;

    public function via(mixed $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return (new MailMessage)
            ->subject('Your Power of Attorney Has Been Approved')
            ->greeting('Hello ' . ($notifiable->first_name ?? $notifiable->name) . '!')
            ->line('Your Power of Attorney (POA) document has been reviewed and approved.')
            ->line('You can now submit vehicles to auction. Head to your vehicle inventory to get started.')
            ->action('Go to My Vehicles', "{$frontendUrl}/vehicles")
            ->line('If you have any questions, please contact our support team.');
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            'type'    => 'poa_approved',
            'message' => 'Power of Attorney approved — you can now submit vehicles to auction.',
        ];
    }
}
