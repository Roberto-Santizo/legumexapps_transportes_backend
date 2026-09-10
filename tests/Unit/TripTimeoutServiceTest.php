<?php

use App\Enums\UserRole;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\TripTimeout\TripTimeoutServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripPosition;
use App\Models\TripTimeout;
use App\Models\User;
use App\Services\TripTimeout\TripTimeoutService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolve the service through the container, which checks the Provider binding too.
 */
function tripTimeoutService(): TripTimeoutServiceInterface
{
    return app(TripTimeoutServiceInterface::class);
}

function tripTimeoutServiceUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

/**
 * Write one point of the given trip's track by hand, without going through the POST.
 *
 * The detection is fed with positions built here on purpose: it must be measurable
 * without the 15 second floor of SPEC 26 getting in the way.
 */
function tripTimeoutPosition(Trip $trip, float $latitude, float $longitude, ?CarbonInterface $recordedAt = null): TripPosition
{
    return TripPosition::factory()->create([
        'trip_id' => $trip->id,
        'pilot_id' => $trip->pilot_id,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'recorded_at' => $recordedAt ?? now(),
    ]);
}

/*
|--------------------------------------------------------------------------
| Binding
|--------------------------------------------------------------------------
*/

it('resuelve el contrato del dominio contra su implementación', function () {
    expect(tripTimeoutService())->toBeInstanceOf(TripTimeoutService::class);
});

/*
|--------------------------------------------------------------------------
| Detección: apertura de la parada
|--------------------------------------------------------------------------
|
| Las coordenadas van elegidas contra el umbral de cinco metros: 0,00002° de latitud
| son ~2,2 m (por debajo) y 0,001° son ~111 m (muy por encima).
|
*/

it('no abre ninguna parada con el primer punto del viaje', function () {
    $trip = Trip::factory()->inRoute()->create();

    tripTimeoutService()->trackPosition(tripTimeoutPosition($trip, 14.6282, -90.5229));

    expect(TripTimeout::where('trip_id', $trip->id)->count())->toBe(0);
});

it('abre una parada anclada en el punto anterior cuando el camión apenas se movió', function () {
    $trip = Trip::factory()->inRoute()->create();

    $anchor = tripTimeoutPosition($trip, 14.6282, -90.5229, now()->subMinutes(2));
    $current = tripTimeoutPosition($trip, 14.62822, -90.5229, now());

    tripTimeoutService()->trackPosition($current);

    $timeout = TripTimeout::where('trip_id', $trip->id)->sole();

    expect($timeout->start_position_id)->toBe($anchor->id)
        ->and($timeout->pilot_id)->toBe($trip->pilot_id)
        ->and($timeout->latitude)->toBe($anchor->latitude)
        ->and($timeout->longitude)->toBe($anchor->longitude)
        ->and($timeout->started_at->toDateTimeString())->toBe($anchor->recorded_at->toDateTimeString())
        ->and($timeout->ended_at)->toBeNull()
        ->and($timeout->end_position_id)->toBeNull();
});

