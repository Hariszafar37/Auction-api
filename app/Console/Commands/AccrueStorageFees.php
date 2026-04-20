<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Payment\FeeCalculationService;
use Illuminate\Console\Command;

class AccrueStorageFees extends Command
{
    protected $signature = 'invoices:accrue-storage';
    protected $description = 'Increment storage days and recalculate storage fees for all open invoices';

    public function __construct(private readonly FeeCalculationService $fees)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = now()->startOfDay();

        $invoices = Invoice::whereIn('status', [
                InvoiceStatus::Pending->value,
                InvoiceStatus::Partial->value,
                InvoiceStatus::Overdue->value,
            ])
            ->where(function ($q) use ($today) {
                $q->whereNull('storage_last_accrued_at')
                  ->orWhereDate('storage_last_accrued_at', '<', $today);
            })
            ->with(['lot.auction'])
            ->get();

        $count = 0;

        foreach ($invoices as $invoice) {
            $location  = $invoice->lot?->auction?->location;
            $newDays   = $invoice->storage_days + 1;

            $breakdown = $this->fees->calculate(
                $invoice->sale_price,
                $location,
                $newDays
            );

            $newStorage = (float) $breakdown['storage_fee_amount'];
            $newTotal   = (float) $breakdown['total_amount'];
            $newBalance = max(0, $newTotal - (float) $invoice->amount_paid);

            $invoice->update([
                'storage_days'            => $newDays,
                'storage_fee_amount'      => $newStorage,
                'storage_fee_total'       => $newStorage,
                'total_amount'            => $newTotal,
                'balance_due'             => $newBalance,
                'storage_last_accrued_at' => now(),
            ]);

            $count++;
        }

        $this->info("Accrued storage fees for {$count} invoice(s).");
        return self::SUCCESS;
    }
}
