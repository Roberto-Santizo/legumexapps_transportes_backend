<?php

use App\Enums\FuelType;
use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripFuel;
use App\Models\User;
use App\Models\Vehicle;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * The three routes of the domain as method and URI, for the middleware datasets.
 *
 * Two of them are nested under `{trip}` like the track of SPEC 26; the confirmation is
 * not, because the id of the load already identifies its trip.
 *
 * @return array<string, array{string, string}>
 */
function tripFuelEndpoints(): array
{
    return [
        'store' => ['POST', '/api/trips/1/fuels'],
        'index' => ['GET', '/api/trips/1/fuels'],
        'confirm' => ['PATCH', '/api/trip-fuels/1/confirm'],
    ];
}

/**
 * The three roles `role:carrier` keeps out of the registering endpoint.
 *
 * @return array<string, UserRole>
 */
function tripFuelNonCarrierRoles(): array
{
    return [
        'administrator' => UserRole::Administrator,
        'manager' => UserRole::Manager,
        'pilot' => UserRole::Pilot,
    ];
}

/**
 * The three roles `role:pilot` keeps out of the confirmation endpoint.
 *
 * @return array<string, UserRole>
 */
function tripFuelNonPilotRoles(): array
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
function tripFuelScene(string $state = 'assigned', array $attributes = []): array
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
function tripFuelStranger(): User
{
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    return $carrier->owner;
}

/**
 * A valid store payload with the two fields `StoreTripFuelRequest` declares.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tripFuelPayload(array $overrides = []): array
{
    return array_merge([
        'gallons' => 20,
        'fuelType' => FuelType::Diesel->value,
    ], $overrides);
}

/**
 * The eight keys `TripFuelResource` promises, in the order it declares them.
 *
 * @return array<int, string>
 */
function tripFuelResourceKeys(): array
{
    return ['id', 'tripId', 'gallons', 'fuelType', 'isConfirmed', 'loadedAt', 'confirmedByName', 'registeredByName'];
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth y role
|--------------------------------------------------------------------------
*/

it('rechaza con 401 las tres rutas de combustible sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(tripFuelEndpoints());

it('rechaza con 403 a quien no es transportista al registrar una carga', function (UserRole $role) {
    ['trip' => $trip] = tripFuelScene();

    asUser(userWithRole($role))->postJson("/api/trips/{$trip->id}/fuels", tripFuelPayload())
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);

    expect(TripFuel::count())->toBe(0);
})->with(tripFuelNonCarrierRoles());

it('rechaza con 403 a quien no es piloto al confirmar una carga', function (UserRole $role) {
    ['trip' => $trip] = tripFuelScene();
    $fuel = TripFuel::factory()->create(['trip_id' => $trip->id]);

    asUser(userWithRole($role))->patchJson("/api/trip-fuels/{$fuel->id}/confirm")
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);

    expect($fuel->fresh()->loaded_at)->toBeNull()
        ->and($fuel->fresh()->confirmed_by)->toBeNull();
})->with(tripFuelNonPilotRoles());

/*
|--------------------------------------------------------------------------
| POST /api/trips/{trip}/fuels — las cuatro guardas, en su orden
|--------------------------------------------------------------------------
*/

it('registra la carga sin confirmar del transportista que tomó el viaje', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene();

    $response = asUser($owner)->postJson("/api/trips/{$trip->id}/fuels", tripFuelPayload(['gallons' => 45.5]))
        ->assertCreated()
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Carga de combustible registrada correctamente')
        ->assertJsonPath('data.gallons', '45.50')
        ->assertJsonPath('data.fuelType', 'diesel')
        /** Registrar no es confirmar: nace sin fecha y sin piloto. */
        ->assertJsonPath('data.isConfirmed', false)
        ->assertJsonPath('data.loadedAt', null)
        ->assertJsonPath('data.confirmedByName', null)
        ->assertJsonPath('data.registeredByName', $owner->name);

    $this->assertDatabaseHas('trip_fuels', [
        'trip_id' => $trip->id,
        'gallons' => '45.50',
        'fuel_type' => 'diesel',
        'loaded_at' => null,
        'confirmed_by' => null,
        /** El autor sale del usuario autenticado, nunca del body. */
        'registered_by' => $owner->id,
    ]);

    expect(array_keys($response->json('data')))->toBe(tripFuelResourceKeys())
        ->and($response->json('data.tripId'))->toBe($trip->id);
});

