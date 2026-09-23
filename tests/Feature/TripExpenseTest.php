<?php

use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\User;
use App\Models\Vehicle;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * The three routes of the domain as method and URI, for the middleware datasets.
 *
 * Two of them are nested under `{trip}` like the fuel loads of SPEC 27; the
 * confirmation is not, because the id of the allowance already identifies its trip.
 *
 * @return array<string, array{string, string}>
 */
function tripExpenseEndpoints(): array
{
    return [
        'store' => ['POST', '/api/trips/1/expenses'],
        'index' => ['GET', '/api/trips/1/expenses'],
        'confirm' => ['PATCH', '/api/trip-expenses/1/confirm'],
    ];
}

/**
 * The roles `role:carrier,administrator` keeps out of the registering endpoint.
 *
 * @return array<string, UserRole>
 */
function tripExpenseNonCarrierRoles(): array
{
    return [
        'manager' => UserRole::Manager,
        'pilot' => UserRole::Pilot,
        'export' => UserRole::Export,
        'user' => UserRole::User,
        'shipment' => UserRole::Shipment,
    ];
}

/**
 * The three roles `role:pilot` keeps out of the confirmation endpoint.
 *
 * @return array<string, UserRole>
 */
function tripExpenseNonPilotRoles(): array
{
    return [
        'administrator' => UserRole::Administrator,
        'carrier' => UserRole::Carrier,
        'manager' => UserRole::Manager,
    ];
}

if (! function_exists('userWithRole')) {
    /**
     * Create a confirmed user with the given role.
     */
    function userWithRole(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }
}

if (! function_exists('asUser')) {
    /**
     * Authenticate the next request as the given user.
     *
     * The JWT singletons survive between calls of the same test, so the guard state is
     * dropped before handing the fresh token over.
     */
    function asUser(User $user): TestCase
    {
        resetAuthState();

        return test()->withToken(JWTAuth::fromUser($user))->withHeader('Accept', 'application/json');
    }
}

/**
 * A trip already taken by a company, with its assigned pilot and the owner that took
 * it — the three actors almost every test of this file needs.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{trip: Trip, pilot: User, owner: User}
 */
function tripExpenseScene(string $state = 'assigned', array $attributes = []): array
{
    $trip = Trip::factory()->{$state}()->create($attributes);

    return [
        'trip' => $trip,
        'pilot' => User::findOrFail($trip->pilot_id),
        'owner' => User::findOrFail($trip->assigned_by),
    ];
}

/**
 * The owner of another company, with a pilot and a vehicle of its own.
 */
function tripExpenseStranger(): User
{
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    return $carrier->owner;
}

/**
 * A valid store payload with the fields `StoreTripExpenseRequest` declares.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tripExpensePayload(array $overrides = []): array
{
    return array_merge([
        'amount' => 350,
        'description' => 'Alimentación y peajes',
    ], $overrides);
}

/**
 * The eight keys `TripExpenseResource` promises, in the order it declares them.
 *
 * @return array<int, string>
 */
function tripExpenseResourceKeys(): array
{
    return ['id', 'tripId', 'amount', 'description', 'isConfirmed', 'receivedAt', 'confirmedByName', 'registeredByName'];
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth y role
|--------------------------------------------------------------------------
*/

it('rechaza con 401 las tres rutas de viáticos sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(tripExpenseEndpoints());

it('rechaza con 403 a quien no es transportista al registrar un viático', function (UserRole $role) {
    ['trip' => $trip] = tripExpenseScene();

    asUser(userWithRole($role))->postJson("/api/trips/{$trip->id}/expenses", tripExpensePayload())
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);

    expect(TripExpense::count())->toBe(0);
})->with(tripExpenseNonCarrierRoles());

it('rechaza con 403 a quien no es piloto al confirmar un viático', function (UserRole $role) {
    ['trip' => $trip] = tripExpenseScene();
    $expense = TripExpense::factory()->create(['trip_id' => $trip->id]);

    asUser(userWithRole($role))->patchJson("/api/trip-expenses/{$expense->id}/confirm")
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);

    expect($expense->fresh()->received_at)->toBeNull()
        ->and($expense->fresh()->confirmed_by)->toBeNull();
})->with(tripExpenseNonPilotRoles());

/*
|--------------------------------------------------------------------------
| POST /api/trips/{trip}/expenses — las cuatro guardas, en su orden
|--------------------------------------------------------------------------
*/

