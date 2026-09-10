<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Events\Trip\TripPositionUpdated;
use App\Interfaces\TripPosition\TripPositionServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripPosition;
use App\Models\User;
use App\Services\TripPosition\TripPositionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;

/**
 * Resolve the service through the container, which checks the Provider binding too.
 */
function tripPositionService(): TripPositionServiceInterface
{
    return app(TripPositionServiceInterface::class);
}

function tripPositionServiceUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

/**
 * A trip already in route, with its assigned pilot and the owner of the company that
 * took it.
 *
 * @return array{trip: Trip, pilot: User, owner: User}
 */
function tripPositionServiceTrip(): array
{
    $trip = Trip::factory()->inRoute()->create();

    return [
        'trip' => $trip,
        'pilot' => User::findOrFail($trip->pilot_id),
        'owner' => User::findOrFail($trip->assigned_by),
    ];
}

/**
 * The payload the service expects, straight from the validated request.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tripPositionServiceData(array $overrides = []): array
{
    return array_merge([
        'latitude' => 14.628074,
        'longitude' => -90.522554,
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Binding
|--------------------------------------------------------------------------
*/

it('resuelve el contrato del dominio contra su implementación', function () {
    expect(tripPositionService())->toBeInstanceOf(TripPositionService::class);
});

/*
|--------------------------------------------------------------------------
| create(): las cuatro guardas, en su orden
|--------------------------------------------------------------------------
*/

it('guarda el punto con la hora del servidor y el piloto autenticado', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();

    $position = tripPositionService()->create($pilot, $trip->id, tripPositionServiceData());

    expect($position)->toBeInstanceOf(TripPosition::class)
        ->and($position->trip_id)->toBe($trip->id)
        ->and($position->pilot_id)->toBe($pilot->id)
        ->and($position->latitude)->toBe('14.62807400')
        ->and($position->longitude)->toBe('-90.52255400')
        ->and($position->recorded_at->timestamp)->toBe(now()->timestamp)
        ->and(TripPosition::count())->toBe(1);

    Event::assertDispatched(TripPositionUpdated::class);
});

it('descarta la hora y el autor que vengan en el payload', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();
    $otroPiloto = tripPositionServiceUser(UserRole::Pilot);

    $position = tripPositionService()->create($pilot, $trip->id, tripPositionServiceData([
        'recorded_at' => '2000-01-01 00:00:00',
        'pilot_id' => $otroPiloto->id,
    ]));

    expect($position->pilot_id)->toBe($pilot->id)
        ->and($position->recorded_at->year)->toBe(now()->year);
});

it('lanza 404 al reportar sobre un viaje que no existe', function () {
    expect(fn () => tripPositionService()->create(
        tripPositionServiceUser(UserRole::Pilot),
        99999,
        tripPositionServiceData(),
    ))->toThrow(NotFoundError::class, 'El viaje no existe');
});

it('lanza 400 al reportar sobre un viaje borrado, antes de mirar quién llama', function () {
    /** Ni suyo, ni en ruta: aun así el mensaje es el del borrado, que es la segunda guarda. */
    $trip = Trip::factory()->finished()->trashed()->create();

    expect(fn () => tripPositionService()->create(
        tripPositionServiceUser(UserRole::Pilot),
        $trip->id,
        tripPositionServiceData(),
    ))->toThrow(BadRequestError::class, 'El viaje ya fue eliminado');

    expect(TripPosition::count())->toBe(0);
});

it('lanza 403 al reportar sobre un viaje ajeno, antes de mirar el estado', function () {
    /** Está finalizado, pero primero se comprueba de quién es. */
    $trip = Trip::factory()->finished()->create();

    expect(fn () => tripPositionService()->create(
        tripPositionServiceUser(UserRole::Pilot),
        $trip->id,
        tripPositionServiceData(),
    ))->toThrow(ForbiddenError::class, 'No puedes reportar la posición de un viaje que no tienes asignado');

    expect(TripPosition::count())->toBe(0);
});

it('lanza 400 al reportar sobre un viaje que no está en ruta', function (string $estado) {
    $trip = Trip::factory()->{$estado}()->create();
    $pilot = User::findOrFail($trip->pilot_id);

    expect(fn () => tripPositionService()->create($pilot, $trip->id, tripPositionServiceData()))
        ->toThrow(BadRequestError::class, 'El viaje no está en ruta');

    expect(TripPosition::count())->toBe(0);
})->with([
    'pendiente' => 'assigned',
    'finalizado' => 'finished',
]);

/*
|--------------------------------------------------------------------------
| create(): el piso de quince segundos
|--------------------------------------------------------------------------
*/

it('devuelve el punto anterior sin escribir ni emitir antes de los quince segundos', function () {
    Event::fake([TripPositionUpdated::class]);

    /**
     * El reloj se congela porque el margen del piso es de un solo segundo: sin congelar,
     * el tiempo real que tarda la propia petición se suma a los 14 s viajados y el punto
     * acaba escribiéndose bajo carga.
     */
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();

    $primero = tripPositionService()->create($pilot, $trip->id, tripPositionServiceData());

    $this->travel(14)->seconds();

    $segundo = tripPositionService()->create($pilot, $trip->id, tripPositionServiceData([
        'latitude' => 15.111111,
        'longitude' => -91.222222,
    ]));

    expect($segundo->id)->toBe($primero->id)
        ->and($segundo->latitude)->toBe('14.62807400')
        ->and(TripPosition::count())->toBe(1);

    Event::assertDispatchedTimes(TripPositionUpdated::class, 1);
});

