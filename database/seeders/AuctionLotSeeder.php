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

        $vehicles = Vehicle::where('status', 'available')
            ->orderBy('id')
            ->take(5)
            ->get();

        if ($vehicles->count() < 5) {
            $this->command->error('Not enough available vehicles to seed lots. Run VehicleSeeder first.');
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

            AuctionLot::create([
                'auction_id'               => $auction->id,
                'vehicle_id'               => $vehicle->id,
                'lot_number'               => $config['lot_number'],
                'status'                   => LotStatus::Pending,
                'starting_bid'             => $config['starting_bid'],
                'reserve_price'            => $config['reserve_price'],
                'countdown_seconds'        => $config['countdown_seconds'],
                'requires_seller_approval' => $config['requires_seller_approval'],
                'bid_count'                => 0,
            ]);

            // Mark vehicle as in_auction to match the assigned state
            $vehicle->update(['status' => 'in_auction']);
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
