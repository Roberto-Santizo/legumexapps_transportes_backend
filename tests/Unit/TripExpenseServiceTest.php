<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\TripExpense\TripExpenseServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripExpense\TripExpenseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolve the service through the container, which checks the Provider binding too.
 */
function tripExpenseService(): TripExpenseServiceInterface
{
    return app(TripExpenseServiceInterface::class);
}

function tripExpenseServiceUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

/**
 * A trip already taken by a company, with its assigned pilot and the owner that took it.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{trip: Trip, pilot: User, owner: User}
 */
function tripExpenseServiceScene(string $state = 'assigned', array $attributes = []): array
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
function tripExpenseServiceStranger(): User
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
function tripExpenseServiceData(array $overrides = []): array
{
    return array_merge([
        'amount' => 350,
        'description' => 'Alimentación y peajes',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Binding
|--------------------------------------------------------------------------
*/

it('resuelve el contrato del dominio contra su implementación', function () {
    expect(tripExpenseService())->toBeInstanceOf(TripExpenseService::class);
});

/*
|--------------------------------------------------------------------------
| create(): las cuatro guardas, en su orden
|--------------------------------------------------------------------------
*/

it('escribe el viático sin confirmar y con el autor autenticado', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseServiceScene();

    $expense = tripExpenseService()->create($owner, $trip->id, tripExpenseServiceData(['amount' => 350.5]));

    expect($expense)->toBeInstanceOf(TripExpense::class)
        ->and($expense->trip_id)->toBe($trip->id)
        /** Sin cast: el modelo recién creado devuelve lo que entró, y la columna decimal lo redondea a dos. */
        ->and($expense->fresh()->amount)->toBe('350.50')
        ->and($expense->description)->toBe('Alimentación y peajes')
        /** Registrar no es confirmar: las dos columnas del ciclo de vida nacen nulas. */
        ->and($expense->received_at)->toBeNull()
        ->and($expense->confirmed_by)->toBeNull()
        ->and($expense->registered_by)->toBe($owner->id)
        ->and(TripExpense::count())->toBe(1);
});

it('guarda la descripción en null cuando el payload no la trae', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseServiceScene();

    $expense = tripExpenseService()->create($owner, $trip->id, ['amount' => 100]);

    expect($expense->fresh()->description)->toBeNull();
});

it('descarta el ciclo de vida y el autor que vengan en el payload', function () {
    ['trip' => $trip, 'owner' => $owner, 'pilot' => $pilot] = tripExpenseServiceScene();
    $otroViaje = Trip::factory()->create();

    $expense = tripExpenseService()->create($owner, $trip->id, tripExpenseServiceData([
        'trip_id' => $otroViaje->id,
        'received_at' => '2020-01-01 08:00:00',
        'confirmed_by' => $pilot->id,
        'registered_by' => $pilot->id,
    ]));

    expect($expense->trip_id)->toBe($trip->id)
        ->and($expense->received_at)->toBeNull()
        ->and($expense->confirmed_by)->toBeNull()
        ->and($expense->registered_by)->toBe($owner->id);
});

it('lanza 404 al registrar sobre un viaje que no existe', function () {
    expect(fn () => tripExpenseService()->create(tripExpenseServiceStranger(), 99999, tripExpenseServiceData()))
        ->toThrow(NotFoundError::class, 'El viaje no existe');

    expect(TripExpense::count())->toBe(0);
});

it('lanza 400 sobre un viaje borrado, antes de mirar de quién es', function () {
    /** Borrado, ajeno y finalizado: aun así el mensaje es el de la segunda guarda. */
    ['trip' => $trip] = tripExpenseServiceScene('finished', ['deleted_at' => now()]);

    expect(fn () => tripExpenseService()->create(tripExpenseServiceStranger(), $trip->id, tripExpenseServiceData()))
        ->toThrow(BadRequestError::class, 'El viaje ya fue eliminado');

    expect(TripExpense::count())->toBe(0);
});

it('lanza 403 sobre un viaje ajeno o sin asignar, antes de mirar el estado', function (bool $asignado) {
    /** Finalizado, pero primero se comprueba de quién es. */
    $trip = $asignado
        ? Trip::factory()->finished()->create()
        : Trip::factory()->create();

    expect(fn () => tripExpenseService()->create(tripExpenseServiceStranger(), $trip->id, tripExpenseServiceData()))
        ->toThrow(ForbiddenError::class, 'No puedes registrar viáticos en un viaje que no tomó tu empresa transportista');

    expect(TripExpense::count())->toBe(0);
})->with([
    'asignado por otra empresa' => true,
    'todavía en la bolsa' => false,
]);

it('lanza 403 cuando quien registra no pertenece a ninguna empresa', function () {
    ['trip' => $trip] = tripExpenseServiceScene();

    expect(fn () => tripExpenseService()->create(
        tripExpenseServiceUser(UserRole::Carrier),
        $trip->id,
        tripExpenseServiceData(),
    ))->toThrow(ForbiddenError::class, 'No perteneces a ninguna empresa transportista');

    expect(TripExpense::count())->toBe(0);
});

it('lanza 400 sobre un viaje ya finalizado', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseServiceScene('finished');

    expect(fn () => tripExpenseService()->create($owner, $trip->id, tripExpenseServiceData()))
        ->toThrow(BadRequestError::class, 'El viaje ya fue finalizado');

    expect(TripExpense::count())->toBe(0);
});

