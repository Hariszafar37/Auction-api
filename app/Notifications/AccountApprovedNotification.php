<?php

namespace App\Notifications;

use App\Notifications\Concerns\HasBroadcastPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to users when their account application is approved by an admin.
 * Covers dealer, business, individual seller, and government account types.
 */
class AccountApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable, HasBroadcastPayload;

    /** @param string $context 'dealer'|'business'|'seller'|'government' */
    public function __construct(
        private readonly string $context,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        [$subject, $headline, $body, $actionLabel, $actionUrl] = match ($this->context) {
            'dealer' => [
                'Your Dealer Account Has Been Approved',
                'Your dealer account is now active.',
                'Congratulations! Your dealer application has been reviewed and approved. You can now submit vehicles to auction and access all dealer features.',
                'Go to Dashboard',
                "{$frontendUrl}/dashboard",
            ],
            'business' => [
                'Your Business Account Has Been Approved',
                'Your business account is now active.',
                'Congratulations! Your business account application has been reviewed and approved. Your account is now fully active.',
                'Go to Dashboard',
                "{$frontendUrl}/dashboard",
            ],
            'seller' => [
                'Your Seller Application Has Been Approved',
                'You are now approved to sell on Colonial Auto Auction.',
                'Great news! Your individual seller application has been approved. You can now list vehicles for auction.',
                'Add a Vehicle',
                "{$frontendUrl}/vehicles/add",
            ],
            'government' => [
                'Your Government Account Has Been Approved',
                'Your government account is now active.',
                'Your government/organization account has been reviewed and approved. You now have full access to Colonial Auto Auction.',
                'Go to Dashboard',
                "{$frontendUrl}/dashboard",
            ],
            default => [
                'Your Account Has Been Approved',
                'Your account is now active.',
                'Your account has been reviewed and approved. You can now access all features.',
                'Go to Dashboard',
                "{$frontendUrl}/dashboard",
            ],
        };

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hello ' . ($notifiable->first_name ?? $notifiable->name) . '!')
            ->line($headline)
            ->line($body)
            ->action($actionLabel, $actionUrl)
            ->line('If you have any questions, please contact our support team.');
    }

    public function toDatabase(mixed $notifiable): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        [$title, $message, $actionUrl] = match ($this->context) {
            'dealer'     => ['Dealer account approved', 'Your dealer account is now active. You can submit vehicles to auction.', "{$frontendUrl}/dashboard"],
            'business'   => ['Business account approved', 'Your business account is now active.', "{$frontendUrl}/dashboard"],
            'seller'     => ['Seller application approved', 'You are now approved to sell on Colonial Auto Auction.', "{$frontendUrl}/vehicles/add"],
            'government' => ['Government account approved', 'Your government account is now active.', "{$frontendUrl}/dashboard"],
            default      => ['Account approved', 'Your account has been approved.', "{$frontendUrl}/dashboard"],
        };

        return [
            'type'       => 'account_approved',
            'title'      => $title,
            'message'    => $message,
            'action_url' => $actionUrl,
            'meta'       => ['context' => $this->context],
        ];
    }
}
