<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Product::normalizeName(fake()->randomElement([
                'brocoli', 'ejote frances', 'arveja china', 'zanahoria', 'apio',
                'coliflor', 'lechuga romana', 'tomate', 'chile pimiento', 'cebolla',
            ]).' '.fake()->unique()->bothify('??##')),
            'status' => true,
            'registered_by' => User::factory()->state(['role' => UserRole::Administrator]),
        ];
    }

    /**
     * Indicate that the product is available in the catalog.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => true,
        ]);
    }

    /**
     * Indicate that the product has been logically removed from the catalog.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => false,
        ]);
    }
}
