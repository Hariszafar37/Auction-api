<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Console\Command;

class MarkOverdueInvoices extends Command
{
    protected $signature = 'invoices:mark-overdue';
    protected $description = 'Mark open invoices past their due date as overdue';

    public function handle(): int
    {
        $updated = Invoice::whereIn('status', [
                InvoiceStatus::Pending->value,
                InvoiceStatus::Partial->value,
            ])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->update(['status' => InvoiceStatus::Overdue->value]);

        $this->info("Marked {$updated} invoice(s) as overdue.");
        return self::SUCCESS;
    }
}
