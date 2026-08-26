<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\DeparturePoint;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeparturePoint>
 */
class DeparturePointFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => DeparturePoint::normalizeName(fake()->randomElement([
                'bodega central escuintla', 'planta de empaque chimaltenango', 'finca la esperanza',
                'centro de acopio patzun', 'terminal de carga puerto quetzal', 'bodega zona 12',
                'planta san juan sacatepequez', 'centro de acopio tecpan', 'finca el rosario',
                'bodega de frio villa nueva',
            ]).' '.fake()->unique()->bothify('??##')),
            'description' => fake()->optional()->sentence(),
            /** Los ids de Google empiezan por `ChIJ` y son opacos: aquí solo importa que sean únicos. */
            'google_place_id' => 'ChIJ'.fake()->unique()->regexify('[A-Za-z0-9_-]{23}'),
            /** Dentro de Guatemala: la latitud cabe en decimal(10,8) y la longitud en decimal(11,8). */
            'latitude' => fake()->randomFloat(8, 13.90, 17.75),
            'longitude' => fake()->randomFloat(8, -92.10, -88.30),
            'status' => true,
            'registered_by' => User::factory()->state(['role' => UserRole::Administrator]),
        ];
    }

    /**
     * Indicate that the departure point is published.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => true,
        ]);
    }

    /**
     * Indicate that the departure point has been logically removed.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => false,
        ]);
    }
}
