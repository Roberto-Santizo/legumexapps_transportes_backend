<?php

namespace Database\Factories;

use App\Enums\FuelType;
use App\Enums\UserRole;
use App\Models\FreightRate;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FreightRate>
 */
class FreightRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'product_id' => Product::factory(),
            'fuel_type' => FuelType::Diesel,
            /** Rangos realistas del dominio: el diésel ronda los 30 GTQ el galón. */
            'fuel_min' => fake()->randomFloat(2, 25, 45),
            'price_per_pound' => fake()->randomFloat(6, 0.2, 0.9),
            'registered_by' => User::factory()->state(['role' => UserRole::Administrator]),
        ];
    }
}
