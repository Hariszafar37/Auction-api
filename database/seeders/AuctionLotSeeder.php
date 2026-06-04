<?php

namespace Database\Seeders;

use App\Enums\LotStatus;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class AuctionLotSeeder extends Seeder
{
    /**
     * Assigns the first 5 available vehicles to the scheduled auction as lots.
     *
     * Lot config covers a realistic spread:
     *  - Different starting bids and reserves
     *  - Mixed seller approval requirements
     *  - Mixed countdown durations
     */
    public function run(): void
    {
        $auction = Auction::where('status', 'scheduled')->firstOrFail();

        // Take the first 5 vehicles by id (no status filter) so re-runs select the
        // SAME vehicles — after the first seed these are already 'in_auction', and a
        // status filter would otherwise pick different vehicles or report a false
        // "not enough vehicles" error on every subsequent deploy.
        $vehicles = Vehicle::orderBy('id')
            ->take(5)
            ->get();

        if ($vehicles->count() < 5) {
            $this->command->error('Not enough vehicles to seed lots. Run VehicleSeeder first.');
            return;
        }

        $lotConfigs = [
            [
                'lot_number'               => 1,
                'starting_bid'             => 500,
                'reserve_price'            => 3500,
                'countdown_seconds'        => 30,
                'requires_seller_approval' => false,
            ],
            [
                'lot_number'               => 2,
                'starting_bid'             => 1000,
                'reserve_price'            => 7500,
                'countdown_seconds'        => 30,
                'requires_seller_approval' => false,
            ],
            [
                'lot_number'               => 3,
                'starting_bid'             => 2000,
                'reserve_price'            => 12000,
                'countdown_seconds'        => 60,
                'requires_seller_approval' => true,   // if_sale workflow
            ],
            [
                'lot_number'               => 4,
                'starting_bid'             => 500,
                'reserve_price'            => null,   // no reserve — sells to highest bidder
                'countdown_seconds'        => 30,
                'requires_seller_approval' => false,
            ],
            [
                'lot_number'               => 5,
                'starting_bid'             => 1500,
                'reserve_price'            => 18000,
                'countdown_seconds'        => 45,
                'requires_seller_approval' => true,   // if_sale workflow
            ],
        ];

        foreach ($lotConfigs as $i => $config) {
            $vehicle = $vehicles[$i];

            // Idempotent: keyed on (auction_id, lot_number) so re-running on every
            // deploy never duplicates. firstOrCreate (not updateOrCreate) so live
            // bidding state (status, bid_count) on a seeded lot is never wiped.
            $lot = AuctionLot::firstOrCreate(
                [
                    'auction_id' => $auction->id,
                    'lot_number' => $config['lot_number'],
                ],
                [
                    'vehicle_id'               => $vehicle->id,
                    'status'                   => LotStatus::Pending,
                    'starting_bid'             => $config['starting_bid'],
                    'reserve_price'            => $config['reserve_price'],
                    'countdown_seconds'        => $config['countdown_seconds'],
                    'requires_seller_approval' => $config['requires_seller_approval'],
                    'bid_count'                => 0,
                ]
            );

            // Only flip the vehicle into the auction when the lot was just created,
            // so we never override a live status (sold, etc.) on re-seed.
            if ($lot->wasRecentlyCreated) {
                $vehicle->update(['status' => 'in_auction']);
            }
        }

        $this->command->info("Auction lots seeded: 5 lots assigned to \"{$auction->title}\"");
        $this->command->table(
            ['Lot #', 'Vehicle', 'Starting Bid', 'Reserve', 'Seller Approval', 'Countdown'],
            $vehicles->map(function ($v, $i) use ($lotConfigs) {
                $c = $lotConfigs[$i];
                return [
                    $c['lot_number'],
                    "{$v->year} {$v->make} {$v->model}",
                    '$' . number_format($c['starting_bid']),
                    $c['reserve_price'] ? '$' . number_format($c['reserve_price']) : 'None',
                    $c['requires_seller_approval'] ? 'Yes' : 'No',
                    $c['countdown_seconds'] . 's',
                ];
            })->all()
        );
    }
}
