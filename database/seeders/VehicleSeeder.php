<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class VehicleSeeder extends Seeder
{
    /**
     * Seeds 15 vehicles (all status = available).
     * The first 5 will be claimed by AuctionLotSeeder for the scheduled auction.
     * The remaining 10 stay available for frontend testing.
     */
    public function run(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $vehicles = [
            // ── Lot 1-5 (will be assigned to scheduled auction) ──────────────────
            [
                'year'            => 2021,
                'make'            => 'Toyota',
                'model'           => 'Camry',
                'trim'            => 'SE',
                'color'           => 'Midnight Black',
                'mileage'         => 28400,
                'body_type'       => 'car',
                'transmission'    => 'Automatic',
                'engine'          => '2.5L 4-Cylinder',
                'fuel_type'       => 'Gasoline',
                'condition_light' => 'green',
                'condition_notes' => 'Light scratches on rear bumper.',
                'has_title'       => true,
                'title_state'     => 'MD',
            ],
            [
                'year'            => 2020,
                'make'            => 'Honda',
                'model'           => 'Civic',
                'trim'            => 'LX',
                'color'           => 'Pearl White',
                'mileage'         => 35100,
                'body_type'       => 'car',
                'transmission'    => 'CVT',
                'engine'          => '2.0L 4-Cylinder',
                'fuel_type'       => 'Gasoline',
                'condition_light' => 'green',
                'condition_notes' => null,
                'has_title'       => true,
                'title_state'     => 'VA',
            ],
            [
                'year'            => 2019,
                'make'            => 'Ford',
                'model'           => 'F-150',
                'trim'            => 'XLT',
                'color'           => 'Iconic Silver',
                'mileage'         => 62000,
                'body_type'       => 'truck',
                'transmission'    => 'Automatic',
                'engine'          => '3.5L V6 EcoBoost',
                'fuel_type'       => 'Gasoline',
                'condition_light' => 'red',
                'condition_notes' => 'Sold AS-IS. Minor dents on passenger door.',
                'has_title'       => true,
                'title_state'     => 'DC',
            ],
            [
                'year'            => 2022,
                'make'            => 'Chevrolet',
                'model'           => 'Silverado 1500',
                'trim'            => 'LT',
                'color'           => 'Summit White',
                'mileage'         => 14800,
                'body_type'       => 'truck',
                'transmission'    => 'Automatic',
                'engine'          => '5.3L V8',
                'fuel_type'       => 'Gasoline',
                'condition_light' => 'green',
                'condition_notes' => null,
                'has_title'       => true,
                'title_state'     => 'MD',
            ],
            [
                'year'            => 2020,
                'make'            => 'Jeep',
                'model'           => 'Wrangler',
                'trim'            => 'Sport',
                'color'           => 'Firecracker Red',
                'mileage'         => 41200,
                'body_type'       => 'suv',
                'transmission'    => 'Manual',
                'engine'          => '3.6L V6',
                'fuel_type'       => 'Gasoline',
                'condition_light' => 'green',
                'condition_notes' => 'Off-road tires, roof rack added.',
                'has_title'       => true,
                'title_state'     => 'VA',
            ],

            // ── Available inventory (10 vehicles for frontend testing) ────────────
            [
                'year'            => 2021,
                'make'            => 'BMW',
                'model'           => '3 Series',
                'trim'            => '330i xDrive',
                'color'           => 'Alpine White',
                'mileage'         => 22300,
                'body_type'       => 'car',
                'transmission'    => 'Automatic',
                'engine'          => '2.0L Turbocharged 4-Cylinder',
                'fuel_type'       => 'Gasoline',
                'condition_light' => 'green',
                'condition_notes' => null,
                'has_title'       => true,
                'title_state'     => 'MD',
            ],
            [
                'year'            => 2018,
                'make'            => 'Mercedes-Benz',
                'model'           => 'C300',
                'trim'            => 'AMG Line',
                'color'           => 'Obsidian Black',
                'mileage'         => 58900,
                'body_type'       => 'car',
                'transmission'    => 'Automatic',
                'engine'          => '2.0L Turbocharged 4-Cylinder',
                'fuel_type'       => 'Gasoline',
                'condition_light' => 'blue',
                'condition_notes' => 'Title attached separately — arrives within 21 days.',
                'has_title'       => false,
                'title_state'     => 'DC',
            ],
            [
                'year'            => 2022,
                'make'            => 'Tesla',
                'model'           => 'Model 3',
                'trim'            => 'Long Range',
                'color'           => 'Deep Blue Metallic',
                'mileage'         => 9800,
                'body_type'       => 'car',
                'transmission'    => 'Single-Speed Fixed',
                'engine'          => 'Dual Motor Electric',
                'fuel_type'       => 'Electric',
                'condition_light' => 'green',
                'condition_notes' => null,
                'has_title'       => true,
                'title_state'     => 'VA',
            ],
            [
                'year'            => 2019,
                'make'            => 'Dodge',
                'model'           => 'Charger',
                'trim'            => 'R/T',
                'color'           => 'TorRed',
                'mileage'         => 47500,
                'body_type'       => 'car',
                'transmission'    => 'Automatic',
                'engine'          => '5.7L HEMI V8',
                'fuel_type'       => 'Gasoline',
                'condition_light' => 'red',
                'condition_notes' => 'AS-IS. Modified exhaust.',
                'has_title'       => true,
                'title_state'     => 'MD',
            ],
            [
                'year'            => 2021,
                'make'            => 'Nissan',
                'model'           => 'Altima',
                'trim'            => 'SR',
                'color'           => 'Monarch Orange',
                'mileage'         => 19200,
                'body_type'       => 'car',
                'transmission'    => 'CVT',
                'engine'          => '2.0L Turbocharged 4-Cylinder',
                'fuel_type'       => 'Gasoline',
                'condition_light' => 'green',
                'condition_notes' => null,
                'has_title'       => true,
                'title_state'     => 'VA',
            ],
            [
                'year'            => 2020,
                'make'            => 'Hyundai',
                'model'           => 'Sonata',
                'trim'            => 'SEL Plus',
                'color'           => 'Quartz White',
                'mileage'         => 31400,
                'body_type'       => 'car',
                'transmission'    => 'Automatic',
                'engine'          => '1.6L Turbocharged 4-Cylinder',
                'fuel_type'       => 'Gasoline',
                'condition_light' => 'green',
                'condition_notes' => null,
                'has_title'       => true,
                'title_state'     => 'DC',
            ],
            [
                'year'            => 2021,
                'make'            => 'Ford',
                'model'           => 'Mustang',
                'trim'            => 'EcoBoost Premium',
                'color'           => 'Grabber Blue',
                'mileage'         => 16700,
                'body_type'       => 'car',
                'transmission'    => 'Manual',
                'engine'          => '2.3L EcoBoost 4-Cylinder',
                'fuel_type'       => 'Gasoline',
                'condition_light' => 'green',
                'condition_notes' => null,
                'has_title'       => true,
                'title_state'     => 'MD',
            ],
            [
                'year'            => 2019,
                'make'            => 'Ram',
                'model'           => '1500',
                'trim'            => 'Laramie',
                'color'           => 'Billet Silver',
                'mileage'         => 54300,
                'body_type'       => 'truck',
                'transmission'    => 'Automatic',
                'engine'          => '5.7L HEMI V8',
                'fuel_type'       => 'Gasoline',
                'condition_light' => 'green',
                'condition_notes' => null,
                'has_title'       => true,
                'title_state'     => 'VA',
            ],
            [
                'year'            => 2022,
                'make'            => 'Kia',
                'model'           => 'Telluride',
                'trim'            => 'EX',
                'color'           => 'Sangria',
                'mileage'         => 11500,
                'body_type'       => 'suv',
                'transmission'    => 'Automatic',
                'engine'          => '3.8L V6',
                'fuel_type'       => 'Gasoline',
                'condition_light' => 'green',
                'condition_notes' => null,
                'has_title'       => true,
                'title_state'     => 'MD',
            ],
            [
                'year'            => 2020,
                'make'            => 'Subaru',
                'model'           => 'Outback',
                'trim'            => 'Onyx Edition XT',
                'color'           => 'Crystal Black Silica',
                'mileage'         => 27800,
                'body_type'       => 'suv',
                'transmission'    => 'CVT',
                'engine'          => '2.4L Turbocharged Boxer',
                'fuel_type'       => 'Gasoline',
                'condition_light' => 'green',
                'condition_notes' => null,
                'has_title'       => true,
                'title_state'     => 'DC',
            ],
        ];

        foreach ($vehicles as $index => $data) {
            Vehicle::create(array_merge($data, [
                'seller_id' => $admin->id,
                'vin'       => $this->generateVin($index),
                'status'    => 'available',
            ]));
        }

        $this->command->info('Vehicles seeded: 15 vehicles (all status = available)');
    }

    /**
     * Generate a deterministic but realistic-looking 17-character VIN.
     * Real VINs exclude I, O, Q — we respect that here.
     */
    private function generateVin(int $index): string
    {
        $chars = 'ABCDEFGHJKLMNPRSTUVWXYZ0123456789';
        $prefix = ['1FA', '2T1', '3VW', '1FT', '1C4', '5YM', 'WDB', '5YJ', '2B3', '1N4',
                   'KMH', '1FA', '3C6', '5XYP', 'JF2'][min($index, 14)];
        $suffix = str_pad((string)($index + 1), 17 - strlen($prefix), '0', STR_PAD_LEFT);

        return strtoupper(substr($prefix . $suffix, 0, 17));
    }
}