it('acepta el viático con el viaje pendiente y con el viaje en ruta', function (string $estado) {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseServiceScene($estado);

    tripExpenseService()->create($owner, $trip->id, tripExpenseServiceData());

    expect(TripExpense::where('trip_id', $trip->id)->count())->toBe(1);
})->with([
    'pendiente' => 'assigned',
    'en ruta' => 'inRoute',
]);

it('deja que cualquier usuario de la empresa asignataria registre, no solo el que asignó', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseServiceScene();

    $companero = tripExpenseServiceUser(UserRole::Carrier);
    $owner->currentCarrier()->pilots()->attach($companero);

    $expense = tripExpenseService()->create($companero, $trip->id, tripExpenseServiceData());

    expect($expense->registered_by)->toBe($companero->id);
});

it('acumula dos viáticos independientes del mismo viaje', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseServiceScene();

    tripExpenseService()->create($owner, $trip->id, tripExpenseServiceData(['amount' => 200]));
    tripExpenseService()->create($owner, $trip->id, tripExpenseServiceData(['amount' => 200]));

    /** Ningún índice único lo impide: dos entregas iguales son legítimas. */
    expect(TripExpense::where('trip_id', $trip->id)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| confirm(): del piloto asignado, una sola vez
|--------------------------------------------------------------------------
*/

it('escribe received_at y confirmed_by juntos, con la hora del servidor', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripExpenseServiceScene();
    $expense = TripExpense::factory()->create(['trip_id' => $trip->id]);

    $confirmado = tripExpenseService()->confirm($pilot, $expense->id);

    expect($confirmado->id)->toBe($expense->id)
        ->and($confirmado->confirmed_by)->toBe($pilot->id)
        ->and($confirmado->received_at)->not->toBeNull()
        ->and($confirmado->received_at->timestamp)->toBe(now()->timestamp)
        ->and(TripExpense::count())->toBe(1);
});

it('devuelve el viático con su fecha original al confirmarlo dos veces, sin escribir', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripExpenseServiceScene();
    $expense = TripExpense::factory()->create(['trip_id' => $trip->id]);

    $primera = tripExpenseService()->confirm($pilot, $expense->id);
    $fechaOriginal = $primera->received_at;

    $this->travel(3)->hours();

    $segunda = tripExpenseService()->confirm($pilot, $expense->id);

    expect($segunda->id)->toBe($primera->id)
        ->and($segunda->received_at->equalTo($fechaOriginal))->toBeTrue()
        ->and($expense->fresh()->received_at->equalTo($fechaOriginal))->toBeTrue()
        ->and(TripExpense::count())->toBe(1);
});

it('lanza 404 al confirmar un viático que no existe', function () {
    expect(fn () => tripExpenseService()->confirm(tripExpenseServiceUser(UserRole::Pilot), 99999))
        ->toThrow(NotFoundError::class, 'El viático no existe');
});

it('lanza 403 al piloto que no tiene asignado el viaje del viático', function () {
    ['trip' => $trip] = tripExpenseServiceScene();
    $expense = TripExpense::factory()->create(['trip_id' => $trip->id]);

    expect(fn () => tripExpenseService()->confirm(tripExpenseServiceUser(UserRole::Pilot), $expense->id))
        ->toThrow(ForbiddenError::class, 'No puedes confirmar el viático de un viaje que no tienes asignado');

    expect($expense->fresh()->received_at)->toBeNull();
});

it('confirma sin mirar el estado del viaje: hasta uno finalizado admite papeleo atrasado', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripExpenseServiceScene('finished');
    $expense = TripExpense::factory()->create(['trip_id' => $trip->id]);

    expect(tripExpenseService()->confirm($pilot, $expense->id)->confirmed_by)->toBe($pilot->id);
});

/*
|--------------------------------------------------------------------------
| getTripExpenses(): ámbito, orden, acumulado y paginación
|--------------------------------------------------------------------------
*/

it('lanza 404 al listar los viáticos de un viaje inexistente o borrado', function (bool $borrado) {
    $tripId = $borrado
        ? Trip::factory()->assigned()->create(['deleted_at' => now()])->id
        : 99999;

    expect(fn () => tripExpenseService()->getTripExpenses(
        tripExpenseServiceUser(UserRole::Administrator),
        $tripId,
        [],
    ))->toThrow(NotFoundError::class, 'El viaje no existe');
})->with([
    'inexistente' => false,
    'borrado' => true,
]);

it('lanza 403 al piloto que pide los viáticos de un viaje que no es suyo', function () {
    ['trip' => $trip] = tripExpenseServiceScene();

    expect(fn () => tripExpenseService()->getTripExpenses(tripExpenseServiceUser(UserRole::Pilot), $trip->id, []))
        ->toThrow(ForbiddenError::class, 'No puedes acceder a un viaje que no tienes asignado');
});

