<?php

namespace App\Mail;

use App\Models\TransportRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TransportQuoteReceived extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly TransportRequest $transportRequest,
    ) {}

    public function envelope(): Envelope
    {
        $vehicle = $this->transportRequest->lot?->vehicle;
        $label   = $vehicle ? "{$vehicle->year} {$vehicle->make} {$vehicle->model}" : 'Your vehicle';

        return new Envelope(
            subject: "Transport Quote Received — {$label}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.transport-quote-received');
    }

    public function attachments(): array
    {
        return [];
    }
}
