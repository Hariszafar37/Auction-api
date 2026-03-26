<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        $makes = [
            'Toyota'     => ['Camry', 'Corolla', 'RAV4', 'Tacoma'],
            'Honda'      => ['Civic', 'Accord', 'CR-V', 'Pilot'],
            'Ford'       => ['F-150', 'Mustang', 'Explorer', 'Escape'],
            'Chevrolet'  => ['Silverado', 'Equinox', 'Malibu', 'Tahoe'],
            'BMW'        => ['3 Series', '5 Series', 'X3', 'X5'],
        ];

        $make  = $this->faker->randomKey($makes);
        $model = $this->faker->randomElement($makes[$make]);

        return [
            'seller_id'       => User::factory(),
            'vin'             => strtoupper($this->faker->lexify('???') . $this->faker->numerify('##########') . $this->faker->lexify('????')),
            'year'            => $this->faker->numberBetween(2015, 2024),
            'make'            => $make,
            'model'           => $model,
            'trim'            => $this->faker->optional(0.6)->randomElement(['SE', 'LE', 'XLE', 'Sport', 'Limited']),
            'color'           => $this->faker->optional(0.8)->colorName(),
            'mileage'         => $this->faker->optional(0.9)->numberBetween(0, 200000),
            'body_type'       => $this->faker->randomElement(['car', 'truck', 'suv', 'motorcycle', 'boat', 'atv', 'fleet', 'other']),
            'transmission'    => $this->faker->optional(0.7)->randomElement(['Automatic', 'Manual', 'CVT']),
            'engine'          => $this->faker->optional(0.7)->randomElement(['2.0L 4-Cylinder', '2.5L 4-Cylinder', '3.5L V6', '5.0L V8']),
            'fuel_type'       => $this->faker->optional(0.8)->randomElement(['Gasoline', 'Diesel', 'Electric', 'Hybrid']),
            'condition_light' => $this->faker->randomElement(['green', 'red', 'blue']),
            'condition_notes' => $this->faker->optional(0.5)->sentence(),
            'has_title'       => $this->faker->boolean(80),
            'title_state'     => $this->faker->optional(0.7)->stateAbbr(),
            'status'          => 'available',
        ];
    }

    public function available(): static
    {
        return $this->state(['status' => 'available']);
    }

    public function inAuction(): static
    {
        return $this->state(['status' => 'in_auction']);
    }

    public function sold(): static
    {
        return $this->state(['status' => 'sold']);
    }

    public function withdrawn(): static
    {
        return $this->state(['status' => 'withdrawn']);
    }
}
