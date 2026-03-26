<?php

namespace Database\Seeders;

use App\Models\BidIncrement;
use Illuminate\Database\Seeder;

class BidIncrementSeeder extends Seeder
{
    /**
     * Standard auto-auction bid increment table.
     *
     * Matches common industry practice for vehicle auctions.
     */
    public function run(): void
    {
        BidIncrement::truncate();

        $increments = [
            ['min_amount' => 0,      'max_amount' => 499,    'increment' => 25],
            ['min_amount' => 500,    'max_amount' => 999,    'increment' => 50],
            ['min_amount' => 1000,   'max_amount' => 4999,   'increment' => 100],
            ['min_amount' => 5000,   'max_amount' => 9999,   'increment' => 250],
            ['min_amount' => 10000,  'max_amount' => 19999,  'increment' => 500],
            ['min_amount' => 20000,  'max_amount' => null,   'increment' => 1000],
        ];

        foreach ($increments as $row) {
            BidIncrement::create($row);
        }

        $this->command->info('Bid increments seeded.');
    }
}
