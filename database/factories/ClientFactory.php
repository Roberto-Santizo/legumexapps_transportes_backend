<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            /**
             * Nine characters, well inside the 15 the column allows, and without a single
             * space: the same shape the form request demands.
             */
            'code' => Client::normalizeCode('CLI-'.fake()->unique()->numerify('#####')),
            /** El sufijo único evita que `->count(20)` choque contra el índice único. */
            'name' => Client::normalizeName(fake()->randomElement([
                'agroexportadora del sur s.a.', 'comercializadora la ceiba',
                'distribuidora del pacifico', 'exportaciones altiplano',
                'frutas y vegetales de guatemala', 'grupo agricola petenero',
                'importadora costa sur', 'legumbres del valle',
                'productos frescos xelaju', 'transportes y carga izabal',
            ]).' '.fake()->unique()->bothify('??##')),
            'registered_by' => User::factory()->state(['role' => UserRole::Administrator]),
        ];
    }

    /**
     * Indicate that the client has already been deleted.
     *
     * Its `code` and its `name` stay taken: a soft deleted row keeps its entry in both
     * unique indexes, which is exactly the point of this catalog.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
