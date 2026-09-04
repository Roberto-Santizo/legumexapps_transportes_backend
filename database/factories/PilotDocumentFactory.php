<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\PilotDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PilotDocument>
 */
class PilotDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => UserRole::Pilot]),
            /**
             * Keys only: the factory never touches the disk. A test that needs the object
             * to exist uploads it through the global `fakeDefaultDisk()` of tests/Pest.php.
             */
            'dpi_image' => 'pilot-documents/'.fake()->uuid().'.jpg',
            'license_image' => 'pilot-documents/'.fake()->uuid().'.png',
        ];
    }
}
