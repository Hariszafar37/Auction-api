<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Mail\InvoiceOverdue;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MarkOverdueInvoices extends Command
{
    protected $signature = 'invoices:mark-overdue';
    protected $description = 'Mark open invoices past their due date as overdue and queue notification emails';

    public function handle(): int
    {
        $count = 0;

        // FIX 4: process one-by-one to queue emails — overdue_notified_at guards against re-sending
        Invoice::whereIn('status', [
                InvoiceStatus::Pending->value,
                InvoiceStatus::Partial->value,
            ])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNull('overdue_notified_at')
            ->with(['buyer', 'vehicle', 'lot'])
            ->chunkById(100, function ($invoices) use (&$count) {
                foreach ($invoices as $invoice) {
                    $invoice->update([
                        'status'              => InvoiceStatus::Overdue->value,
                        'overdue_notified_at' => now(),
                    ]);

                    if ($invoice->buyer?->email) {
                        Mail::to($invoice->buyer->email)->queue(new InvoiceOverdue($invoice));
                    }

                    $count++;
                }
            });

        $this->info("Marked {$count} invoice(s) as overdue and queued notification emails.");
        return self::SUCCESS;
    }
}
