<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Carrier>
 */
class CarrierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => UserRole::Carrier]),
            'name' => fake()->company(),
            'image' => null,
            'code' => fake()->unique()->regexify('[A-Z0-9]{6}'),
            'active' => true,
        ];
    }
}