it('deja registrar a cualquier usuario de la empresa que tomó el viaje, no solo al que asignó', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene();

    /** Un compañero de la misma empresa: el ámbito compara empresas, no personas. */
    $companero = userWithRole(UserRole::Carrier);
    $owner->currentCarrier()->pilots()->attach($companero);

    asUser($companero)->postJson("/api/trips/{$trip->id}/fuels", tripFuelPayload())->assertCreated();

    $this->assertDatabaseHas('trip_fuels', [
        'trip_id' => $trip->id,
        'registered_by' => $companero->id,
    ]);
});

it('rechaza con 404 registrar sobre un viaje que no existe', function () {
    asUser(tripFuelStranger())->postJson('/api/trips/99999/fuels', tripFuelPayload())
        ->assertNotFound()
        ->assertJsonPath('message', 'El viaje no existe');

    expect(TripFuel::count())->toBe(0);
});

it('rechaza con 400, y no con 403, un viaje borrado que además es de otra empresa', function () {
    /** La segunda guarda gana a la tercera: el borrado se responde antes de mirar de quién es. */
    ['trip' => $trip] = tripFuelScene('assigned', ['deleted_at' => now()]);

    asUser(tripFuelStranger())->postJson("/api/trips/{$trip->id}/fuels", tripFuelPayload())
        ->assertBadRequest()
        ->assertJsonPath('message', 'El viaje ya fue eliminado');

    expect(TripFuel::count())->toBe(0);
});

it('rechaza con 403 registrar sobre un viaje que todavía no tomó nadie', function () {
    /** La bolsa se lee, pero no se carga: una carga sin piloto que la confirme nacería atascada. */
    $trip = Trip::factory()->create();

    asUser(tripFuelStranger())->postJson("/api/trips/{$trip->id}/fuels", tripFuelPayload())
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes registrar combustible en un viaje que no tomó tu empresa transportista');

    expect(TripFuel::count())->toBe(0);
});

it('rechaza con 403 registrar sobre un viaje que tomó otra empresa', function () {
    ['trip' => $trip] = tripFuelScene();

    asUser(tripFuelStranger())->postJson("/api/trips/{$trip->id}/fuels", tripFuelPayload())
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes registrar combustible en un viaje que no tomó tu empresa transportista');

    expect(TripFuel::count())->toBe(0);
});

it('rechaza con 403 al transportista que no pertenece a ninguna empresa', function () {
    ['trip' => $trip] = tripFuelScene();

    /** La ruta no lleva carrier.required: el 403 lo levanta el service. */
    asUser(userWithRole(UserRole::Carrier))->postJson("/api/trips/{$trip->id}/fuels", tripFuelPayload())
        ->assertForbidden()
        ->assertJsonPath('message', 'No perteneces a ninguna empresa transportista');

    expect(TripFuel::count())->toBe(0);
});

it('rechaza con 400 registrar sobre un viaje ya finalizado', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene('finished');

    asUser($owner)->postJson("/api/trips/{$trip->id}/fuels", tripFuelPayload())
        ->assertBadRequest()
        ->assertJsonPath('message', 'El viaje ya fue finalizado');

    expect(TripFuel::count())->toBe(0);
});

it('acepta la carga con el viaje pendiente y con el viaje en ruta', function (string $estado) {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene($estado);

    asUser($owner)->postJson("/api/trips/{$trip->id}/fuels", tripFuelPayload())->assertCreated();

    expect(TripFuel::where('trip_id', $trip->id)->count())->toBe(1);
})->with([
    'pendiente' => 'assigned',
    'en ruta' => 'inRoute',
]);

it('guarda dos cargas del mismo viaje con tipos de combustible distintos', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene();

    asUser($owner)->postJson("/api/trips/{$trip->id}/fuels", tripFuelPayload([
        'gallons' => 30,
        'fuelType' => FuelType::Diesel->value,
    ]))->assertCreated();

    asUser($owner)->postJson("/api/trips/{$trip->id}/fuels", tripFuelPayload([
        'gallons' => 12.25,
        'fuelType' => FuelType::Premium->value,
    ]))->assertCreated();

    $tipos = TripFuel::where('trip_id', $trip->id)->orderBy('id')->get()
        ->map(fn (TripFuel $fuel) => $fuel->fuel_type->value)
        ->all();

    expect($tipos)->toBe(['diesel', 'premium'])
        ->and(TripFuel::where('trip_id', $trip->id)->count())->toBe(2);
});

