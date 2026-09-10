<?php

use App\Http\Resources\TripTimeout\TripTimeoutResource;
use App\Models\TripTimeout;
use Illuminate\Http\Request;

/**
 * Build the resource array of an unsaved stop.
 *
 * The model is never persisted: `durationMinutes` is arithmetic over two timestamps
 * already in memory, so the calculation is testable without touching the database,
 * exactly like `currentValue` in `AccessoryResourceTest`.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tripTimeoutResourceArray(array $overrides = []): array
{
    $timeout = new TripTimeout(array_merge([
        'trip_id' => 3,
        'pilot_id' => 7,
        'start_position_id' => 340,
        'end_position_id' => 451,
        'latitude' => '14.6282',
        'longitude' => '-90.5229',
        'started_at' => '2026-09-10 08:14:00',
        'ended_at' => '2026-09-10 08:41:30',
    ], $overrides));

    $timeout->id = 12;

    return (new TripTimeoutResource($timeout))->toArray(Request::create('/'));
}

it('expone exactamente nueve claves, en el orden del contrato', function () {
    expect(array_keys(tripTimeoutResourceArray()))->toBe([
        'id',
        'latitude',
        'longitude',
        'startedAt',
        'endedAt',
        'durationMinutes',
        'pilotId',
        'startPositionId',
        'endPositionId',
    ]);
});

it('saca las dos coordenadas del ancla como string de ocho decimales', function () {
    $resource = tripTimeoutResourceArray();

    expect($resource['latitude'])->toBe('14.62820000')
        ->and($resource['longitude'])->toBe('-90.52290000');
});

it('formatea las dos horas como d-m-Y h:i:s A y nunca en ISO 8601', function () {
    $resource = tripTimeoutResourceArray();

    expect($resource['startedAt'])->toBe('10-09-2026 08:14:00 AM')
        ->and($resource['endedAt'])->toBe('10-09-2026 08:41:30 AM');
});

it('calcula la duración en minutos con dos decimales', function () {
    expect(tripTimeoutResourceArray()['durationMinutes'])->toBe(27.5);
});

it('redondea la duración a dos decimales', function () {
    $resource = tripTimeoutResourceArray(['ended_at' => '2026-09-10 08:14:05']);

    /** Cinco segundos son 0,0833… minutos. */
    expect($resource['durationMinutes'])->toBe(0.08);
});

it('deja la duración y la hora de cierre en null mientras la parada siga abierta', function () {
    $resource = tripTimeoutResourceArray([
        'ended_at' => null,
        'end_position_id' => null,
    ]);

    expect($resource['endedAt'])->toBeNull()
        ->and($resource['durationMinutes'])->toBeNull()
        ->and($resource['endPositionId'])->toBeNull()
        ->and($resource['startedAt'])->not->toBeNull();
});

it('deja endPositionId en null cuando la parada la cerró el fin del viaje', function () {
    $resource = tripTimeoutResourceArray(['end_position_id' => null]);

    expect($resource['endPositionId'])->toBeNull()
        ->and($resource['endedAt'])->toBe('10-09-2026 08:41:30 AM')
        ->and($resource['durationMinutes'])->toBe(27.5);
});

it('expone los ids del piloto y de los dos puntos tal como están en la fila', function () {
    $resource = tripTimeoutResourceArray();

    expect($resource['id'])->toBe(12)
        ->and($resource['pilotId'])->toBe(7)
        ->and($resource['startPositionId'])->toBe(340)
        ->and($resource['endPositionId'])->toBe(451);
});
