<?php

use App\Enums\FuelType;
use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\TripFuel\TripFuelServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripFuel;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripFuel\TripFuelService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolve the service through the container, which checks the Provider binding too.
 */
function tripFuelService(): TripFuelServiceInterface
{
    return app(TripFuelServiceInterface::class);
}

function tripFuelServiceUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

/**
 * A trip already taken by a company, with its assigned pilot and the owner that took it.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{trip: Trip, pilot: User, owner: User}
 */
function tripFuelServiceScene(string $state = 'assigned', array $attributes = []): array
{
    $trip = Trip::factory()->{$state}()->create($attributes);

    return [
        'trip' => $trip,
        'pilot' => User::findOrFail($trip->pilot_id),
        'owner' => User::findOrFail($trip->assigned_by),
    ];
}

/**
 * The owner of a company that has nothing to do with the trip under test.
 */
function tripFuelServiceStranger(): User
{
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    return $carrier->owner;
}

/**
 * The payload the service expects, straight from the validated request.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tripFuelServiceData(array $overrides = []): array
{
    return array_merge([
        'gallons' => 20,
        'fuelType' => FuelType::Diesel->value,
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Binding
|--------------------------------------------------------------------------
*/

it('resuelve el contrato del dominio contra su implementación', function () {
    expect(tripFuelService())->toBeInstanceOf(TripFuelService::class);
});

/*
|--------------------------------------------------------------------------
| create(): las cuatro guardas, en su orden
|--------------------------------------------------------------------------
*/

it('escribe la carga sin confirmar y con el autor autenticado', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelServiceScene();

    $fuel = tripFuelService()->create($owner, $trip->id, tripFuelServiceData(['gallons' => 45.5]));

    expect($fuel)->toBeInstanceOf(TripFuel::class)
        ->and($fuel->trip_id)->toBe($trip->id)
        /** Sin cast: el modelo recién creado devuelve lo que entró, y la columna decimal lo redondea a dos. */
        ->and($fuel->fresh()->gallons)->toBe('45.50')
        ->and($fuel->fuel_type)->toBe(FuelType::Diesel)
        /** Registrar no es confirmar: las dos columnas del ciclo de vida nacen nulas. */
        ->and($fuel->loaded_at)->toBeNull()
        ->and($fuel->confirmed_by)->toBeNull()
        ->and($fuel->registered_by)->toBe($owner->id)
        ->and(TripFuel::count())->toBe(1);
});

it('descarta el ciclo de vida y el autor que vengan en el payload', function () {
    ['trip' => $trip, 'owner' => $owner, 'pilot' => $pilot] = tripFuelServiceScene();
    $otroViaje = Trip::factory()->create();

    $fuel = tripFuelService()->create($owner, $trip->id, tripFuelServiceData([
        'trip_id' => $otroViaje->id,
        'loaded_at' => '2020-01-01 08:00:00',
        'confirmed_by' => $pilot->id,
        'registered_by' => $pilot->id,
    ]));

    expect($fuel->trip_id)->toBe($trip->id)
        ->and($fuel->loaded_at)->toBeNull()
        ->and($fuel->confirmed_by)->toBeNull()
        ->and($fuel->registered_by)->toBe($owner->id);
});

it('lanza 404 al registrar sobre un viaje que no existe', function () {
    expect(fn () => tripFuelService()->create(tripFuelServiceStranger(), 99999, tripFuelServiceData()))
        ->toThrow(NotFoundError::class, 'El viaje no existe');

    expect(TripFuel::count())->toBe(0);
});

it('lanza 400 sobre un viaje borrado, antes de mirar de quién es', function () {
    /** Borrado, ajeno y finalizado: aun así el mensaje es el de la segunda guarda. */
    ['trip' => $trip] = tripFuelServiceScene('finished', ['deleted_at' => now()]);

    expect(fn () => tripFuelService()->create(tripFuelServiceStranger(), $trip->id, tripFuelServiceData()))
        ->toThrow(BadRequestError::class, 'El viaje ya fue eliminado');

    expect(TripFuel::count())->toBe(0);
});

