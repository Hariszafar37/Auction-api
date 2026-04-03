<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to users when their account application is rejected by an admin.
 * Covers dealer, business, individual seller, and government account types.
 */
class AccountRejectedNotification extends Notification
{
    use Queueable;

    /**
     * @param string|null $reason  Admin-provided rejection reason (may be null for some flows)
     * @param string      $context 'dealer'|'business'|'seller'|'government'
     */
    public function __construct(
        private readonly ?string $reason,
        private readonly string  $context,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        [$subject, $body, $nextStep] = match ($this->context) {
            'dealer' => [
                'Your Dealer Application Was Not Approved',
                'Thank you for applying to become a dealer on our auction platform. After reviewing your application, we are unable to approve it at this time.',
                'If you believe this was an error or have additional documentation, please contact our support team.',
            ],
            'business' => [
                'Your Business Account Application Was Not Approved',
                'Thank you for submitting your business account application. After reviewing it, we are unable to approve it at this time.',
                'Please contact our support team if you have questions or wish to reapply.',
            ],
            'seller' => [
                'Your Seller Application Was Not Approved',
                'Thank you for applying to sell on our platform. After reviewing your application, we are unable to grant seller access at this time.',
                'Your buyer account remains active. You can contact us to discuss your application or reapply in the future.',
            ],
            'government' => [
                'Your Government Account Application Was Not Approved',
                'Thank you for your interest in our auction platform. After reviewing your government account application, we are unable to approve it at this time.',
                'Please contact our support team if you have questions.',
            ],
            default => [
                'Your Application Was Not Approved',
                'After reviewing your application, we are unable to approve it at this time.',
                'Please contact our support team if you have questions.',
            ],
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello ' . ($notifiable->first_name ?? $notifiable->name) . ',')
            ->line($body);

        if ($this->reason) {
            $mail->line('**Reason:** ' . $this->reason);
        }

        return $mail
            ->line($nextStep)
            ->action('Contact Support', "{$frontendUrl}/dashboard")
            ->line('We appreciate your understanding.');
    }

    public function toDatabase(mixed $notifiable): array
    {
        $labels = [
            'dealer'     => 'Dealer application not approved',
            'business'   => 'Business account application not approved',
            'seller'     => 'Seller application not approved',
            'government' => 'Government account application not approved',
        ];

        return [
            'type'    => 'account_rejected',
            'context' => $this->context,
            'reason'  => $this->reason,
            'message' => $labels[$this->context] ?? 'Application not approved',
        ];
    }
}
