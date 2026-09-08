<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\TripPosition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripPosition>
 */
class TripPositionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Leans on `Trip::factory()->inRoute()` because a point only exists for a trip a
     * pilot is already driving, and takes `pilot_id` from that very trip so the row is
     * consistent with the guard the service enforces.
     *
     * There are **no states**: the model has no variant worth representing.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $trip = Trip::factory()->inRoute();

        return [
            'trip_id' => $trip,
            'pilot_id' => fn (array $attributes) => Trip::find($attributes['trip_id'])?->pilot_id,
            /** Coordenadas dentro de Guatemala: nada las valida, pero un rastro en Noruega no ayuda a leer un test. */
            'latitude' => fake()->latitude(13.7, 17.8),
            'longitude' => fake()->longitude(-92.2, -88.2),
            'recorded_at' => now(),
        ];
    }
}