it('registra el viático sin confirmar del transportista que tomó el viaje', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene();

    $response = asUser($owner)->postJson("/api/trips/{$trip->id}/expenses", tripExpensePayload(['amount' => 350.5]))
        ->assertCreated()
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Viático registrado correctamente')
        ->assertJsonPath('data.amount', '350.50')
        ->assertJsonPath('data.description', 'Alimentación y peajes')
        /** Registrar no es confirmar: nace sin fecha y sin piloto. */
        ->assertJsonPath('data.isConfirmed', false)
        ->assertJsonPath('data.receivedAt', null)
        ->assertJsonPath('data.confirmedByName', null)
        ->assertJsonPath('data.registeredByName', $owner->name);

    $this->assertDatabaseHas('trip_expenses', [
        'trip_id' => $trip->id,
        'amount' => '350.50',
        'description' => 'Alimentación y peajes',
        'received_at' => null,
        'confirmed_by' => null,
        /** El autor sale del usuario autenticado, nunca del body. */
        'registered_by' => $owner->id,
    ]);

    expect(array_keys($response->json('data')))->toBe(tripExpenseResourceKeys())
        ->and($response->json('data.tripId'))->toBe($trip->id);
});

it('guarda la descripción con solo trim y la deja en null cuando falta o va en blanco', function (array $payload, ?string $esperado) {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene();

    asUser($owner)->postJson("/api/trips/{$trip->id}/expenses", $payload)
        ->assertCreated()
        ->assertJsonPath('data.description', $esperado);

    expect(TripExpense::firstOrFail()->description)->toBe($esperado);
})->with([
    'sin descripción' => [['amount' => 100], null],
    'descripción null' => [['amount' => 100, 'description' => null], null],
    'solo espacios' => [['amount' => 100, 'description' => '   '], null],
    'con espacios alrededor' => [['amount' => 100, 'description' => '  Peajes  de  ida  '], 'Peajes  de  ida'],
]);

it('deja registrar a cualquier usuario de la empresa que tomó el viaje, no solo al que asignó', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene();

    /** Un compañero de la misma empresa: el ámbito compara empresas, no personas. */
    $companero = userWithRole(UserRole::Carrier);
    $owner->currentCarrier()->pilots()->attach($companero);

    asUser($companero)->postJson("/api/trips/{$trip->id}/expenses", tripExpensePayload())->assertCreated();

    $this->assertDatabaseHas('trip_expenses', [
        'trip_id' => $trip->id,
        'registered_by' => $companero->id,
    ]);
});

it('rechaza con 404 registrar sobre un viaje que no existe', function () {
    asUser(tripExpenseStranger())->postJson('/api/trips/99999/expenses', tripExpensePayload())
        ->assertNotFound()
        ->assertJsonPath('message', 'El viaje no existe');

    expect(TripExpense::count())->toBe(0);
});

it('rechaza con 400, y no con 403, un viaje borrado que además es de otra empresa', function () {
    /** La segunda guarda gana a la tercera: el borrado se responde antes de mirar de quién es. */
    ['trip' => $trip] = tripExpenseScene('assigned', ['deleted_at' => now()]);

    asUser(tripExpenseStranger())->postJson("/api/trips/{$trip->id}/expenses", tripExpensePayload())
        ->assertBadRequest()
        ->assertJsonPath('message', 'El viaje ya fue eliminado');

    expect(TripExpense::count())->toBe(0);
});

it('rechaza con 403 registrar sobre un viaje que todavía no tomó nadie', function () {
    /** La bolsa se lee, pero no se carga: un viático sin piloto que lo confirme nacería atascado. */
    $trip = Trip::factory()->create();

    asUser(tripExpenseStranger())->postJson("/api/trips/{$trip->id}/expenses", tripExpensePayload())
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes registrar viáticos en un viaje que no tomó tu empresa transportista');

    expect(TripExpense::count())->toBe(0);
});

it('rechaza con 403 registrar sobre un viaje que tomó otra empresa', function () {
    ['trip' => $trip] = tripExpenseScene();

    asUser(tripExpenseStranger())->postJson("/api/trips/{$trip->id}/expenses", tripExpensePayload())
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes registrar viáticos en un viaje que no tomó tu empresa transportista');

    expect(TripExpense::count())->toBe(0);
});

it('rechaza con 403 al transportista que no pertenece a ninguna empresa', function () {
    ['trip' => $trip] = tripExpenseScene();

    /** La ruta no lleva carrier.required: el 403 lo levanta el service. */
    asUser(userWithRole(UserRole::Carrier))->postJson("/api/trips/{$trip->id}/expenses", tripExpensePayload())
        ->assertForbidden()
        ->assertJsonPath('message', 'No perteneces a ninguna empresa transportista');

    expect(TripExpense::count())->toBe(0);
});

