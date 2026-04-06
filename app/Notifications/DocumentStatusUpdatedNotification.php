<?php

namespace App\Notifications;

use App\Models\UserDocument;
use App\Notifications\Concerns\HasBroadcastPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a user when the status of one of their uploaded documents changes.
 * Covers: approved, rejected, needs_resubmission.
 */
class DocumentStatusUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable, HasBroadcastPayload;

    public function __construct(
        private readonly UserDocument $document,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
        $docLabel    = $this->documentLabel();

        [$subject, $headline, $body] = match ($this->document->status) {
            'approved' => [
                "Document Approved: {$docLabel}",
                'Your document has been approved.',
                "Your {$docLabel} has been reviewed and approved by our team.",
            ],
            'needs_resubmission' => [
                "Action Required: Please Re-upload Your {$docLabel}",
                'Your document needs to be re-submitted.',
                "Your {$docLabel} could not be accepted and requires re-submission.",
            ],
            'rejected' => [
                "Document Not Accepted: {$docLabel}",
                'Your document was not accepted.',
                "Your {$docLabel} has been reviewed and could not be accepted.",
            ],
            default => [
                "Document Status Updated: {$docLabel}",
                'Your document status has been updated.',
                "The status of your {$docLabel} has been updated.",
            ],
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello ' . ($notifiable->first_name ?? $notifiable->name) . ',')
            ->line($headline)
            ->line($body);

        if ($this->document->admin_notes) {
            $mail->line('**Admin Notes:** ' . $this->document->admin_notes);
        }

        if ($this->document->status === 'needs_resubmission') {
            $mail->action('Re-upload Document', "{$frontendUrl}/activation/upload-id-license");
        } else {
            $mail->action('View Account', "{$frontendUrl}/dashboard");
        }

        return $mail->line('Contact our support team if you have any questions.');
    }

    public function toDatabase(mixed $notifiable): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
        $label       = $this->documentLabel();

        $title = match ($this->document->status) {
            'approved'           => "{$label} approved",
            'needs_resubmission' => "{$label} requires resubmission",
            'rejected'           => "{$label} not accepted",
            default              => "{$label} status updated",
        };

        $actionUrl = $this->document->status === 'needs_resubmission'
            ? "{$frontendUrl}/activation/upload-id-license"
            : "{$frontendUrl}/dashboard";

        return [
            'type'       => 'document_status_updated',
            'title'      => $title,
            'message'    => "Your {$label} has been updated to: {$this->document->status}.",
            'action_url' => $actionUrl,
            'meta'       => [
                'document_type' => $this->document->type,
                'status'        => $this->document->status,
                'admin_notes'   => $this->document->admin_notes,
            ],
        ];
    }

    private function documentLabel(): string
    {
        return match ($this->document->type) {
            'driver_license'      => 'Driver\'s License',
            'state_id'            => 'State ID',
            'passport'            => 'Passport',
            'dealer_license'      => 'Dealer License',
            'business_license'    => 'Business License',
            'bill_of_sale'        => 'Bill of Sale',
            'power_of_attorney'   => 'Power of Attorney',
            'insurance_cert'      => 'Insurance Certificate',
            default               => ucwords(str_replace('_', ' ', $this->document->type)),
        };
    }
}