it('ignora en el cuerpo todo lo que no son los dos campos del contrato', function () {
    ['trip' => $trip, 'owner' => $owner, 'pilot' => $pilot] = tripFuelScene();
    $otroViaje = Trip::factory()->create();

    asUser($owner)->postJson("/api/trips/{$trip->id}/fuels", tripFuelPayload([
        'tripId' => $otroViaje->id,
        'trip_id' => $otroViaje->id,
        'loadedAt' => '01-01-2020 08:00:00',
        'loaded_at' => '2020-01-01 08:00:00',
        'confirmedBy' => $pilot->id,
        'confirmed_by' => $pilot->id,
        'registeredBy' => $pilot->id,
        'registered_by' => $pilot->id,
    ]))->assertCreated();

    $fuel = TripFuel::firstOrFail();

    expect($fuel->trip_id)->toBe($trip->id)
        ->and($fuel->loaded_at)->toBeNull()
        ->and($fuel->confirmed_by)->toBeNull()
        ->and($fuel->registered_by)->toBe($owner->id);
});

/*
|--------------------------------------------------------------------------
| POST: validación del cuerpo
|--------------------------------------------------------------------------
*/

it('rechaza con 422 un cuerpo inválido en la carga', function (array $payload, string $campo, string $mensaje) {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene();

    asUser($owner)->postJson("/api/trips/{$trip->id}/fuels", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo])
        ->assertJsonFragment([$mensaje]);

    expect(TripFuel::count())->toBe(0);
})->with([
    'sin galones' => [['fuelType' => 'diesel'], 'gallons', 'Los galones son obligatorios'],
    'galones no numéricos' => [['gallons' => 'veinte', 'fuelType' => 'diesel'], 'gallons', 'Los galones deben ser un número'],
    'galones en cero' => [['gallons' => 0, 'fuelType' => 'diesel'], 'gallons', 'Los galones deben ser mayores a 0'],
    'galones negativos' => [['gallons' => -5, 'fuelType' => 'diesel'], 'gallons', 'Los galones deben ser mayores a 0'],
    'sin tipo' => [['gallons' => 20], 'fuelType', 'El tipo de combustible es obligatorio'],
    'tipo fuera del enum' => [['gallons' => 20, 'fuelType' => 'gasolina'], 'fuelType', 'El tipo de combustible seleccionado no es válido'],
    'cuerpo vacío' => [[], 'gallons', 'Los galones son obligatorios'],
]);

it('acepta los cuatro casos del enum de combustible', function (FuelType $fuelType) {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene();

    asUser($owner)->postJson("/api/trips/{$trip->id}/fuels", tripFuelPayload(['fuelType' => $fuelType->value]))
        ->assertCreated()
        ->assertJsonPath('data.fuelType', $fuelType->value);
})->with(FuelType::cases());

it('valida el cuerpo antes que las guardas: un id inexistente con cuerpo vacío es 422, no 404', function () {
    asUser(tripFuelStranger())->postJson('/api/trips/999999/fuels', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['gallons', 'fuelType']);
});

/*
|--------------------------------------------------------------------------
| PATCH /api/trip-fuels/{tripFuel}/confirm
|--------------------------------------------------------------------------
*/

it('confirma la carga del piloto asignado con la hora del servidor', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripFuelScene();
    $fuel = TripFuel::factory()->create(['trip_id' => $trip->id, 'gallons' => 45.5]);

    $this->travelTo(now()->setDate(2026, 9, 10)->setTime(8, 14, 3));

    asUser($pilot)->patchJson("/api/trip-fuels/{$fuel->id}/confirm")
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Carga de combustible confirmada correctamente')
        ->assertJsonPath('data.isConfirmed', true)
        ->assertJsonPath('data.loadedAt', '10-09-2026 08:14:03 AM')
        ->assertJsonPath('data.confirmedByName', $pilot->name)
        ->assertJsonPath('data.gallons', '45.50');

    $fresco = $fuel->fresh();

    expect($fresco->confirmed_by)->toBe($pilot->id)
        ->and($fresco->loaded_at->format('d-m-Y H:i:s'))->toBe('10-09-2026 08:14:03');
});