it('rechaza con 400 registrar sobre un viaje ya finalizado', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene('finished');

    asUser($owner)->postJson("/api/trips/{$trip->id}/expenses", tripExpensePayload())
        ->assertBadRequest()
        ->assertJsonPath('message', 'El viaje ya fue finalizado');

    expect(TripExpense::count())->toBe(0);
});

it('acepta el viático con el viaje pendiente y con el viaje en ruta', function (string $estado) {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene($estado);

    asUser($owner)->postJson("/api/trips/{$trip->id}/expenses", tripExpensePayload())->assertCreated();

    expect(TripExpense::where('trip_id', $trip->id)->count())->toBe(1);
})->with([
    'pendiente' => 'assigned',
    'en ruta' => 'inRoute',
]);

it('guarda dos viáticos idénticos del mismo viaje como dos filas', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene();

    asUser($owner)->postJson("/api/trips/{$trip->id}/expenses", tripExpensePayload(['amount' => 200]))->assertCreated();
    asUser($owner)->postJson("/api/trips/{$trip->id}/expenses", tripExpensePayload(['amount' => 200]))->assertCreated();

    expect(TripExpense::where('trip_id', $trip->id)->count())->toBe(2);
});

it('ignora en el cuerpo todo lo que no son los dos campos del contrato', function () {
    ['trip' => $trip, 'owner' => $owner, 'pilot' => $pilot] = tripExpenseScene();
    $otroViaje = Trip::factory()->create();

    asUser($owner)->postJson("/api/trips/{$trip->id}/expenses", tripExpensePayload([
        'tripId' => $otroViaje->id,
        'trip_id' => $otroViaje->id,
        'receivedAt' => '01-01-2020 08:00:00',
        'received_at' => '2020-01-01 08:00:00',
        'confirmedBy' => $pilot->id,
        'confirmed_by' => $pilot->id,
        'registeredBy' => $pilot->id,
        'registered_by' => $pilot->id,
    ]))->assertCreated();

    $expense = TripExpense::firstOrFail();

    expect($expense->trip_id)->toBe($trip->id)
        ->and($expense->received_at)->toBeNull()
        ->and($expense->confirmed_by)->toBeNull()
        ->and($expense->registered_by)->toBe($owner->id);
});

/*
|--------------------------------------------------------------------------
| POST: validación del cuerpo
|--------------------------------------------------------------------------
*/

it('rechaza con 422 un cuerpo inválido en el viático', function (array $payload, string $campo, string $mensaje) {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene();

    asUser($owner)->postJson("/api/trips/{$trip->id}/expenses", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo])
        ->assertJsonFragment([$mensaje]);

    expect(TripExpense::count())->toBe(0);
})->with([
    'sin monto' => [['description' => 'Peajes'], 'amount', 'El monto es obligatorio'],
    'monto no numérico' => [['amount' => 'trescientos'], 'amount', 'El monto debe ser un número'],
    'monto en cero' => [['amount' => 0], 'amount', 'El monto debe ser mayor a 0'],
    'monto negativo' => [['amount' => -5], 'amount', 'El monto debe ser mayor a 0'],
    'monto desbordado' => [['amount' => 100000000], 'amount', 'El monto no puede superar 99999999.99'],
    'descripción no texto' => [['amount' => 100, 'description' => ['x']], 'description', 'La descripción debe ser texto'],
    'descripción larga' => [['amount' => 100, 'description' => str_repeat('a', 256)], 'description', 'La descripción no puede superar los 255 caracteres'],
    'cuerpo vacío' => [[], 'amount', 'El monto es obligatorio'],
]);

it('valida el cuerpo antes que las guardas: un id inexistente con cuerpo vacío es 422, no 404', function () {
    asUser(tripExpenseStranger())->postJson('/api/trips/999999/expenses', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

/*
|--------------------------------------------------------------------------
| PATCH /api/trip-expenses/{tripExpense}/confirm
|--------------------------------------------------------------------------
*/

it('confirma el viático del piloto asignado con la hora del servidor', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripExpenseScene();
    $expense = TripExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 350.5]);

    $this->travelTo(now()->setDate(2026, 9, 18)->setTime(8, 14, 3));

    asUser($pilot)->patchJson("/api/trip-expenses/{$expense->id}/confirm")
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Viático confirmado correctamente')
        ->assertJsonPath('data.isConfirmed', true)
        ->assertJsonPath('data.receivedAt', '18-09-2026 08:14:03 AM')
        ->assertJsonPath('data.confirmedByName', $pilot->name)
        ->assertJsonPath('data.amount', '350.50');

    $fresco = $expense->fresh();

    expect($fresco->confirmed_by)->toBe($pilot->id)
        ->and($fresco->received_at->format('d-m-Y H:i:s'))->toBe('18-09-2026 08:14:03');
});

