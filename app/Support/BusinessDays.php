<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Business-day arithmetic for payment deadlines. "Business days" excludes
 * Saturdays and Sundays. Public holidays are intentionally not modelled yet —
 * if the client later requires holiday-aware deadlines we can inject a calendar.
 */
class BusinessDays
{
    /**
     * Return the datetime that is $days full business days after $from.
     * The starting day itself is never counted; the result lands at the end
     * of the target business day (23:59:59) so a buyer has the whole day.
     */
    public static function add(CarbonInterface $from, int $days): CarbonInterface
    {
        $date = $from->copy();
        $added = 0;

        while ($added < max(0, $days)) {
            $date = $date->addDay();
            if (! $date->isWeekend()) {
                $added++;
            }
        }

        return $date->endOfDay();
    }
}
