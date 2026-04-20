<?php

use App\Console\Commands\AccrueStorageFees;
use App\Console\Commands\CheckDepositExpiry;
use App\Console\Commands\MarkOverdueInvoices;
use App\Jobs\Auction\ProcessIfSaleExpiry;
use App\Jobs\Auction\ProcessLotClose;
use App\Jobs\Auction\StartScheduledAuctions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Auction Engine — Scheduled Jobs
|--------------------------------------------------------------------------
*/

// Auto-start auctions whose starts_at has passed
Schedule::job(new StartScheduledAuctions)->everyMinute();

// Close lots whose countdown has expired
Schedule::job(new ProcessLotClose)->everyMinute();

// Auto-reject if_sale lots past their seller decision deadline
Schedule::job(new ProcessIfSaleExpiry)->everyMinute();

/*
|--------------------------------------------------------------------------
| Invoice — Scheduled Commands
|--------------------------------------------------------------------------
*/

// Mark open invoices past their due date as overdue
Schedule::command(MarkOverdueInvoices::class)->dailyAt('00:10');

// Accrue daily storage fees on open invoices
Schedule::command(AccrueStorageFees::class)->dailyAt('00:05');

// Warn about deposit PIs approaching Stripe 7-day expiry
Schedule::command(CheckDepositExpiry::class)->dailyAt('08:00');
