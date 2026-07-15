<?php

namespace App\Notifications;

use App\Models\UserDocument;
use App\Notifications\Concerns\RendersFromTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent to a user when the status of one of their uploaded documents changes.
 * Covers: approved, rejected, needs_resubmission — one template variant each.
 */
class DocumentStatusUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable, RendersFromTemplate;

    private const STATUSES = ['approved', 'rejected', 'needs_resubmission'];

    public function __construct(
        private readonly UserDocument $document,
    ) {}

    protected function templateKey(): string
    {
        $variant = in_array($this->document->status, self::STATUSES, true)
            ? $this->document->status
            : 'default';

        return "document_status_updated.{$variant}";
    }

    protected function templateVariables(mixed $notifiable): array
    {
        return [
            'document_label' => $this->documentLabel(),
            'status'         => $this->document->status,
            'admin_notes'    => $this->document->admin_notes,
        ];
    }

    protected function actionPayload(): array
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');

        // Anything the user must act on sends them to the document list, where the
        // reason and the re-upload control live. Everything else to the dashboard.
        $path = $this->document->status === 'needs_resubmission' ? '/my/documents' : '/dashboard';

        return [
            'action_url' => "{$frontendUrl}{$path}",
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
            'driver_license'    => "Driver's License",
            'state_id'          => 'State ID',
            'passport'          => 'Passport',
            'dealer_license'    => 'Dealer License',
            'business_license'  => 'Business License',
            'bill_of_sale'      => 'Bill of Sale',
            'power_of_attorney' => 'Power of Attorney',
            'insurance_cert'    => 'Insurance Certificate',
            default             => ucwords(str_replace('_', ' ', $this->document->type)),
        };
    }
}
