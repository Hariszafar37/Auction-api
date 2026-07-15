<?php

namespace App\Notifications;

use App\Models\UserDocument;
use App\Notifications\Concerns\RendersFromTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent when an admin marks a document as needing resubmission.
 * Copy and channels come from the 'document_needs_resubmission' template.
 */
class DocumentNeedsResubmissionNotification extends Notification implements ShouldQueue
{
    use Queueable, RendersFromTemplate;

    public function __construct(
        private readonly UserDocument $document,
    ) {}

    protected function templateKey(): string
    {
        return 'document_needs_resubmission';
    }

    protected function templateVariables(mixed $notifiable): array
    {
        return [
            'document_label' => $this->documentLabel(),
            'admin_notes'    => $this->document->admin_notes,
        ];
    }

    protected function actionPayload(): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        return [
            'action_url' => "{$frontendUrl}/my/documents",
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
            'government_id'      => 'Government-Issued ID',
            'dealer_license'     => 'Dealer License',
            'business_license'   => 'Business License',
            'bill_of_sale'       => 'Bill of Sale',
            'proof_of_insurance' => 'Proof of Insurance',
            default              => ucwords(str_replace('_', ' ', $this->document->type)),
        };
    }
}