it('lanza 403 sobre un viaje ajeno o sin asignar, antes de mirar el estado', function (bool $asignado) {
    /** Finalizado, pero primero se comprueba de quién es. */
    $trip = $asignado
        ? Trip::factory()->finished()->create()
        : Trip::factory()->create();

    expect(fn () => tripFuelService()->create(tripFuelServiceStranger(), $trip->id, tripFuelServiceData()))
        ->toThrow(ForbiddenError::class, 'No puedes registrar combustible en un viaje que no tomó tu empresa transportista');

    expect(TripFuel::count())->toBe(0);
})->with([
    'asignado por otra empresa' => true,
    'todavía en la bolsa' => false,
]);

it('lanza 403 cuando quien registra no pertenece a ninguna empresa', function () {
    ['trip' => $trip] = tripFuelServiceScene();

    expect(fn () => tripFuelService()->create(
        tripFuelServiceUser(UserRole::Carrier),
        $trip->id,
        tripFuelServiceData(),
    ))->toThrow(ForbiddenError::class, 'No perteneces a ninguna empresa transportista');

    expect(TripFuel::count())->toBe(0);
});

it('lanza 400 sobre un viaje ya finalizado', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelServiceScene('finished');

    expect(fn () => tripFuelService()->create($owner, $trip->id, tripFuelServiceData()))
        ->toThrow(BadRequestError::class, 'El viaje ya fue finalizado');

    expect(TripFuel::count())->toBe(0);
});

it('acepta la carga con el viaje pendiente y con el viaje en ruta', function (string $estado) {
    ['trip' => $trip, 'owner' => $owner] = tripFuelServiceScene($estado);

    tripFuelService()->create($owner, $trip->id, tripFuelServiceData());

    expect(TripFuel::where('trip_id', $trip->id)->count())->toBe(1);
})->with([
    'pendiente' => 'assigned',
    'en ruta' => 'inRoute',
]);

it('deja que cualquier usuario de la empresa asignataria registre, no solo el que asignó', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelServiceScene();

    $companero = tripFuelServiceUser(UserRole::Carrier);
    $owner->currentCarrier()->pilots()->attach($companero);

    $fuel = tripFuelService()->create($companero, $trip->id, tripFuelServiceData());

    expect($fuel->registered_by)->toBe($companero->id);
});

it('acumula dos cargas independientes del mismo viaje, con tipos distintos', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelServiceScene();

    tripFuelService()->create($owner, $trip->id, tripFuelServiceData(['gallons' => 30, 'fuelType' => FuelType::Diesel->value]));
    tripFuelService()->create($owner, $trip->id, tripFuelServiceData(['gallons' => 30, 'fuelType' => FuelType::Regular->value]));

    /** Ningún índice único lo impide: dos camionadas iguales son legítimas. */
    expect(TripFuel::where('trip_id', $trip->id)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| confirm(): del piloto asignado, una sola vez
|--------------------------------------------------------------------------
*/

it('escribe loaded_at y confirmed_by juntos, con la hora del servidor', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripFuelServiceScene();
    $fuel = TripFuel::factory()->create(['trip_id' => $trip->id]);

    $confirmada = tripFuelService()->confirm($pilot, $fuel->id);

    expect($confirmada->id)->toBe($fuel->id)
        ->and($confirmada->confirmed_by)->toBe($pilot->id)
        ->and($confirmada->loaded_at)->not->toBeNull()
        ->and($confirmada->loaded_at->timestamp)->toBe(now()->timestamp)
        ->and(TripFuel::count())->toBe(1);
});

it('devuelve la carga con su fecha original al confirmarla dos veces, sin escribir', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripFuelServiceScene();
    $fuel = TripFuel::factory()->create(['trip_id' => $trip->id]);

    $primera = tripFuelService()->confirm($pilot, $fuel->id);
    $fechaOriginal = $primera->loaded_at;

    $this->travel(3)->hours();

    $segunda = tripFuelService()->confirm($pilot, $fuel->id);

    expect($segunda->id)->toBe($primera->id)
        ->and($segunda->loaded_at->equalTo($fechaOriginal))->toBeTrue()
        ->and($fuel->fresh()->loaded_at->equalTo($fechaOriginal))->toBeTrue()
        ->and(TripFuel::count())->toBe(1);
});

