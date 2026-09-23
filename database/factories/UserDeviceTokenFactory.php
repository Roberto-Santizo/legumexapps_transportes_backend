<?php

namespace Database\Factories;

use App\Enums\DevicePlatform;
use App\Models\User;
use App\Models\UserDeviceToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserDeviceToken>
 */
class UserDeviceTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The token mimics an FCM one: about 160 characters from `[A-Za-z0-9:_-]`, with
     * no spaces, which is what the FormRequest accepts.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => Str::random(22).':APA91b'.Str::random(132),
            'platform' => fake()->randomElement(DevicePlatform::cases()),
            'last_seen_at' => now(),
        ];
    }
}
