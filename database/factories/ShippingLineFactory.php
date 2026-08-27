<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\ShippingLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingLine>
 */
class ShippingLineFactory extends Factory
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
            'name' => ShippingLine::normalizeName(fake()->randomElement([
                'maersk line', 'mediterranean shipping company', 'cma cgm',
                'hapag lloyd', 'ocean network express', 'evergreen marine',
                'hmm', 'yang ming marine transport', 'zim integrated shipping',
                'pacific international lines',
            ]).' '.fake()->unique()->bothify('??##')),
            'registered_by' => User::factory()->state(['role' => UserRole::Administrator]),
        ];
    }

    /**
     * Indicate that the shipping line has already been deleted.
     *
     * Its `name` stays taken: a soft deleted row keeps its entry in the unique index,
     * which is exactly the point of this catalog.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