it('devuelve 200 sin pisar loaded_at al confirmar dos veces la misma carga', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripFuelScene();
    $fuel = TripFuel::factory()->create(['trip_id' => $trip->id]);

    $primera = asUser($pilot)->patchJson("/api/trip-fuels/{$fuel->id}/confirm")->assertOk();

    $this->travel(2)->hours();

    $segunda = asUser($pilot)->patchJson("/api/trip-fuels/{$fuel->id}/confirm")
        ->assertOk()
        ->assertJsonPath('message', 'Carga de combustible confirmada correctamente');

    expect($segunda->json('data.loadedAt'))->toBe($primera->json('data.loadedAt'))
        ->and($segunda->json('data.id'))->toBe($primera->json('data.id'))
        /** Nada nuevo: la confirmación no crea filas. */
        ->and(TripFuel::count())->toBe(1)
        ->and($fuel->fresh()->confirmed_by)->toBe($pilot->id);
});

it('acepta cuerpo vacío y no deja pisar loadedAt ni gallons desde el cuerpo', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripFuelScene();
    $fuel = TripFuel::factory()->create(['trip_id' => $trip->id, 'gallons' => 20]);
    $otroPiloto = userWithRole(UserRole::Pilot);

    asUser($pilot)->patchJson("/api/trip-fuels/{$fuel->id}/confirm")->assertOk();

    $confirmadaEn = $fuel->fresh()->loaded_at;

    $this->travel(1)->hour();

    asUser($pilot)->patchJson("/api/trip-fuels/{$fuel->id}/confirm", [
        'loadedAt' => '01-01-2020 08:00:00',
        'loaded_at' => '2020-01-01 08:00:00',
        'gallons' => 999,
        'fuelType' => FuelType::Regular->value,
        'confirmedBy' => $otroPiloto->id,
        'confirmed_by' => $otroPiloto->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.gallons', '20.00')
        ->assertJsonPath('data.fuelType', $fuel->fuel_type->value)
        ->assertJsonPath('data.confirmedByName', $pilot->name);

    expect($fuel->fresh()->loaded_at->equalTo($confirmadaEn))->toBeTrue()
        ->and($fuel->fresh()->gallons)->toBe('20.00')
        ->and($fuel->fresh()->confirmed_by)->toBe($pilot->id);
});

it('rechaza con 403 al piloto que no tiene asignado el viaje de la carga', function () {
    ['trip' => $trip] = tripFuelScene();
    $fuel = TripFuel::factory()->create(['trip_id' => $trip->id]);

    asUser(userWithRole(UserRole::Pilot))->patchJson("/api/trip-fuels/{$fuel->id}/confirm")
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No puedes confirmar la carga de un viaje que no tienes asignado',
            'data' => null,
        ]);

    expect($fuel->fresh()->loaded_at)->toBeNull();
});

it('rechaza con 404 confirmar una carga que no existe', function () {
    asUser(userWithRole(UserRole::Pilot))->patchJson('/api/trip-fuels/99999/confirm')
        ->assertNotFound()
        ->assertJsonPath('message', 'La carga de combustible no existe');
});

it('deja confirmar la carga de un viaje ya finalizado: la confirmación no mira el estado', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripFuelScene('finished');
    $fuel = TripFuel::factory()->create(['trip_id' => $trip->id]);

    asUser($pilot)->patchJson("/api/trip-fuels/{$fuel->id}/confirm")
        ->assertOk()
        ->assertJsonPath('data.isConfirmed', true);

    expect($fuel->fresh()->confirmed_by)->toBe($pilot->id);
});

/*
|--------------------------------------------------------------------------
| GET /api/trips/{trip}/fuels
|--------------------------------------------------------------------------
*/

