<?php

namespace App\Console\Commands;

use App\Enums\SellerSettlementStatus;
use App\Models\SellerSettlement;
use Illuminate\Console\Command;

/**
 * Flips sold settlements whose release date has arrived from `pending` to
 * `ready_for_release`, making the proceeds eligible for an admin to issue a
 * check. Idempotent — only touches pending sold rows past their release_date.
 */
class ReleaseSellerSettlements extends Command
{
    protected $signature = 'settlements:release';
    protected $description = 'Mark sold seller settlements as ready for release once their release date has passed';

    public function handle(): int
    {
        $count = 0;

        SellerSettlement::where('status', SellerSettlementStatus::Pending->value)
            ->where('outcome', 'sold')
            ->whereNotNull('release_date')
            ->whereDate('release_date', '<=', now()->toDateString())
            ->chunkById(200, function ($settlements) use (&$count) {
                foreach ($settlements as $settlement) {
                    $settlement->update([
                        'status'      => SellerSettlementStatus::ReadyForRelease->value,
                        'released_at' => now(),
                    ]);
                    $count++;
                }
            });

        $this->info("Marked {$count} seller settlement(s) ready for release.");

        return self::SUCCESS;
    }
}