it('lanza 404 al confirmar una carga que no existe', function () {
    expect(fn () => tripFuelService()->confirm(tripFuelServiceUser(UserRole::Pilot), 99999))
        ->toThrow(NotFoundError::class, 'La carga de combustible no existe');
});

it('lanza 403 al piloto que no tiene asignado el viaje de la carga', function () {
    ['trip' => $trip] = tripFuelServiceScene();
    $fuel = TripFuel::factory()->create(['trip_id' => $trip->id]);

    expect(fn () => tripFuelService()->confirm(tripFuelServiceUser(UserRole::Pilot), $fuel->id))
        ->toThrow(ForbiddenError::class, 'No puedes confirmar la carga de un viaje que no tienes asignado');

    expect($fuel->fresh()->loaded_at)->toBeNull();
});

it('confirma sin mirar el estado del viaje: hasta uno finalizado admite papeleo atrasado', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripFuelServiceScene('finished');
    $fuel = TripFuel::factory()->create(['trip_id' => $trip->id]);

    expect(tripFuelService()->confirm($pilot, $fuel->id)->confirmed_by)->toBe($pilot->id);
});

/*
|--------------------------------------------------------------------------
| getTripFuels(): ámbito, orden, acumulado y paginación
|--------------------------------------------------------------------------
*/

it('lanza 404 al listar las cargas de un viaje inexistente o borrado', function (bool $borrado) {
    $tripId = $borrado
        ? Trip::factory()->assigned()->create(['deleted_at' => now()])->id
        : 99999;

    expect(fn () => tripFuelService()->getTripFuels(
        tripFuelServiceUser(UserRole::Administrator),
        $tripId,
        [],
    ))->toThrow(NotFoundError::class, 'El viaje no existe');
})->with([
    'inexistente' => false,
    'borrado' => true,
]);

it('lanza 403 al piloto que pide las cargas de un viaje que no es suyo', function () {
    ['trip' => $trip] = tripFuelServiceScene();

    expect(fn () => tripFuelService()->getTripFuels(tripFuelServiceUser(UserRole::Pilot), $trip->id, []))
        ->toThrow(ForbiddenError::class, 'No puedes acceder a un viaje que no tienes asignado');
});

it('lanza 403 al transportista que pide las cargas de un viaje de otra empresa', function () {
    ['trip' => $trip] = tripFuelServiceScene();

    expect(fn () => tripFuelService()->getTripFuels(tripFuelServiceStranger(), $trip->id, []))
        ->toThrow(ForbiddenError::class, 'No puedes acceder a un viaje que no pertenece a tu empresa transportista');
});

it('devuelve las cargas a los cuatro actores dentro del ámbito, incluido el piloto asignado', function (string $actor) {
    ['trip' => $trip, 'owner' => $owner, 'pilot' => $pilot] = tripFuelServiceScene();

    TripFuel::factory()->create(['trip_id' => $trip->id]);

    $user = match ($actor) {
        'administrator' => tripFuelServiceUser(UserRole::Administrator),
        'manager' => tripFuelServiceUser(UserRole::Manager),
        'pilot' => $pilot,
        default => $owner,
    };

    /** A diferencia del rastro de SPEC 26, el piloto asignado no queda fuera: el dato es suyo. */
    expect(tripFuelService()->getTripFuels($user, $trip->id, [])['fuels'])->toHaveCount(1);
})->with(['administrator', 'manager', 'carrier', 'pilot']);

