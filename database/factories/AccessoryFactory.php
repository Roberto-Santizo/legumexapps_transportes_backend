<?php

namespace Database\Factories;

use App\Enums\AccessoryStatus;
use App\Enums\UserRole;
use App\Models\Accessory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Accessory>
 */
class AccessoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            /** El sufijo único evita que `->count(20)` choque contra el índice único. */
            'name' => Accessory::normalizeName(fake()->randomElement([
                'gato hidraulico 20 ton', 'llanta 295/80 r22.5', 'lona de carga 8x12',
                'cadena de amarre 5 m', 'extintor abc 10 lb', 'triangulo reflectivo',
                'cable pasa corriente', 'caja de herramientas', 'faja de amarre 4 ton',
                'compresor portatil 12v',
            ]).' '.fake()->unique()->bothify('??##')),
            'code' => Accessory::normalizeCode('ACC-'.fake()->unique()->numerify('#####')),
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomFloat(2, 500, 50000),
            'purchase_date' => fake()->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
            'annual_depreciation' => fake()->randomFloat(2, 0, 30),
            'status' => AccessoryStatus::Active,
            'registered_by' => User::factory()->state(['role' => UserRole::Administrator]),
        ];
    }

    /**
     * Indicate that the accessory is in service.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccessoryStatus::Active,
        ]);
    }

    /**
     * Indicate that the accessory has been logically removed.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccessoryStatus::Inactive,
        ]);
    }

    /**
     * Indicate that the accessory is out for repair.
     */
    public function underRepair(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccessoryStatus::UnderRepair,
        ]);
    }
}
