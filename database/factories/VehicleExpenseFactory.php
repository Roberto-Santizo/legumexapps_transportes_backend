<?php

namespace Database\Factories;

use App\Enums\VehicleExpenseCategory;
use App\Enums\VehicleExpenseNature;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleExpense>
 */
class VehicleExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'category' => fake()->randomElement(VehicleExpenseCategory::cases()),
            'nature' => fake()->randomElement(VehicleExpenseNature::cases()),
            'amount' => fake()->randomFloat(2, 100, 5000),
            'expense_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'description' => fake()->sentence(),
            'registered_by' => User::factory(),
        ];
    }
}
