<?php

namespace Database\Factories;

use App\Enums\FuelPriceStatus;
use App\Enums\FuelType;
use App\Enums\UserRole;
use App\Models\FuelPrice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FuelPrice>
 */
class FuelPriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fuel_type' => fake()->randomElement(FuelType::cases()),
            'price' => fake()->randomFloat(2, 20, 45),
            'status' => FuelPriceStatus::Active,
            'registered_by' => User::factory()->state(['role' => UserRole::Administrator]),
        ];
    }

    /**
     * Indicate that the price is the one currently in effect for its fuel type.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FuelPriceStatus::Active,
        ]);
    }

    /**
     * Indicate that the price has already been displaced and belongs to the history.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FuelPriceStatus::Inactive,
        ]);
    }
}
