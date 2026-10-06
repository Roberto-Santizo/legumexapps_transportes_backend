<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Events\Trip\TripPositionUpdated;
use App\Interfaces\TripPosition\TripPositionServiceInterface;
use App\Interfaces\TripTimeout\TripTimeoutServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripPosition;
use App\Models\User;
use App\Services\TripPosition\TripPositionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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
| create(): el piso de cinco segundos
|--------------------------------------------------------------------------
*/

it('devuelve el punto anterior sin escribir ni emitir antes de los cinco segundos', function () {
    Event::fake([TripPositionUpdated::class]);

    /**
     * El reloj se congela porque el margen del piso es de un solo segundo: sin congelar,
     * el tiempo real que tarda la propia petición se suma a los 14 s viajados y el punto
     * acaba escribiéndose bajo carga.
     */
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();

    $primero = tripPositionService()->create($pilot, $trip->id, tripPositionServiceData());

    $this->travel(4)->seconds();

    $segundo = tripPositionService()->create($pilot, $trip->id, tripPositionServiceData([
        'latitude' => 15.111111,
        'longitude' => -91.222222,
    ]));

    expect($segundo->id)->toBe($primero->id)
        ->and($segundo->latitude)->toBe('14.62807400')
        ->and(TripPosition::count())->toBe(1);

    Event::assertDispatchedTimes(TripPositionUpdated::class, 1);
});

it('escribe y emite el segundo punto pasados los cinco segundos', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();

    $primero = tripPositionService()->create($pilot, $trip->id, tripPositionServiceData());

    $this->travel(5)->seconds();

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

/*
|--------------------------------------------------------------------------
| storePositions(): el lote
|--------------------------------------------------------------------------
*/

/**
 * A batch payload whose points sit the given seconds after a base time, in ISO 8601 with
 * the Guatemala offset, the way a device would send them.
 *
 * @param  list<int>  $seconds
 * @return array{positions: list<array{latitude: float, longitude: float, recordedAt: string}>}
 */
function tripPositionServiceBatch(array $seconds, ?CarbonImmutable $base = null): array
{
    $base ??= CarbonImmutable::now()->subHour();

    return ['positions' => array_map(fn (int $offset): array => [
        'latitude' => 14.628074 + $offset / 10000,
        'longitude' => -90.522554,
        'recordedAt' => $base->addSeconds($offset)->setTimezone('America/Guatemala')->toIso8601String(),
    ], $seconds)];
}

it('guarda el lote con la hora del dispositivo convertida a la zona de la app', function () {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();

    $summary = tripPositionService()->storePositions($pilot, $trip->id, ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => now()->subHour()->setTimezone('America/Guatemala')->format('Y-m-d\TH:i:s.vP')],
    ]]);

    $position = TripPosition::sole();

    expect($summary['received'])->toBe(1)
        ->and($summary['saved'])->toBe(1)
        ->and($summary['discarded'])->toBe(0)
        ->and($summary['lastPosition']->id)->toBe($position->id)
        ->and($position->pilot_id)->toBe($pilot->id)
        ->and($position->latitude)->toBe('14.62807400')
        ->and($position->recorded_at->timestamp)->toBe(now()->subHour()->timestamp)
        ->and($position->recorded_at->format('H:i:s'))->toBe(now()->subHour()->setTimezone(config('app.timezone'))->format('H:i:s'));
});

it('ignora un pilotId que venga dentro del punto', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();
    $otroPiloto = tripPositionServiceUser(UserRole::Pilot);

    $data = tripPositionServiceBatch([0]);
    $data['positions'][0]['pilotId'] = $otroPiloto->id;

    tripPositionService()->storePositions($pilot, $trip->id, $data);

    expect(TripPosition::sole()->pilot_id)->toBe($pilot->id);
});

it('aplica al lote entero las cuatro guardas de un punto, en su orden', function (string $caso, string $error, string $mensaje) {
    $pilot = tripPositionServiceUser(UserRole::Pilot);

    if ($caso === 'no en ruta') {
        $trip = Trip::factory()->finished()->create();
        $pilot = User::findOrFail($trip->pilot_id);
    }

    $tripId = match ($caso) {
        'inexistente' => 99999,
        /** Ni suyo ni en ruta: gana el borrado, que es la segunda guarda. */
        'borrado' => Trip::factory()->finished()->trashed()->create()->id,
        /** Finalizado, pero primero se mira de quién es. */
        'ajeno' => Trip::factory()->finished()->create()->id,
        'no en ruta' => $trip->id,
    };

    expect(fn () => tripPositionService()->storePositions($pilot, $tripId, tripPositionServiceBatch([0, 6])))
        ->toThrow($error, $mensaje);

    expect(TripPosition::count())->toBe(0);
})->with([
    'inexistente' => ['inexistente', NotFoundError::class, 'El viaje no existe'],
    'borrado' => ['borrado', BadRequestError::class, 'El viaje ya fue eliminado'],
    'ajeno' => ['ajeno', ForbiddenError::class, 'No puedes reportar la posición de un viaje que no tienes asignado'],
    'no en ruta' => ['no en ruta', BadRequestError::class, 'El viaje no está en ruta'],
]);