it('devuelve las cargas del viaje en orden de id ascendente', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene();

    $primera = TripFuel::factory()->create(['trip_id' => $trip->id, 'gallons' => 10]);
    $segunda = TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id, 'gallons' => 20]);
    $tercera = TripFuel::factory()->create(['trip_id' => $trip->id, 'gallons' => 30]);

    /** La carga de otro viaje no se cuela en este. */
    TripFuel::factory()->create();

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/fuels")
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Cargas de combustible obtenidas correctamente');

    expect(array_column($response->json('data'), 'id'))->toBe([$primera->id, $segunda->id, $tercera->id])
        ->and(array_column($response->json('data'), 'gallons'))->toBe(['10.00', '20.00', '30.00']);
});

it('devuelve totalGallons en la raíz sumando solo las cargas confirmadas', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene();

    TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id, 'gallons' => 30.25]);
    TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id, 'gallons' => 12.75]);
    /** Registrada y sin confirmar: no cuenta. */
    TripFuel::factory()->create(['trip_id' => $trip->id, 'gallons' => 100]);

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/fuels")->assertOk();

    /** Sin limit no hay metadata de paginación, pero el acumulado sí viaja: es dato de negocio. */
    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data', 'totalGallons'])
        ->and($response->json('totalGallons'))->toBe('43.00')
        ->and($response->json('data'))->toHaveCount(3);
});

it('mantiene totalGallons como el total del viaje con limit=10 sobre veinticinco cargas', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene();

    TripFuel::factory()->count(25)->confirmed()->create(['trip_id' => $trip->id, 'gallons' => 10]);

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/fuels?limit=10")->assertOk();

    expect($response->json())->toHaveKeys(['statusCode', 'message', 'data', 'total', 'currentPage', 'lastPage', 'totalGallons'])
        ->and($response->json('data'))->toHaveCount(10)
        ->and($response->json('total'))->toBe(25)
        ->and($response->json('lastPage'))->toBe(3)
        /** El acumulado se calcula antes de paginar: 250, no los 100 de la página. */
        ->and($response->json('totalGallons'))->toBe('250.00')
        ->and($response->json('meta'))->toBeNull();
});

it('acota el tamaño de página a [10, 100] también por HTTP', function (string $limit, int $esperado) {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene();

    TripFuel::factory()->count(12)->create(['trip_id' => $trip->id]);

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/fuels?limit={$limit}")->assertOk();

    expect($response->json('data'))->toHaveCount($esperado);
})->with([
    'por debajo del piso' => ['5', 10],
    'cero' => ['0', 10],
    'por encima del techo' => ['500', 12],
]);

it('no pagina cuando el limit no es numérico', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene();

    TripFuel::factory()->count(11)->create(['trip_id' => $trip->id]);

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/fuels?limit=abc")->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data', 'totalGallons'])
        ->and($response->json('data'))->toHaveCount(11);
});

it('devuelve totalGallons en 0.00 cuando ninguna carga está confirmada', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene();

    TripFuel::factory()->count(2)->create(['trip_id' => $trip->id, 'gallons' => 50]);

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/fuels")->assertOk();

    expect($response->json('totalGallons'))->toBe('0.00')
        ->and($response->json('data'))->toHaveCount(2);
});

it('devuelve lista vacía y 0.00 para un viaje todavía sin cargas', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene();

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/fuels")->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('totalGallons'))->toBe('0.00');
});

it('deja leer el listado a quien está dentro del ámbito de SPEC 24', function (string $actor) {
    ['trip' => $trip, 'owner' => $owner, 'pilot' => $pilot] = tripFuelScene();

    TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id, 'gallons' => 15]);

    $user = match ($actor) {
        'administrator' => userWithRole(UserRole::Administrator),
        'manager' => userWithRole(UserRole::Manager),
        'pilot' => $pilot,
        default => $owner,
    };

    $response = asUser($user)->getJson("/api/trips/{$trip->id}/fuels")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('totalGallons'))->toBe('15.00');
})->with(['administrator', 'manager', 'carrier', 'pilot']);

it('rechaza con 403 al piloto que consulta las cargas de un viaje ajeno', function () {
    ['trip' => $trip] = tripFuelScene();

    asUser(userWithRole(UserRole::Pilot))->getJson("/api/trips/{$trip->id}/fuels")
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes acceder a un viaje que no tienes asignado');
});

