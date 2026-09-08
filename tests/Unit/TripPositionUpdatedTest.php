<?php

use App\Events\Trip\TripPositionUpdated;
use App\Models\TripPosition;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * El evento se prueba sin red y sin Reverb: se construye a mano con un punto de
 * factory y se leen sus tres métodos, que es todo el contrato que ve el frontend.
 */
it('se emite en el acto, sin pasar por la cola', function () {
    $position = TripPosition::factory()->create();

    expect(new TripPositionUpdated($position, 'Carlos Ramírez'))->toBeInstanceOf(ShouldBroadcastNow::class);
});

it('emite en el canal privado del viaje', function () {
    $position = TripPosition::factory()->create();

    $channels = (new TripPositionUpdated($position, 'Carlos Ramírez'))->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0]->name)->toBe('private-trips.'.$position->trip_id);
});

it('se anuncia con el alias corto y no con el namespace de PHP', function () {
    $position = TripPosition::factory()->create();

    expect((new TripPositionUpdated($position, 'Carlos Ramírez'))->broadcastAs())->toBe('trip.position.updated');
});

it('lleva exactamente seis claves en el payload', function () {
    $position = TripPosition::factory()->create();

    $payload = (new TripPositionUpdated($position, 'Carlos Ramírez'))->broadcastWith();

    expect(array_keys($payload))->toBe([
        'tripId', 'latitude', 'longitude', 'recordedAt', 'pilotId', 'pilotName',
    ]);
});

it('lleva el viaje, las coordenadas, la fecha formateada y el piloto', function () {
    $position = TripPosition::factory()->create([
        'latitude' => '14.62807400',
        'longitude' => '-90.52255400',
        'recorded_at' => now()->setDate(2026, 9, 7)->setTime(8, 14, 3),
    ]);

    $payload = (new TripPositionUpdated($position, 'Carlos Ramírez'))->broadcastWith();

    expect($payload['tripId'])->toBe($position->trip_id)
        ->and($payload['latitude'])->toBe('14.62807400')
        ->and($payload['longitude'])->toBe('-90.52255400')
        ->and($payload['recordedAt'])->toBe('07-09-2026 08:14:03 AM')
        ->and($payload['pilotId'])->toBe($position->pilot_id)
        ->and($payload['pilotName'])->toBe('Carlos Ramírez');
});