it('no abre ninguna parada cuando el camión se movió cinco metros o más', function () {
    $trip = Trip::factory()->inRoute()->create();

    tripTimeoutPosition($trip, 14.6282, -90.5229, now()->subMinutes(2));
    $current = tripTimeoutPosition($trip, 14.6292, -90.5229, now());

    tripTimeoutService()->trackPosition($current);

    expect(TripTimeout::where('trip_id', $trip->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Detección: parada ya abierta
|--------------------------------------------------------------------------
*/

it('no toca ni una columna de la parada abierta mientras el camión siga junto al ancla', function () {
    $trip = Trip::factory()->inRoute()->create();

    $anchor = tripTimeoutPosition($trip, 14.6282, -90.5229, now()->subMinutes(5));
    tripTimeoutService()->trackPosition(tripTimeoutPosition($trip, 14.62822, -90.5229, now()->subMinutes(4)));

    $timeout = TripTimeout::where('trip_id', $trip->id)->sole();
    $before = $timeout->updated_at;

    tripTimeoutService()->trackPosition(tripTimeoutPosition($trip, 14.628215, -90.52291, now()));

    $timeout->refresh();

    expect(TripTimeout::where('trip_id', $trip->id)->count())->toBe(1)
        ->and($timeout->ended_at)->toBeNull()
        ->and($timeout->end_position_id)->toBeNull()
        ->and($timeout->start_position_id)->toBe($anchor->id)
        ->and($timeout->updated_at->toDateTimeString())->toBe($before->toDateTimeString());
});

it('cierra la parada con la hora y el id del punto que se alejó del ancla', function () {
    $trip = Trip::factory()->inRoute()->create();

    tripTimeoutPosition($trip, 14.6282, -90.5229, now()->subMinutes(5));
    tripTimeoutService()->trackPosition(tripTimeoutPosition($trip, 14.62822, -90.5229, now()->subMinutes(4)));

    $movedAt = now()->subMinute();
    $moved = tripTimeoutPosition($trip, 14.6295, -90.5229, $movedAt);

    tripTimeoutService()->trackPosition($moved);

    $timeout = TripTimeout::where('trip_id', $trip->id)->sole();

    expect($timeout->end_position_id)->toBe($moved->id)
        ->and($timeout->ended_at->toDateTimeString())->toBe($moved->recorded_at->toDateTimeString());
});

it('no abre otra parada con el mismo punto que cerró la anterior', function () {
    $trip = Trip::factory()->inRoute()->create();

    tripTimeoutPosition($trip, 14.6282, -90.5229, now()->subMinutes(5));
    tripTimeoutService()->trackPosition(tripTimeoutPosition($trip, 14.62822, -90.5229, now()->subMinutes(4)));

    /** ~5,5 m del ancla —así que la cierra— y ~3,3 m del punto anterior, que abriría otra. */
    tripTimeoutService()->trackPosition(tripTimeoutPosition($trip, 14.62825, -90.5229, now()));

    expect(TripTimeout::where('trip_id', $trip->id)->count())->toBe(1)
        ->and(TripTimeout::where('trip_id', $trip->id)->whereNull('ended_at')->count())->toBe(0);
});

it('vuelve a abrir una parada cuando el camión se detiene otra vez', function () {
    $trip = Trip::factory()->inRoute()->create();

    tripTimeoutPosition($trip, 14.6282, -90.5229, now()->subMinutes(9));
    tripTimeoutService()->trackPosition(tripTimeoutPosition($trip, 14.62822, -90.5229, now()->subMinutes(8)));
    tripTimeoutService()->trackPosition(tripTimeoutPosition($trip, 14.6295, -90.5229, now()->subMinutes(7)));

    tripTimeoutService()->trackPosition(tripTimeoutPosition($trip, 14.62951, -90.5229, now()->subMinutes(6)));

    expect(TripTimeout::where('trip_id', $trip->id)->count())->toBe(2)
        ->and(TripTimeout::where('trip_id', $trip->id)->whereNull('ended_at')->count())->toBe(1);
});

it('nunca deja dos paradas abiertas a la vez en el mismo viaje', function () {
    $trip = Trip::factory()->inRoute()->create();

    $latitude = 14.6282;
    $recordedAt = now()->subMinutes(20);

    /** Alterna reposos y arranques: cada parada tiene que cerrarse antes de que nazca la siguiente. */
    foreach ([0, 0.00002, 0.001, 0.000015, 0.002, 0.00001] as $step) {
        $latitude += $step;
        $recordedAt = $recordedAt->copy()->addMinute();

        tripTimeoutService()->trackPosition(tripTimeoutPosition($trip, $latitude, -90.5229, $recordedAt));
    }

    expect(TripTimeout::where('trip_id', $trip->id)->whereNull('ended_at')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Lectura
|--------------------------------------------------------------------------
*/

it('devuelve las paradas del viaje ordenadas por su hora de inicio', function () {
    $trip = Trip::factory()->inRoute()->create();

    $second = TripTimeout::factory()->create(['trip_id' => $trip->id, 'started_at' => now()->subHour()]);
    $first = TripTimeout::factory()->create(['trip_id' => $trip->id, 'started_at' => now()->subHours(3)]);

    $timeouts = tripTimeoutService()->getTimeouts(
        tripTimeoutServiceUser(UserRole::Administrator),
        $trip->id,
        [],
    );

    expect($timeouts)->toBeInstanceOf(Collection::class)
        ->and($timeouts->pluck('id')->all())->toBe([$first->id, $second->id]);
});

it('devuelve una colección vacía para un viaje sin paradas', function () {
    $trip = Trip::factory()->inRoute()->create();

    $timeouts = tripTimeoutService()->getTimeouts(
        tripTimeoutServiceUser(UserRole::Manager),
        $trip->id,
        [],
    );

    expect($timeouts)->toBeInstanceOf(Collection::class)
        ->and($timeouts)->toHaveCount(0);
});

it('pagina cuando llega limit y acota el tamaño de página a diez', function () {
    $trip = Trip::factory()->inRoute()->create();

    TripTimeout::factory()->count(12)->create(['trip_id' => $trip->id]);

    $timeouts = tripTimeoutService()->getTimeouts(
        tripTimeoutServiceUser(UserRole::Administrator),
        $trip->id,
        ['limit' => '3'],
    );

    expect($timeouts)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($timeouts->perPage())->toBe(10)
        ->and($timeouts->total())->toBe(12);
});

it('ignora un limit no numérico y devuelve la colección completa', function () {
    $trip = Trip::factory()->inRoute()->create();

    TripTimeout::factory()->count(2)->create(['trip_id' => $trip->id]);

    $timeouts = tripTimeoutService()->getTimeouts(
        tripTimeoutServiceUser(UserRole::Administrator),
        $trip->id,
        ['limit' => 'abc'],
    );

    expect($timeouts)->toBeInstanceOf(Collection::class)
        ->and($timeouts)->toHaveCount(2);
});

it('rechaza a cualquier piloto, incluido el asignado al viaje', function () {
    $trip = Trip::factory()->inRoute()->create();

    tripTimeoutService()->getTimeouts(User::findOrFail($trip->pilot_id), $trip->id, []);
})->throws(ForbiddenError::class, 'No tienes permisos para consultar las paradas de un viaje');

it('delega en el dominio de viajes el 404 de un viaje inexistente', function () {
    tripTimeoutService()->getTimeouts(tripTimeoutServiceUser(UserRole::Administrator), 999999, []);
})->throws(NotFoundError::class);

it('delega en el dominio de viajes el 404 de un viaje borrado', function () {
    $trip = Trip::factory()->inRoute()->create();
    $trip->delete();

    tripTimeoutService()->getTimeouts(tripTimeoutServiceUser(UserRole::Administrator), $trip->id, []);
})->throws(NotFoundError::class);

it('acota el tamaño de página a cien cuando el limit se pasa de rosca', function () {
    $trip = Trip::factory()->inRoute()->create();

    TripTimeout::factory()->count(3)->create(['trip_id' => $trip->id]);

    $timeouts = tripTimeoutService()->getTimeouts(
        tripTimeoutServiceUser(UserRole::Administrator),
        $trip->id,
        ['limit' => '500'],
    );

    expect($timeouts)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($timeouts->perPage())->toBe(100)
        ->and($timeouts->total())->toBe(3);
});

it('rechaza con ForbiddenError al transportista de una empresa que no asignó el viaje', function () {
    $trip = Trip::factory()->inRoute()->create();

    tripTimeoutService()->getTimeouts(Carrier::factory()->create()->owner, $trip->id, []);
})->throws(ForbiddenError::class, 'No puedes acceder a un viaje que no pertenece a tu empresa transportista');

/*
|--------------------------------------------------------------------------
| Aislamiento entre viajes
|--------------------------------------------------------------------------
*/

it('no toca las paradas de otro viaje al procesar un punto', function () {
    $otherTrip = Trip::factory()->inRoute()->create();
    $otherTimeout = TripTimeout::factory()->open()->create(['trip_id' => $otherTrip->id]);

    $trip = Trip::factory()->inRoute()->create();

    tripTimeoutPosition($trip, 14.6282, -90.5229, now()->subMinutes(5));

    /** Abre la parada de su viaje: la del otro sigue abierta y sin tocar. */
    tripTimeoutService()->trackPosition(tripTimeoutPosition($trip, 14.62822, -90.5229, now()->subMinutes(4)));

    expect(TripTimeout::where('trip_id', $trip->id)->count())->toBe(1)
        ->and($otherTimeout->refresh()->ended_at)->toBeNull()
        ->and($otherTimeout->end_position_id)->toBeNull();

    /** Y el punto que cierra la suya tampoco cierra la ajena, aunque esté a cien metros de todo. */
    tripTimeoutService()->trackPosition(tripTimeoutPosition($trip, 14.6295, -90.5229, now()));

    expect(TripTimeout::where('trip_id', $trip->id)->whereNull('ended_at')->count())->toBe(0)
        ->and($otherTimeout->refresh()->ended_at)->toBeNull()
        ->and(TripTimeout::where('trip_id', $otherTrip->id)->count())->toBe(1);
});
