<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to government/charity/repo accounts when admin issues an invitation.
 */
class GovAccountInvite extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $inviteToken,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/');
        $inviteUrl   = "{$frontendUrl}/accept-invite?token={$this->inviteToken}";

        return (new MailMessage)
            ->subject('You have been invited to join the Auction Platform')
            ->greeting('Hello!')
            ->line('An administrator has created a government/organization account for you on our auction platform.')
            ->line('Click the button below to accept your invitation and set up your password.')
            ->action('Accept Invitation', $inviteUrl)
            ->line('If you were not expecting this invitation, you may safely ignore this email.');
    }
}