it('devuelve 200 sin pisar received_at al confirmar dos veces el mismo viático', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripExpenseScene();
    $expense = TripExpense::factory()->create(['trip_id' => $trip->id]);

    $primera = asUser($pilot)->patchJson("/api/trip-expenses/{$expense->id}/confirm")->assertOk();

    $this->travel(2)->hours();

    $segunda = asUser($pilot)->patchJson("/api/trip-expenses/{$expense->id}/confirm")
        ->assertOk()
        ->assertJsonPath('message', 'Viático confirmado correctamente');

    expect($segunda->json('data.receivedAt'))->toBe($primera->json('data.receivedAt'))
        ->and($segunda->json('data.id'))->toBe($primera->json('data.id'))
        /** Nada nuevo: la confirmación no crea filas. */
        ->and(TripExpense::count())->toBe(1)
        ->and($expense->fresh()->confirmed_by)->toBe($pilot->id);
});

it('acepta cuerpo vacío y no deja pisar receivedAt ni amount desde el cuerpo', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripExpenseScene();
    $expense = TripExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 200, 'description' => 'Peajes']);
    $otroPiloto = userWithRole(UserRole::Pilot);

    asUser($pilot)->patchJson("/api/trip-expenses/{$expense->id}/confirm")->assertOk();

    $confirmadoEn = $expense->fresh()->received_at;

    $this->travel(1)->hour();

    asUser($pilot)->patchJson("/api/trip-expenses/{$expense->id}/confirm", [
        'receivedAt' => '01-01-2020 08:00:00',
        'received_at' => '2020-01-01 08:00:00',
        'amount' => 999,
        'description' => 'Otra cosa',
        'confirmedBy' => $otroPiloto->id,
        'confirmed_by' => $otroPiloto->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.amount', '200.00')
        ->assertJsonPath('data.description', 'Peajes')
        ->assertJsonPath('data.confirmedByName', $pilot->name);

    expect($expense->fresh()->received_at->equalTo($confirmadoEn))->toBeTrue()
        ->and($expense->fresh()->amount)->toBe('200.00')
        ->and($expense->fresh()->confirmed_by)->toBe($pilot->id);
});

it('rechaza con 403 al piloto que no tiene asignado el viaje del viático', function () {
    ['trip' => $trip] = tripExpenseScene();
    $expense = TripExpense::factory()->create(['trip_id' => $trip->id]);

    asUser(userWithRole(UserRole::Pilot))->patchJson("/api/trip-expenses/{$expense->id}/confirm")
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No puedes confirmar el viático de un viaje que no tienes asignado',
            'data' => null,
        ]);

    expect($expense->fresh()->received_at)->toBeNull();
});

it('rechaza con 404 confirmar un viático que no existe', function () {
    asUser(userWithRole(UserRole::Pilot))->patchJson('/api/trip-expenses/99999/confirm')
        ->assertNotFound()
        ->assertJsonPath('message', 'El viático no existe');
});

it('deja confirmar el viático de un viaje ya finalizado: la confirmación no mira el estado', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripExpenseScene('finished');
    $expense = TripExpense::factory()->create(['trip_id' => $trip->id]);

    asUser($pilot)->patchJson("/api/trip-expenses/{$expense->id}/confirm")
        ->assertOk()
        ->assertJsonPath('data.isConfirmed', true);

    expect($expense->fresh()->confirmed_by)->toBe($pilot->id);
});

/*
|--------------------------------------------------------------------------
| GET /api/trips/{trip}/expenses
|--------------------------------------------------------------------------
*/

it('devuelve los viáticos del viaje en orden de id ascendente', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene();

    $primero = TripExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 100]);
    $segundo = TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 200]);
    $tercero = TripExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 300]);

    /** El viático de otro viaje no se cuela en este. */
    TripExpense::factory()->create();

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/expenses")
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Viáticos obtenidos correctamente');

    expect(array_column($response->json('data'), 'id'))->toBe([$primero->id, $segundo->id, $tercero->id])
        ->and(array_column($response->json('data'), 'amount'))->toBe(['100.00', '200.00', '300.00']);
});

