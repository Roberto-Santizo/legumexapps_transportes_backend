<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\TripEmergencyExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripEmergencyExpense>
 */
class TripEmergencyExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * An emergency expense is only registered on a trip `in_route`, so it leans on
     * `Trip::factory()->inRoute()`, and `registered_by` is that trip's `assigned_by` so
     * the row matches what the service would have written. Born without a receipt.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trip_id' => Trip::factory()->inRoute(),
            'amount' => fake()->randomFloat(2, 50, 3000),
            'description' => fake()->sentence(3),
            'receipt' => null,
            'registered_by' => fn (array $attributes) => Trip::find($attributes['trip_id'])?->assigned_by,
        ];
    }

    /**
     * An expense that came with a receipt, with a sample key in `receipt`.
     */
    public function withReceipt(): static
    {
        return $this->state(fn (array $attributes) => [
            'receipt' => 'trip-emergency-expenses/'.fake()->uuid().'.pdf',
        ]);
    }
}