it('escribe y emite el segundo punto pasados los quince segundos', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();

    $primero = tripPositionService()->create($pilot, $trip->id, tripPositionServiceData());

    $this->travel(15)->seconds();

    $segundo = tripPositionService()->create($pilot, $trip->id, tripPositionServiceData([
        'latitude' => 15.111111,
        'longitude' => -91.222222,
    ]));

    expect($segundo->id)->not->toBe($primero->id)
        ->and(TripPosition::count())->toBe(2);

    Event::assertDispatchedTimes(TripPositionUpdated::class, 2);
});

it('mide el piso contra el último punto del viaje, no contra los de otro', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();

    /** Un punto recién grabado en otro viaje no puede frenar a este piloto. */
    TripPosition::factory()->create();

    tripPositionService()->create($pilot, $trip->id, tripPositionServiceData());

    expect(TripPosition::where('trip_id', $trip->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| getPositions(): ámbito, orden y paginación
|--------------------------------------------------------------------------
*/

it('lanza 403 a cualquier piloto que consulte el rastro, incluido el asignado', function (bool $asignado) {
    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();

    $user = $asignado ? $pilot : tripPositionServiceUser(UserRole::Pilot);

    expect(fn () => tripPositionService()->getPositions($user, $trip->id, []))
        ->toThrow(ForbiddenError::class, 'No tienes permisos para consultar el rastro de un viaje');
})->with([
    'el piloto asignado' => true,
    'un piloto ajeno' => false,
]);

it('lanza 404 al consultar el rastro de un viaje inexistente o borrado', function (bool $borrado) {
    $tripId = $borrado
        ? Trip::factory()->inRoute()->trashed()->create()->id
        : 99999;

    expect(fn () => tripPositionService()->getPositions(
        tripPositionServiceUser(UserRole::Administrator),
        $tripId,
        [],
    ))->toThrow(NotFoundError::class, 'El viaje no existe');
})->with([
    'inexistente' => false,
    'borrado' => true,
]);

it('lanza 403 al transportista que consulta el rastro de un viaje de otra empresa', function () {
    ['trip' => $trip] = tripPositionServiceTrip();

    expect(fn () => tripPositionService()->getPositions(
        Carrier::factory()->create()->owner,
        $trip->id,
        [],
    ))->toThrow(ForbiddenError::class, 'No puedes acceder a un viaje que no pertenece a tu empresa transportista');
});

it('devuelve el rastro a quien está dentro del ámbito de lectura', function (string $actor) {
    ['trip' => $trip, 'owner' => $owner] = tripPositionServiceTrip();

    TripPosition::factory()->create([
        'trip_id' => $trip->id,
        'pilot_id' => $trip->pilot_id,
        'recorded_at' => now(),
    ]);

    $user = match ($actor) {
        'administrator' => tripPositionServiceUser(UserRole::Administrator),
        'manager' => tripPositionServiceUser(UserRole::Manager),
        default => $owner,
    };

    expect(tripPositionService()->getPositions($user, $trip->id, []))->toHaveCount(1);
})->with(['administrator', 'manager', 'carrier']);

it('devuelve solo los puntos del viaje pedido, ordenados por hora ascendente', function () {
    ['trip' => $trip] = tripPositionServiceTrip();

    $tercero = TripPosition::factory()->create(['trip_id' => $trip->id, 'pilot_id' => $trip->pilot_id, 'recorded_at' => '2026-09-07 08:00:30']);
    $primero = TripPosition::factory()->create(['trip_id' => $trip->id, 'pilot_id' => $trip->pilot_id, 'recorded_at' => '2026-09-07 08:00:00']);
    $segundo = TripPosition::factory()->create(['trip_id' => $trip->id, 'pilot_id' => $trip->pilot_id, 'recorded_at' => '2026-09-07 08:00:15']);

    /** El rastro de otro viaje no se cuela en este. */
    TripPosition::factory()->create();

    $positions = tripPositionService()->getPositions(tripPositionServiceUser(UserRole::Administrator), $trip->id, []);

    expect($positions)->toBeInstanceOf(Collection::class)
        ->and($positions->pluck('id')->all())->toBe([$primero->id, $segundo->id, $tercero->id]);
});

it('devuelve lista vacía, y no un error, para un viaje sin puntos', function () {
    ['trip' => $trip] = tripPositionServiceTrip();

    $positions = tripPositionService()->getPositions(tripPositionServiceUser(UserRole::Administrator), $trip->id, []);

    expect($positions)->toBeInstanceOf(Collection::class)->toHaveCount(0);
});

it('no pagina sin limit ni con un limit que no es numérico', function (?string $limit) {
    ['trip' => $trip] = tripPositionServiceTrip();

    TripPosition::factory()->count(3)->create(['trip_id' => $trip->id, 'pilot_id' => $trip->pilot_id]);

    $positions = tripPositionService()->getPositions(
        tripPositionServiceUser(UserRole::Administrator),
        $trip->id,
        ['limit' => $limit],
    );

    expect($positions)->toBeInstanceOf(Collection::class)->toHaveCount(3);
})->with([
    'sin limit' => null,
    'limit no numérico' => 'abc',
]);

it('pagina con un limit numérico, acotado a [10, 100]', function (string $limit, int $perPage) {
    ['trip' => $trip] = tripPositionServiceTrip();

    TripPosition::factory()->count(12)->create(['trip_id' => $trip->id, 'pilot_id' => $trip->pilot_id]);

    $positions = tripPositionService()->getPositions(
        tripPositionServiceUser(UserRole::Administrator),
        $trip->id,
        ['limit' => $limit],
    );

    expect($positions)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($positions->perPage())->toBe($perPage)
        ->and($positions->total())->toBe(12);
})->with([
    'por debajo del piso' => ['5', 10],
    'cero' => ['0', 10],
    'dentro del rango' => ['50', 50],
    'por encima del techo' => ['500', 100],
]);
