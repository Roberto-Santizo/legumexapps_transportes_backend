<?php

use App\Models\Trip;
use App\Models\TripPosition;
use App\Models\TripTimeout;
use App\Models\User;

it('crea una parada cerrada anclada en un punto real del viaje', function () {
    $timeout = TripTimeout::factory()->create();

    expect($timeout->trip)->toBeInstanceOf(Trip::class)
        ->and($timeout->pilot)->toBeInstanceOf(User::class)
        ->and($timeout->startPosition)->toBeInstanceOf(TripPosition::class)
        ->and($timeout->endPosition)->toBeInstanceOf(TripPosition::class)
        ->and($timeout->pilot_id)->toBe($timeout->trip->pilot_id)
        ->and($timeout->startPosition->trip_id)->toBe($timeout->trip_id);
});

it('copia en la fila las coordenadas y la hora del ancla', function () {
    $timeout = TripTimeout::factory()->create();

    expect($timeout->latitude)->toBe($timeout->startPosition->latitude)
        ->and($timeout->longitude)->toBe($timeout->startPosition->longitude)
        ->and($timeout->started_at->toDateTimeString())
        ->toBe($timeout->startPosition->recorded_at->toDateTimeString());
});

it('deja la parada sin cerrar con el estado open de la factory', function () {
    $timeout = TripTimeout::factory()->open()->create();

    expect($timeout->ended_at)->toBeNull()
        ->and($timeout->end_position_id)->toBeNull()
        ->and($timeout->endPosition)->toBeNull()
        ->and($timeout->started_at)->not->toBeNull();
});

it('castea las coordenadas a string de ocho decimales y las dos fechas a datetime', function () {
    $timeout = TripTimeout::factory()->create([
        'latitude' => 14.6282,
        'longitude' => -90.5229,
    ]);

    $timeout->refresh();

    expect($timeout->latitude)->toBe('14.62820000')
        ->and($timeout->longitude)->toBe('-90.52290000')
        ->and($timeout->started_at)->toBeInstanceOf(Illuminate\Support\Carbon::class)
        ->and($timeout->ended_at)->toBeInstanceOf(Illuminate\Support\Carbon::class);
});
