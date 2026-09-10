<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\TripPosition;
use App\Models\TripTimeout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripTimeout>
 */
class TripTimeoutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Builds a **closed** stop of half an hour: the anchor and the closing point are two
     * real `trip_positions` rows of the same trip, because both foreign keys point there
     * and a stop whose anchor does not exist is not a stop the API could ever produce.
     *
     * Coordinates and `started_at` are copied from the anchor, exactly as the detection
     * does, so a row built here is indistinguishable from one written by the service.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $trip = Trip::factory()->inRoute();

        return [
            'trip_id' => $trip,
            'pilot_id' => fn (array $attributes) => Trip::find($attributes['trip_id'])?->pilot_id,
            'start_position_id' => fn (array $attributes) => TripPosition::factory()->create([
                'trip_id' => $attributes['trip_id'],
                'pilot_id' => $attributes['pilot_id'],
                'recorded_at' => now()->subMinutes(30),
            ])->id,
            'end_position_id' => fn (array $attributes) => TripPosition::factory()->create([
                'trip_id' => $attributes['trip_id'],
                'pilot_id' => $attributes['pilot_id'],
                'recorded_at' => now(),
            ])->id,
            'latitude' => fn (array $attributes) => TripPosition::find($attributes['start_position_id'])?->latitude,
            'longitude' => fn (array $attributes) => TripPosition::find($attributes['start_position_id'])?->longitude,
            'started_at' => fn (array $attributes) => TripPosition::find($attributes['start_position_id'])?->recorded_at,
            'ended_at' => fn (array $attributes) => TripPosition::find($attributes['end_position_id'])?->recorded_at,
        ];
    }

    /**
     * Indicate that the truck is still stopped: nothing has closed the row yet.
     *
     * The two columns go together —an open stop has neither a closing point nor a
     * closing time—, which is why the state sets both and not just one.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'end_position_id' => null,
            'ended_at' => null,
        ]);
    }
}
