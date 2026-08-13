<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $latitude = fake()->randomFloat(4, 14.55, 14.70);
        $longitude = fake()->randomFloat(4, -90.60, -90.45);

        return [
            'name' => Zone::normalizeName(fake()->randomElement([
                'zona norte', 'zona sur', 'zona oriente', 'zona occidente', 'zona central',
                'ruta al atlantico', 'ruta al pacifico', 'altiplano', 'boca costa', 'peten',
            ]).' '.fake()->unique()->bothify('??##')),
            'description' => fake()->optional()->sentence(),
            'color' => mb_strtoupper(fake()->hexColor()),
            'area' => $this->areaFor([
                [$latitude, $longitude],
                [$latitude + 0.0053, $longitude + 0.0071],
                [$latitude - 0.0068, $longitude + 0.0138],
            ]),
            'status' => true,
            'registered_by' => User::factory()->state(['role' => UserRole::Administrator]),
        ];
    }

    /**
     * Indicate that the zone is published.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => true,
        ]);
    }

    /**
     * Indicate that the zone has been logically removed.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => false,
        ]);
    }

    /**
     * Give the zone a specific polygon, described as `[lat, lng]` pairs with an open ring.
     *
     * @param  array<int, array{0: float, 1: float}>  $pairs
     */
    public function withArea(array $pairs): static
    {
        return $this->state(fn (array $attributes) => [
            'area' => $this->areaFor($pairs),
        ]);
    }

    /**
     * Build the value the geography column accepts: the polygon prefixed with its SRID.
     *
     * It travels as a plain PDO binding, so the factory never builds SQL of its own.
     *
     * @param  array<int, array{0: float, 1: float}>  $pairs
     */
    private function areaFor(array $pairs): string
    {
        return 'SRID='.Zone::SRID.';'.Zone::pairsToWkt($pairs);
    }
}