it('devuelve totalAmount en la raíz sumando solo los viáticos confirmados', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene();

    TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 300.25]);
    TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 124.75]);
    /** Registrado y sin confirmar: no cuenta. */
    TripExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 1000]);

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/expenses")->assertOk();

    /** Sin limit no hay metadata de paginación, pero el acumulado sí viaja: es dato de negocio. */
    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data', 'totalAmount'])
        ->and($response->json('totalAmount'))->toBe('425.00')
        ->and($response->json('data'))->toHaveCount(3);
});

it('mantiene totalAmount como el total del viaje con limit=10 sobre veinticinco viáticos', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene();

    TripExpense::factory()->count(25)->confirmed()->create(['trip_id' => $trip->id, 'amount' => 10]);

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/expenses?limit=10")->assertOk();

    expect($response->json())->toHaveKeys(['statusCode', 'message', 'data', 'total', 'currentPage', 'lastPage', 'totalAmount'])
        ->and($response->json('data'))->toHaveCount(10)
        ->and($response->json('total'))->toBe(25)
        ->and($response->json('lastPage'))->toBe(3)
        /** El acumulado se calcula antes de paginar: 250, no los 100 de la página. */
        ->and($response->json('totalAmount'))->toBe('250.00')
        ->and($response->json('meta'))->toBeNull();
});

it('acota el tamaño de página a [10, 100] también por HTTP', function (string $limit, int $esperado) {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene();

    TripExpense::factory()->count(12)->create(['trip_id' => $trip->id]);

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/expenses?limit={$limit}")->assertOk();

    expect($response->json('data'))->toHaveCount($esperado);
})->with([
    'por debajo del piso' => ['5', 10],
    'cero' => ['0', 10],
    'por encima del techo' => ['500', 12],
]);

it('no pagina cuando el limit no es numérico', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene();

    TripExpense::factory()->count(11)->create(['trip_id' => $trip->id]);

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/expenses?limit=abc")->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data', 'totalAmount'])
        ->and($response->json('data'))->toHaveCount(11);
});

it('devuelve totalAmount en 0.00 cuando ningún viático está confirmado', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene();

    TripExpense::factory()->count(2)->create(['trip_id' => $trip->id, 'amount' => 500]);

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/expenses")->assertOk();

    expect($response->json('totalAmount'))->toBe('0.00')
        ->and($response->json('data'))->toHaveCount(2);
});

it('devuelve lista vacía y 0.00 para un viaje todavía sin viáticos', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene();

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/expenses")->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('totalAmount'))->toBe('0.00');
});

it('deja leer el listado a quien está dentro del ámbito de SPEC 24', function (string $actor) {
    ['trip' => $trip, 'owner' => $owner, 'pilot' => $pilot] = tripExpenseScene();

    TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 150]);

    $user = match ($actor) {
        'administrator' => userWithRole(UserRole::Administrator),
        'manager' => userWithRole(UserRole::Manager),
        'pilot' => $pilot,
        default => $owner,
    };

    $response = asUser($user)->getJson("/api/trips/{$trip->id}/expenses")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('totalAmount'))->toBe('150.00');
})->with(['administrator', 'manager', 'carrier', 'pilot']);

it('rechaza con 403 al piloto que consulta los viáticos de un viaje ajeno', function () {
    ['trip' => $trip] = tripExpenseScene();

    asUser(userWithRole(UserRole::Pilot))->getJson("/api/trips/{$trip->id}/expenses")
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes acceder a un viaje que no tienes asignado');
});

it('rechaza con 403 al transportista fuera del ámbito del viaje', function () {
    ['trip' => $trip] = tripExpenseScene();

    asUser(tripExpenseStranger())->getJson("/api/trips/{$trip->id}/expenses")
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes acceder a un viaje que no pertenece a tu empresa transportista');
});

it('deja ver los viáticos de un viaje de la bolsa a cualquier transportista', function () {
    /** Sin asignar no hay viáticos, pero el viaje está en la bolsa y la lectura no es 403. */
    $trip = Trip::factory()->create();

    asUser(tripExpenseStranger())->getJson("/api/trips/{$trip->id}/expenses")
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('totalAmount', '0.00');
});