it('devuelve solo las cargas del viaje pedido, ordenadas por id ascendente', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelServiceScene();

    $primera = TripFuel::factory()->create(['trip_id' => $trip->id]);
    $segunda = TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id]);
    $tercera = TripFuel::factory()->create(['trip_id' => $trip->id]);

    /** La carga de otro viaje no se cuela en este. */
    TripFuel::factory()->create();

    $result = tripFuelService()->getTripFuels($owner, $trip->id, []);

    expect($result['fuels'])->toBeInstanceOf(Collection::class)
        ->and($result['fuels']->pluck('id')->all())->toBe([$primera->id, $segunda->id, $tercera->id]);
});

it('devuelve lista vacía y el acumulado en cero para un viaje sin cargas', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelServiceScene();

    $result = tripFuelService()->getTripFuels($owner, $trip->id, []);

    expect($result['fuels'])->toBeInstanceOf(Collection::class)->toHaveCount(0)
        ->and($result['totalGallons'])->toBe('0.00');
});

it('suma en totalGallons solo las cargas confirmadas del viaje', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelServiceScene();

    TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id, 'gallons' => 30.25]);
    TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id, 'gallons' => 12.75]);
    /** Registrada y jamás confirmada: no llegó al camión y no cuenta. */
    TripFuel::factory()->create(['trip_id' => $trip->id, 'gallons' => 100]);
    /** Confirmada, pero de otro viaje. */
    TripFuel::factory()->confirmed()->create(['gallons' => 500]);

    $result = tripFuelService()->getTripFuels($owner, $trip->id, []);

    expect($result['totalGallons'])->toBe('43.00')
        ->and($result['fuels'])->toHaveCount(3);
});

it('devuelve "0.00" cuando el viaje tiene cargas pero ninguna confirmada', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelServiceScene();

    TripFuel::factory()->count(2)->create(['trip_id' => $trip->id, 'gallons' => 50]);

    expect(tripFuelService()->getTripFuels($owner, $trip->id, [])['totalGallons'])->toBe('0.00');
});

it('calcula el acumulado antes de paginar, así que la página no lo recorta', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelServiceScene();

    TripFuel::factory()->count(25)->confirmed()->create(['trip_id' => $trip->id, 'gallons' => 10]);

    $result = tripFuelService()->getTripFuels($owner, $trip->id, ['limit' => '10']);

    expect($result['fuels'])->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($result['fuels']->total())->toBe(25)
        ->and($result['fuels']->count())->toBe(10)
        /** El total del viaje, no los 100 de la página. */
        ->and($result['totalGallons'])->toBe('250.00');
});

it('no pagina sin limit ni con un limit que no es numérico', function (?string $limit) {
    ['trip' => $trip, 'owner' => $owner] = tripFuelServiceScene();

    TripFuel::factory()->count(3)->create(['trip_id' => $trip->id]);

    $result = tripFuelService()->getTripFuels($owner, $trip->id, ['limit' => $limit]);

    expect($result['fuels'])->toBeInstanceOf(Collection::class)->toHaveCount(3);
})->with([
    'sin limit' => null,
    'limit no numérico' => 'abc',
]);

it('pagina con un limit numérico, acotado a [10, 100]', function (string $limit, int $perPage) {
    ['trip' => $trip, 'owner' => $owner] = tripFuelServiceScene();

    TripFuel::factory()->count(12)->create(['trip_id' => $trip->id]);

    $result = tripFuelService()->getTripFuels($owner, $trip->id, ['limit' => $limit]);

    expect($result['fuels'])->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($result['fuels']->perPage())->toBe($perPage)
        ->and($result['fuels']->total())->toBe(12);
})->with([
    'por debajo del piso' => ['5', 10],
    'cero' => ['0', 10],
    'dentro del rango' => ['50', 50],
    'por encima del techo' => ['500', 100],
]);
