<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceived extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly InvoicePayment $payment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment Confirmed — Invoice {$this->invoice->invoice_number}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment-received');
    }

    public function attachments(): array
    {
        $pdf  = Pdf::loadView('invoices.pdf', ['invoice' => $this->invoice]);
        $name = "invoice-{$this->invoice->invoice_number}.pdf";

        return [
            Attachment::fromData(fn () => $pdf->output(), $name)
                ->withMime('application/pdf'),
        ];
    }
}
