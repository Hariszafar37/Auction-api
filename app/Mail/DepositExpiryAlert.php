<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DepositExpiryAlert extends Mailable
{
    use SerializesModels;

    public function __construct(public readonly Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "⚠️ Deposit hold expiring — Invoice #{$this->invoice->invoice_number}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.deposit-expiry-alert');
    }

    public function attachments(): array
    {
        return [];
    }
}
