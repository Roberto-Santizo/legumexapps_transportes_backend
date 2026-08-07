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
            'name' => mb_strtoupper(fake()->unique()->words(2, true)),
            'status' => true,
            'registered_by' => User::factory()->state(['role' => UserRole::Administrator]),
        ];
    }
}
