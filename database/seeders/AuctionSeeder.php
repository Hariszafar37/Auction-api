<?php

namespace Database\Seeders;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Database\Seeder;

class AuctionSeeder extends Seeder
{
    /**
     * Seeds two auctions:
     *  - Auction A: status = draft  (no lots — admin uses frontend to add vehicles)
     *  - Auction B: status = scheduled (5 lots added by AuctionLotSeeder)
     */
    public function run(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        // ── Auction A: Draft ──────────────────────────────────────────────────────
        Auction::create([
            'title'      => 'Spring Online Auction 2026',
            'description'=> 'Our spring event featuring a wide selection of cars, trucks, and SUVs. '
                           . 'Open to all registered buyers. Vehicles inspected and condition-graded.',
            'location'   => 'Washington DC',
            'timezone'   => 'America/New_York',
            'starts_at'  => now()->addWeeks(2)->setTime(10, 0, 0),
            'status'     => AuctionStatus::Draft,
            'created_by' => $admin->id,
            'notes'      => 'DEV: Draft auction — use admin panel to assign vehicles and publish.',
        ]);

        // ── Auction B: Scheduled (lots seeded separately) ─────────────────────────
        Auction::create([
            'title'      => 'Weekly Dealer Auction — March 22',
            'description'=> 'Weekly dealer auction open to licensed dealers and approved buyers. '
                           . 'All vehicles have been pre-inspected. Green light vehicles eligible for arbitration.',
            'location'   => 'Baltimore, MD',
            'timezone'   => 'America/New_York',
            'starts_at'  => now()->addWeek()->setTime(9, 0, 0),
            'status'     => AuctionStatus::Scheduled,
            'created_by' => $admin->id,
            'notes'      => 'DEV: Scheduled auction — 5 lots pre-assigned. Ready to go live.',
        ]);

        $this->command->info('Auctions seeded: 1 draft + 1 scheduled');
    }
}
