<?php

namespace App\Mail;

use App\Models\AuctionLot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class IfSaleNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly AuctionLot $lot,
    ) {}

    public function envelope(): Envelope
    {
        $vehicle = $this->lot->vehicle;
        $subject = "Seller Decision Required: Lot {$this->lot->lot_number} – "
            . "{$vehicle->year} {$vehicle->make} {$vehicle->model}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.if-sale-notification');
    }

    public function attachments(): array
    {
        return [];
    }
}
