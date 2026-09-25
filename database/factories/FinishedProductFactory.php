<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\FinishedProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinishedProduct>
 */
class FinishedProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            /** Once caracteres, sin espacios: la misma forma que exige el FormRequest. */
            'code' => FinishedProduct::normalizeCode('SKU-'.fake()->unique()->bothify('??####')),
            'name' => FinishedProduct::normalizeName(fake()->randomElement([
                'brócoli florete iqf', 'arveja china', 'ejote francés',
                'coliflor florete', 'zanahoria baby', 'mezcla de vegetales',
            ])),
            'presentation' => fake()->randomElement([5, 10, 20, 25]),
            'boxes_per_pallet' => fake()->randomElement([48, 60, 80, 96.5, 120]),
            'client_id' => Client::factory(),
            'registered_by' => User::factory()->state(['role' => UserRole::Administrator]),
        ];
    }

    /**
     * Indicate that the finished product has already been deleted.
     *
     * Its `code` stays taken: the soft deleted row keeps its entry in the unique index.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