it('rechaza el lote entero si un punto es anterior al inicio del viaje', function () {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();

    /** El viaje arrancó hace 4 h: el último punto cae una hora antes. */
    $data = tripPositionServiceBatch([0, 6]);
    $data['positions'][] = [
        'latitude' => 14.6,
        'longitude' => -90.5,
        'recordedAt' => now()->subHours(5)->toIso8601String(),
    ];

    expect(fn () => tripPositionService()->storePositions($pilot, $trip->id, $data))
        ->toThrow(BadRequestError::class, 'La hora de un punto es anterior al inicio del viaje');

    expect(TripPosition::count())->toBe(0);
    Event::assertNotDispatched(TripPositionUpdated::class);
});

it('guarda un lote desordenado en orden de hora', function () {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();
    $base = CarbonImmutable::now()->subHour();

    tripPositionService()->storePositions($pilot, $trip->id, tripPositionServiceBatch([12, 0, 6], $base));

    expect(TripPosition::query()->orderBy('id')->get()->map(fn (TripPosition $p): int => $p->recorded_at->timestamp - $base->timestamp)->all())
        ->toBe([0, 6, 12]);
});

it('aplica el piso de cinco segundos entre los puntos conservados', function () {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();
    $base = CarbonImmutable::now()->subHour();

    $summary = tripPositionService()->storePositions($pilot, $trip->id, tripPositionServiceBatch([0, 3, 6, 12], $base));

    expect($summary['received'])->toBe(4)
        ->and($summary['saved'])->toBe(3)
        ->and($summary['discarded'])->toBe(1)
        ->and(TripPosition::query()->orderBy('id')->get()->map(fn (TripPosition $p): int => $p->recorded_at->timestamp - $base->timestamp)->all())
        ->toBe([0, 6, 12]);
});

it('mide el piso contra el último punto ya guardado', function () {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();
    $base = CarbonImmutable::now()->subHour();

    TripPosition::factory()->create(['trip_id' => $trip->id, 'pilot_id' => $pilot->id, 'recorded_at' => $base]);

    $summary = tripPositionService()->storePositions($pilot, $trip->id, tripPositionServiceBatch([3, 8], $base));

    expect($summary['saved'])->toBe(1)
        ->and($summary['discarded'])->toBe(1)
        ->and($summary['lastPosition']->recorded_at->timestamp)->toBe($base->addSeconds(8)->timestamp)
        ->and(TripPosition::count())->toBe(2);
});

it('descarta los puntos anteriores o iguales al último guardado', function () {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();
    $base = CarbonImmutable::now()->subHour();

    TripPosition::factory()->create(['trip_id' => $trip->id, 'pilot_id' => $pilot->id, 'recorded_at' => $base->addSeconds(30)]);

    $summary = tripPositionService()->storePositions($pilot, $trip->id, tripPositionServiceBatch([0, 10, 30, 40], $base));

    expect($summary['received'])->toBe(4)
        ->and($summary['saved'])->toBe(1)
        ->and($summary['discarded'])->toBe(3)
        ->and($summary['lastPosition']->recorded_at->timestamp)->toBe($base->addSeconds(40)->timestamp)
        ->and(TripPosition::count())->toBe(2);
});

it('es idempotente ante el reintento del mismo lote y devuelve el último guardado', function () {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();
    $data = tripPositionServiceBatch([0, 6, 12]);

    $primero = tripPositionService()->storePositions($pilot, $trip->id, $data);
    $segundo = tripPositionService()->storePositions($pilot, $trip->id, $data);

    expect($segundo['received'])->toBe(3)
        ->and($segundo['saved'])->toBe(0)
        ->and($segundo['discarded'])->toBe(3)
        ->and($segundo['lastPosition']->id)->toBe($primero['lastPosition']->id)
        ->and(TripPosition::count())->toBe(3);

    /** Solo el primer lote emitió: el reintento no escribió nada. */
    Event::assertDispatchedTimes(TripPositionUpdated::class, 1);
});

it('emite un solo evento por lote, con el último punto escrito', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();

    $summary = tripPositionService()->storePositions($pilot, $trip->id, tripPositionServiceBatch(range(0, 80, 5)));

    expect($summary['saved'])->toBe(17);

    Event::assertDispatchedTimes(TripPositionUpdated::class, 1);
    Event::assertDispatched(
        TripPositionUpdated::class,
        fn (TripPositionUpdated $event): bool => $event->position->id === $summary['lastPosition']->id,
    );
});

it('bloquea la fila del viaje antes de leer el último punto', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();

    DB::enableQueryLog();

    tripPositionService()->storePositions($pilot, $trip->id, tripPositionServiceBatch([0]));

    $queries = collect(DB::getQueryLog())->pluck('query');

    $lock = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "trips"') && str_contains($sql, 'for update'));
    $last = $queries->search(fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from "trip_positions"'));

    expect($lock)->toBeInt()
        ->and($last)->toBeInt()
        ->and($lock)->toBeLessThan($last);
});

it('deshace el lote entero si la detección de paradas falla a la mitad', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripPositionServiceTrip();

    app()->instance(TripTimeoutServiceInterface::class, new class implements TripTimeoutServiceInterface
    {
        private int $calls = 0;

        public function getTimeouts(User $user, int $tripId, array $filters): LengthAwarePaginator|Collection
        {
            return new Collection;
        }

        public function trackPosition(TripPosition $position): void
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('La detección falló');
            }
        }
    });

    expect(fn () => tripPositionService()->storePositions($pilot, $trip->id, tripPositionServiceBatch([0, 6, 12])))
        ->toThrow(RuntimeException::class, 'La detección falló');

    expect(TripPosition::count())->toBe(0);
    Event::assertNotDispatched(TripPositionUpdated::class);
});
