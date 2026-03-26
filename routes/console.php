<?php

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
