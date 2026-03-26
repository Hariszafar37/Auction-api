<?php

namespace App\Mail;

use App\Models\AuctionLot;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuctionWonMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly AuctionLot $lot,
    ) {}

    public function envelope(): Envelope
    {
        $vehicle = $this->lot->vehicle;
        $subject = "Congratulations! You won Lot {$this->lot->lot_number} – "
            . "{$vehicle->year} {$vehicle->make} {$vehicle->model}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.auction-won');
    }

    public function attachments(): array
    {
        return [];
    }
}