it('rechaza con 404 el listado de un viaje inexistente o borrado', function (bool $borrado) {
    $tripId = $borrado
        ? Trip::factory()->assigned()->create(['deleted_at' => now()])->id
        : 99999;

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$tripId}/expenses")
        ->assertNotFound()
        ->assertJsonPath('message', 'El viaje no existe');
})->with([
    'inexistente' => false,
    'borrado' => true,
]);

it('devuelve cada viático con sus ocho claves, sin confirmar y con los dos nulos', function () {
    ['trip' => $trip, 'owner' => $owner] = tripExpenseScene();
    $expense = TripExpense::factory()->create([
        'trip_id' => $trip->id,
        'amount' => 350.5,
        'description' => null,
        'registered_by' => $owner->id,
    ]);

    $viatico = asUser($owner)->getJson("/api/trips/{$trip->id}/expenses")->assertOk()->json('data.0');

    expect(array_keys($viatico))->toBe(tripExpenseResourceKeys())
        ->and($viatico['id'])->toBe($expense->id)
        ->and($viatico['tripId'])->toBe($trip->id)
        ->and($viatico['amount'])->toBe('350.50')
        ->and($viatico['description'])->toBeNull()
        ->and($viatico['isConfirmed'])->toBeFalse()
        ->and($viatico['receivedAt'])->toBeNull()
        ->and($viatico['confirmedByName'])->toBeNull()
        ->and($viatico['registeredByName'])->toBe($owner->name);
});

it('devuelve el viático confirmado con la fecha del proyecto y el nombre del piloto', function () {
    ['trip' => $trip, 'owner' => $owner, 'pilot' => $pilot] = tripExpenseScene();

    TripExpense::factory()->create([
        'trip_id' => $trip->id,
        'received_at' => '2026-09-18 08:14:03',
        'confirmed_by' => $pilot->id,
    ]);

    $viatico = asUser($owner)->getJson("/api/trips/{$trip->id}/expenses")->assertOk()->json('data.0');

    expect($viatico['isConfirmed'])->toBeTrue()
        ->and($viatico['receivedAt'])->toBe('18-09-2026 08:14:03 AM')
        ->and($viatico['confirmedByName'])->toBe($pilot->name);
});

/*
|--------------------------------------------------------------------------
| Lo que el dominio no publica
|--------------------------------------------------------------------------
*/

it('no publica listado global, ni edición, ni borrado de un viático', function () {
    ['trip' => $trip, 'pilot' => $pilot, 'owner' => $owner] = tripExpenseScene();
    $expense = TripExpense::factory()->create(['trip_id' => $trip->id]);

    asUser($owner)->getJson('/api/trip-expenses')->assertNotFound();
    asUser($owner)->patchJson("/api/trip-expenses/{$expense->id}")->assertNotFound();
    asUser($owner)->deleteJson("/api/trip-expenses/{$expense->id}")->assertNotFound();
    asUser($pilot)->deleteJson("/api/trips/{$trip->id}/expenses/{$expense->id}")->assertNotFound();

    expect(TripExpense::count())->toBe(1);
});

it('no borra ningún viático al dar de baja el viaje', function () {
    ['trip' => $trip] = tripExpenseScene();

    TripExpense::factory()->count(2)->create(['trip_id' => $trip->id]);

    asUser(userWithRole(UserRole::Administrator))->deleteJson("/api/trips/{$trip->id}")->assertOk();

    expect(Trip::withTrashed()->findOrFail($trip->id)->trashed())->toBeTrue()
        ->and(TripExpense::where('trip_id', $trip->id)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Administrador: registra en cualquier viaje asignado
|--------------------------------------------------------------------------
*/

it('deja al administrador registrar un viático en un viaje asignado por cualquier empresa', function () {
    ['trip' => $trip] = tripExpenseScene();
    $administrator = userWithRole(UserRole::Administrator);

    asUser($administrator)->postJson("/api/trips/{$trip->id}/expenses", tripExpensePayload())
        ->assertCreated();

    expect(TripExpense::where('trip_id', $trip->id)->where('registered_by', $administrator->id)->count())->toBe(1);
});

it('responde 400 al administrador que registra un viático en un viaje sin asignar', function () {
    $trip = Trip::factory()->create();

    asUser(userWithRole(UserRole::Administrator))->postJson("/api/trips/{$trip->id}/expenses", tripExpensePayload())
        ->assertStatus(400)
        ->assertJsonPath('message', 'El viaje aún no fue asignado');

    expect(TripExpense::count())->toBe(0);
});