it('rechaza con 403 al transportista fuera del ámbito del viaje', function () {
    ['trip' => $trip] = tripFuelScene();

    asUser(tripFuelStranger())->getJson("/api/trips/{$trip->id}/fuels")
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes acceder a un viaje que no pertenece a tu empresa transportista');
});

it('deja ver las cargas de un viaje de la bolsa a cualquier transportista', function () {
    /** Sin asignar no hay cargas, pero el viaje está en la bolsa y la lectura no es 403. */
    $trip = Trip::factory()->create();

    asUser(tripFuelStranger())->getJson("/api/trips/{$trip->id}/fuels")
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('totalGallons', '0.00');
});

it('rechaza con 404 el listado de un viaje inexistente o borrado', function (bool $borrado) {
    $tripId = $borrado
        ? Trip::factory()->assigned()->create(['deleted_at' => now()])->id
        : 99999;

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$tripId}/fuels")
        ->assertNotFound()
        ->assertJsonPath('message', 'El viaje no existe');
})->with([
    'inexistente' => false,
    'borrado' => true,
]);

it('devuelve cada carga con sus ocho claves, sin confirmar y con los dos nulos', function () {
    ['trip' => $trip, 'owner' => $owner] = tripFuelScene();
    $fuel = TripFuel::factory()->create([
        'trip_id' => $trip->id,
        'gallons' => 45.5,
        'fuel_type' => FuelType::DieselPremium,
        'registered_by' => $owner->id,
    ]);

    $carga = asUser($owner)->getJson("/api/trips/{$trip->id}/fuels")->assertOk()->json('data.0');

    expect(array_keys($carga))->toBe(tripFuelResourceKeys())
        ->and($carga['id'])->toBe($fuel->id)
        ->and($carga['tripId'])->toBe($trip->id)
        ->and($carga['gallons'])->toBe('45.50')
        ->and($carga['fuelType'])->toBe('diesel_premium')
        ->and($carga['isConfirmed'])->toBeFalse()
        ->and($carga['loadedAt'])->toBeNull()
        ->and($carga['confirmedByName'])->toBeNull()
        ->and($carga['registeredByName'])->toBe($owner->name);
});

it('devuelve la carga confirmada con la fecha del proyecto y el nombre del piloto', function () {
    ['trip' => $trip, 'owner' => $owner, 'pilot' => $pilot] = tripFuelScene();

    TripFuel::factory()->create([
        'trip_id' => $trip->id,
        'loaded_at' => '2026-09-10 08:14:03',
        'confirmed_by' => $pilot->id,
    ]);

    $carga = asUser($owner)->getJson("/api/trips/{$trip->id}/fuels")->assertOk()->json('data.0');

    expect($carga['isConfirmed'])->toBeTrue()
        ->and($carga['loadedAt'])->toBe('10-09-2026 08:14:03 AM')
        ->and($carga['confirmedByName'])->toBe($pilot->name);
});

/*
|--------------------------------------------------------------------------
| Lo que el dominio no publica
|--------------------------------------------------------------------------
*/

it('no publica listado global, ni edición, ni borrado de una carga', function () {
    ['trip' => $trip, 'pilot' => $pilot, 'owner' => $owner] = tripFuelScene();
    $fuel = TripFuel::factory()->create(['trip_id' => $trip->id]);

    asUser($owner)->getJson('/api/trip-fuels')->assertNotFound();
    asUser($owner)->patchJson("/api/trip-fuels/{$fuel->id}")->assertNotFound();
    asUser($owner)->deleteJson("/api/trip-fuels/{$fuel->id}")->assertNotFound();
    asUser($pilot)->deleteJson("/api/trips/{$trip->id}/fuels/{$fuel->id}")->assertNotFound();

    expect(TripFuel::count())->toBe(1);
});

it('no borra ninguna carga al dar de baja el viaje', function () {
    ['trip' => $trip] = tripFuelScene();

    TripFuel::factory()->count(2)->create(['trip_id' => $trip->id]);

    asUser(userWithRole(UserRole::Administrator))->deleteJson("/api/trips/{$trip->id}")->assertOk();

    expect(Trip::withTrashed()->findOrFail($trip->id)->trashed())->toBeTrue()
        ->and(TripFuel::where('trip_id', $trip->id)->count())->toBe(2);
});