it('lanza 403 al transportista que pide los viáticos de un viaje de otra empresa', function () {
    ['trip' => $trip] = tripExpenseServiceScene();

    expect(fn () => tripExpenseService()->getTripExpenses(tripExpenseServiceStranger(), $trip->id, []))
        ->toThrow(ForbiddenError::class, 'No puedes acceder a un viaje que no pertenece a tu empresa transportista');
});

it('devuelve los viáticos a los cuatro actores dentro del ámbito, incluido el piloto asignado', function (string $actor) {
    ['trip' => $trip, 'owner' => $owner, 'pilot' => $pilot] = tripExpenseServiceScene();

    TripExpense::factory()->create(['trip_id' => $trip->id]);

    $user = match ($actor) {
        'administrator' => tripExpenseServiceUser(UserRole::Administrator),
        'manager' => tripExpenseServiceUser(UserRole::Manager),
        'pilot' => $pilot,
        default => $owner,
    };

    /** Como con las cargas de SPEC 27, el piloto asignado no queda fuera: el dato es suyo. */
    expect(tripExpenseService()->getTripExpenses($user, $trip->id, [])['expenses'])->toHaveCount(1);
})->with(['administrator', 'manager', 'carrier', 'pilot']);

it('devuelve solo los viáticos del viaje pedido, ordenados por id ascendente', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseServiceScene();

    $primero = TripExpense::factory()->create(['trip_id' => $trip->id]);
    $segundo = TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id]);
    $tercero = TripExpense::factory()->create(['trip_id' => $trip->id]);

    /** El viático de otro viaje no se cuela en este. */
    TripExpense::factory()->create();

    $result = tripExpenseService()->getTripExpenses($owner, $trip->id, []);

    expect($result['expenses'])->toBeInstanceOf(Collection::class)
        ->and($result['expenses']->pluck('id')->all())->toBe([$primero->id, $segundo->id, $tercero->id]);
});

it('devuelve lista vacía y el acumulado en cero para un viaje sin viáticos', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseServiceScene();

    $result = tripExpenseService()->getTripExpenses($owner, $trip->id, []);

    expect($result['expenses'])->toBeInstanceOf(Collection::class)->toHaveCount(0)
        ->and($result['totalAmount'])->toBe('0.00');
});

it('suma en totalAmount solo los viáticos confirmados del viaje', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseServiceScene();

    TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 300.25]);
    TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 124.75]);
    /** Registrado y jamás confirmado: no llegó al piloto y no cuenta. */
    TripExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 1000]);
    /** Confirmado, pero de otro viaje. */
    TripExpense::factory()->confirmed()->create(['amount' => 5000]);

    $result = tripExpenseService()->getTripExpenses($owner, $trip->id, []);

    expect($result['totalAmount'])->toBe('425.00')
        ->and($result['expenses'])->toHaveCount(3);
});

it('devuelve "0.00" cuando el viaje tiene viáticos pero ninguno confirmado', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseServiceScene();

    TripExpense::factory()->count(2)->create(['trip_id' => $trip->id, 'amount' => 500]);

    expect(tripExpenseService()->getTripExpenses($owner, $trip->id, [])['totalAmount'])->toBe('0.00');
});

it('calcula el acumulado antes de paginar, así que la página no lo recorta', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseServiceScene();

    TripExpense::factory()->count(25)->confirmed()->create(['trip_id' => $trip->id, 'amount' => 10]);

    $result = tripExpenseService()->getTripExpenses($owner, $trip->id, ['limit' => '10']);

    expect($result['expenses'])->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($result['expenses']->total())->toBe(25)
        ->and($result['expenses']->count())->toBe(10)
        /** El total del viaje, no los 100 de la página. */
        ->and($result['totalAmount'])->toBe('250.00');
});

it('no pagina sin limit ni con un limit que no es numérico', function (?string $limit) {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseServiceScene();

    TripExpense::factory()->count(3)->create(['trip_id' => $trip->id]);

    $result = tripExpenseService()->getTripExpenses($owner, $trip->id, ['limit' => $limit]);

    expect($result['expenses'])->toBeInstanceOf(Collection::class)->toHaveCount(3);
})->with([
    'sin limit' => null,
    'limit no numérico' => 'abc',
]);

it('pagina con un limit numérico, acotado a [10, 100]', function (string $limit, int $perPage) {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseServiceScene();

    TripExpense::factory()->count(12)->create(['trip_id' => $trip->id]);

    $result = tripExpenseService()->getTripExpenses($owner, $trip->id, ['limit' => $limit]);

    expect($result['expenses'])->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($result['expenses']->perPage())->toBe($perPage)
        ->and($result['expenses']->total())->toBe(12);
})->with([
    'por debajo del piso' => ['5', 10],
    'cero' => ['0', 10],
    'dentro del rango' => ['50', 50],
    'por encima del techo' => ['500', 100],
]);
