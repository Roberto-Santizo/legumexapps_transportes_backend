<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\FinishedProduct;
use App\Models\Trip;
use App\Models\TripFinishedProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripFinishedProduct>
 */
class TripFinishedProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The finished product is created **for the trip's client**: a line whose SKU
     * belongs to another client is exactly what the service rejects.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trip_id' => Trip::factory(),
            'finished_product_id' => fn (array $attributes) => FinishedProduct::factory()->create([
                'client_id' => Trip::find($attributes['trip_id'])?->client_id,
            ])->id,
            'boxes' => fake()->numberBetween(1, 1200),
            'registered_by' => User::factory()->state(['role' => UserRole::Administrator]),
        ];
    }
}
