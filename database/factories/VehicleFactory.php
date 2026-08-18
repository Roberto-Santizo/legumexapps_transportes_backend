<?php

namespace Database\Factories;

use App\Enums\VehicleCondition;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Carrier;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'carrier_id' => Carrier::factory(),
            'plate' => fake()->unique()->regexify('[A-Z]{1}[0-9]{3}[A-Z]{3}'),
            'brand' => fake()->randomElement(['Freightliner', 'Kenworth', 'Volvo', 'Hino', 'Isuzu']),
            'model' => fake()->bothify('??-####'),
            'year' => fake()->numberBetween(2000, (int) date('Y')),
            'capacity' => fake()->randomFloat(2, 500, 40000),
            'type' => fake()->randomElement(VehicleType::cases()),
            'condition' => fake()->randomElement(VehicleCondition::cases()),
            'kilometers_per_gallon' => fake()->randomFloat(2, 3, 25),
            'purchase_price' => fake()->randomFloat(2, 50000, 900000),
            'monthly_insurance_cost' => fake()->randomFloat(2, 300, 4000),
            'mileage' => fake()->numberBetween(0, 500000),
            'engine_number' => strtoupper(fake()->bothify('??######')),
            'image' => null,
            'status' => VehicleStatus::Active,
        ];
    }
}
