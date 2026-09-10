<?php

namespace Database\Factories;

use App\Enums\FuelType;
use App\Models\Trip;
use App\Models\TripFuel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripFuel>
 */
class TripFuelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A load is born **unconfirmed**: `loaded_at` and `confirmed_by` are null, which is
     * exactly what the `POST` and the assignment leave behind. It leans on
     * `Trip::factory()->assigned()` because a load only exists on a trip a company has
     * already taken, and `registered_by` is that trip's `assigned_by` so the row matches
     * what the service would have written.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trip_id' => Trip::factory()->assigned(),
            'gallons' => fake()->randomFloat(2, 10, 120),
            'fuel_type' => fake()->randomElement(FuelType::cases()),
            'loaded_at' => null,
            'confirmed_by' => null,
            'registered_by' => fn (array $attributes) => Trip::find($attributes['trip_id'])?->assigned_by,
        ];
    }

    /**
     * Indicate that the assigned pilot already confirmed the load.
     *
     * Both columns are written together, never one without the other, and the pilot is
     * taken from the trip itself: only its `pilot_id` can confirm.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'loaded_at' => now(),
            'confirmed_by' => fn (array $attributes) => Trip::find($attributes['trip_id'])?->pilot_id,
        ]);
    }
}
