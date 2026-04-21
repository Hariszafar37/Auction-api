<?php

namespace App\Mail;

use App\Models\PurchaseDetail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PickupReadyNotification extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly PurchaseDetail $purchase,
    ) {}

    public function envelope(): Envelope
    {
        $vehicle = $this->purchase->lot?->vehicle;
        $label   = $vehicle ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}" : 'Your vehicle';

        return new Envelope(
            subject: "Ready for Pickup — {$label}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.pickup-ready');
    }

    public function attachments(): array
    {
        return [];
    }
}
