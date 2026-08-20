<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Accessory;
use App\Models\AccessoryCharacteristic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessoryCharacteristic>
 */
class AccessoryCharacteristicFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'accessory_id' => Accessory::factory(),
            /** El sufijo único evita que `->count(20)` choque contra el único `(accessory_id, name)`. */
            'name' => AccessoryCharacteristic::normalizeName(fake()->randomElement([
                'placa', 'tipo de combustible', 'marca', 'modelo', 'color',
                'numero de serie', 'medida', 'capacidad', 'material', 'voltaje',
            ]).' '.fake()->unique()->bothify('??##')),
            /** Corto y sin normalizar la caja: el valor sale tal como lo teclea el usuario. */
            'value' => AccessoryCharacteristic::normalizeValue(fake()->randomElement([
                'P-123ABC', 'Diésel', 'Michelin', '295/80 R22.5', 'Rojo',
                'SN-8842190', '8x12 m', '20 ton', 'Acero inoxidable', '12V',
            ])),
            'registered_by' => User::factory()->state(['role' => UserRole::Administrator]),
        ];
    }
}
