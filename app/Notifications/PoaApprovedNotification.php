<?php

namespace App\Notifications;

use App\Notifications\Concerns\HasBroadcastPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a seller when their Power of Attorney document is approved by an admin.
 */
class PoaApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable, HasBroadcastPayload;

    public function via(mixed $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
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
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'type'       => 'poa_approved',
            'title'      => 'Power of Attorney approved',
            'message'    => 'Your POA has been approved — you can now submit vehicles to auction.',
            'action_url' => "{$frontendUrl}/vehicles",
            'meta'       => [],
        ];
    }
}
