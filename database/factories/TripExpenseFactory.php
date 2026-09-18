<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\TripExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripExpense>
 */
class TripExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * An allowance is born **unconfirmed**: `received_at` and `confirmed_by` are null,
     * which is exactly what the `POST` and the assignment leave behind. It leans on
     * `Trip::factory()->assigned()` because an allowance only exists on a trip a company
     * has already taken, and `registered_by` is that trip's `assigned_by` so the row
     * matches what the service would have written.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trip_id' => Trip::factory()->assigned(),
            'amount' => fake()->randomFloat(2, 100, 2000),
            'description' => fake()->optional()->sentence(3),
            'received_at' => null,
            'confirmed_by' => null,
            'registered_by' => fn (array $attributes) => Trip::find($attributes['trip_id'])?->assigned_by,
        ];
    }

    /**
     * Indicate that the assigned pilot already confirmed receiving the money.
     *
     * Both columns are written together, never one without the other, and the pilot is
     * taken from the trip itself: only its `pilot_id` can confirm.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'received_at' => now(),
            'confirmed_by' => fn (array $attributes) => Trip::find($attributes['trip_id'])?->pilot_id,
        ]);
    }
}
