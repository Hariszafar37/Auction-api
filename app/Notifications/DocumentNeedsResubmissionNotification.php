<?php

namespace App\Notifications;

use App\Models\UserDocument;
use App\Notifications\Concerns\HasBroadcastPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an admin marks a document as needing resubmission.
 */
class DocumentNeedsResubmissionNotification extends Notification implements ShouldQueue
{
    use Queueable, HasBroadcastPayload;

    public function __construct(
        private readonly UserDocument $document,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $frontendUrl  = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
        $label        = $this->documentLabel();
        $notes        = $this->document->admin_notes ?? 'No additional notes provided.';

        return (new MailMessage)
            ->subject('Action Required: Please Resubmit Your Document')
            ->greeting('Hello ' . ($notifiable->first_name ?? $notifiable->name) . ',')
            ->line("Your **{$label}** requires resubmission.")
            ->line("Admin notes: {$notes}")
            ->line('Please upload a new version of this document to continue your application.')
            ->action('Upload Documents', "{$frontendUrl}/activation/upload-id-license")
            ->line('If you have questions, please contact our support team.');
    }

    public function toDatabase(mixed $notifiable): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'type'       => 'document_needs_resubmission',
            'title'      => 'Document resubmission required',
            'message'    => "Your {$this->documentLabel()} needs to be resubmitted.",
            'action_url' => "{$frontendUrl}/activation/upload-id-license",
            'meta'       => [
                'document_id'   => $this->document->id,
                'document_type' => $this->document->type,
                'admin_notes'   => $this->document->admin_notes,
            ],
        ];
    }

    private function documentLabel(): string
    {
        return match ($this->document->type) {
            'government_id'       => 'Government-Issued ID',
            'dealer_license'      => 'Dealer License',
            'business_license'    => 'Business License',
            'bill_of_sale'        => 'Bill of Sale',
            'proof_of_insurance'  => 'Proof of Insurance',
            default               => ucwords(str_replace('_', ' ', $this->document->type)),
        };
    }
}
