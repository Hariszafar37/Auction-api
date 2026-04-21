<?php

namespace App\Console\Commands;

use App\Mail\DepositExpiryAlert;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckDepositExpiry extends Command
{
    protected $signature = 'invoices:check-deposit-expiry';
    protected $description = 'Warn about deposit PIs approaching Stripe 7-day expiry and mark them expired';

    public function handle(): int
    {
        // Stripe PIs expire after 7 days — warn 1 day before (day 6 after creation)
        $expiring = Invoice::where('deposit_status', 'authorized')
            ->where('created_at', '<', now()->subDays(6))
            ->with(['buyer', 'lot'])
            ->get();

        foreach ($expiring as $invoice) {
            Log::warning('Deposit hold expiring in <24h', [
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'buyer_id'       => $invoice->buyer_id,
                'pi_id'          => $invoice->stripe_deposit_intent_id,
            ]);

            $invoice->update(['deposit_status' => 'expired']);

            $adminEmail = config('mail.from.address');
            if ($adminEmail) {
                Mail::to($adminEmail)->queue(new DepositExpiryAlert($invoice));
            }
        }

        $this->info("Processed {$expiring->count()} expiring deposit hold(s).");
        return self::SUCCESS;
    }
}
