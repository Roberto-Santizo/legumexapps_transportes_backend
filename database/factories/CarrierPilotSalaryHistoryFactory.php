<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\CarrierPilot;
use App\Models\CarrierPilotSalaryHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarrierPilotSalaryHistory>
 */
class CarrierPilotSalaryHistoryFactory extends Factory
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
             * CarrierPilot no tiene factory —es una pivote y se crea con create()—,
             * así que el vínculo se arma aquí a mano.
             */
            'carrier_pilot_id' => fn (): int => CarrierPilot::create([
                'carrier_id' => Carrier::factory()->create()->id,
                'user_id' => User::factory()->create(['role' => UserRole::Pilot])->id,
            ])->id,
            /** Rangos realistas del dominio: un salario base mensual en GTQ. */
            'previous_salary' => fake()->randomFloat(2, 3000, 6000),
            'new_salary' => fake()->randomFloat(2, 3000, 6000),
            'changed_by' => User::factory()->state(['role' => UserRole::Administrator]),
        ];
    }
}
