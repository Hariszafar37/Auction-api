<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when the off-session deposit charge on a won lot could not be completed
 * (declined, SCA/authentication required, or no usable card on file). Asks the
 * buyer to update their card / authenticate so the deposit can be taken before
 * the invoice due date.
 */
class DepositActionRequired extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly string $reason = 'failed',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Action needed — deposit for Invoice {$this->invoice->invoice_number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.deposit-action-required',
            with: [
                'headline' => match ($this->reason) {
                    'requires_action'   => 'Your bank needs you to confirm your deposit',
                    'no_payment_method' => 'We need a card on file to take your deposit',
                    'declined'          => 'Your deposit payment was declined',
                    default             => 'We could not process your deposit',
                },
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
