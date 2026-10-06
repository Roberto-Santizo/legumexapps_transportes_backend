<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Events\Trip\TripPositionUpdated;
use App\Interfaces\TripTimeout\TripTimeoutServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripPosition;
use App\Models\TripTimeout;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * The two nested endpoints of the domain, for the middleware datasets.
 *
 * @return array<string, array{string, string}>
 */
function tripPositionEndpoints(): array
{
    return [
        'store' => ['POST', '/api/trips/1/positions'],
        'index' => ['GET', '/api/trips/1/positions'],
    ];
}

/**
 * The three roles the `role:pilot` middleware keeps out of the reporting endpoint.
 *
 * @return array<string, UserRole>
 */
function tripPositionNonPilotRoles(): array
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
     * The JWT singletons survive between calls of the same test, so the guard
     * state is dropped before handing the fresh token over.
     */
    function asUser(User $user): TestCase
    {
        resetAuthState();

        return test()->withToken(JWTAuth::fromUser($user))->withHeader('Accept', 'application/json');
    }
}

/**
 * A trip already in route, with its assigned pilot and the owner of the company that
 * took it — the three actors every test of this file needs.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{trip: Trip, pilot: User, owner: User}
 */
function tripInRoute(array $attributes = []): array
{
    $trip = Trip::factory()->inRoute()->create($attributes);

    return [
        'trip' => $trip,
        'pilot' => User::findOrFail($trip->pilot_id),
        'owner' => User::findOrFail($trip->assigned_by),
    ];
}

/**
 * Record a point of the given trip straight in the database.
 *
 * `recorded_at` is written by hand because the order of the track is what the listing
 * promises, and the factory would stamp every row with the very same now().
 */
function tripPositionAt(Trip $trip, string $recordedAt, float $latitude = 14.628074): TripPosition
{
    return TripPosition::factory()->create([
        'trip_id' => $trip->id,
        'pilot_id' => $trip->pilot_id,
        'latitude' => $latitude,
        'recorded_at' => $recordedAt,
    ]);
}

/**
 * The five keys `TripPositionResource` promises, in the order it declares them.
 *
 * @return array<int, string>
 */
function tripPositionResourceKeys(): array
{
    return ['id', 'latitude', 'longitude', 'recordedAt', 'pilotId'];
}

/**
 * The four keys `TripPositionBatchResource` promises, in the order it declares them.
 *
 * @return array<int, string>
 */
function tripPositionBatchKeys(): array
{
    return ['received', 'saved', 'discarded', 'lastPosition'];
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth y role
|--------------------------------------------------------------------------
*/

it('rechaza con 401 las dos rutas de posiciones sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(tripPositionEndpoints());

it('rechaza con 401 la autorización de canal sin token, con el sobre habitual', function () {
    $this->postJson('/api/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-trips.1',
    ])
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
});

it('rechaza con 403 a quien no es piloto al reportar una posición', function (UserRole $role) {
    ['trip' => $trip] = tripInRoute();

    asUser(userWithRole($role))->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => now()->toIso8601String()],
    ]])
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);

    expect(TripPosition::count())->toBe(0);
})->with(tripPositionNonPilotRoles());

/*
|--------------------------------------------------------------------------
| POST /api/trips/{trip}/positions
|--------------------------------------------------------------------------
*/

it('registra el punto del piloto asignado con el viaje en ruta', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    $response = asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => now()->toIso8601String()],
    ]])
        ->assertCreated()
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Posiciones registradas correctamente')
        ->assertJsonPath('data.received', 1)
        ->assertJsonPath('data.saved', 1)
        ->assertJsonPath('data.discarded', 0);

    $this->assertDatabaseHas('trip_positions', [
        'trip_id' => $trip->id,
        'pilot_id' => $pilot->id,
        'latitude' => '14.62807400',
        'longitude' => '-90.52255400',
    ]);

    expect(array_keys($response->json('data')))->toBe(tripPositionBatchKeys())
        ->and($response->json('data.lastPosition.pilotId'))->toBe($pilot->id)
        ->and(array_keys($response->json('data.lastPosition')))->toBe(tripPositionResourceKeys());

    Event::assertDispatched(TripPositionUpdated::class);
});

it('guarda la hora del dispositivo convertida a la zona de la app, no la del servidor', function () {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    $recordedAt = now()->subMinutes(10)->startOfSecond();

    $response = asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => $recordedAt->copy()->setTimezone('America/Guatemala')->toIso8601String()],
    ]])->assertCreated();

    $expected = $recordedAt->copy()->setTimezone(config('app.timezone'));

    expect($response->json('data.lastPosition.recordedAt'))->toBe($expected->format('d-m-Y h:i:s A'))
        ->and(TripPosition::firstOrFail()->recorded_at->format('d-m-Y H:i:s'))->toBe($expected->format('d-m-Y H:i:s'));
});

it('ignora el pilotId y el recorded_at en snake_case mandados en el punto', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();
    $otroPiloto = userWithRole(UserRole::Pilot);

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [[
        'latitude' => 14.628074,
        'longitude' => -90.522554,
        'recordedAt' => now()->toIso8601String(),
        'recorded_at' => '2000-01-01 00:00:00',
        'pilotId' => $otroPiloto->id,
        'pilot_id' => $otroPiloto->id,
    ]]])->assertCreated();

    $position = TripPosition::firstOrFail();

    expect($position->pilot_id)->toBe($pilot->id)
        ->and($position->recorded_at->year)->toBe(now()->year);
});

it('rechaza con 403 al piloto que no tiene el viaje asignado', function () {
    ['trip' => $trip] = tripInRoute();

    asUser(userWithRole(UserRole::Pilot))->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => now()->toIso8601String()],
    ]])
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes reportar la posición de un viaje que no tienes asignado');

    expect(TripPosition::count())->toBe(0);
});

it('rechaza con 400 reportar sobre un viaje que no está en ruta', function (string $estado) {
    $trip = Trip::factory()->{$estado}()->create();
    $pilot = User::findOrFail($trip->pilot_id);

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => now()->toIso8601String()],
    ]])
        ->assertBadRequest()
        ->assertJsonPath('message', 'El viaje no está en ruta');

    expect(TripPosition::count())->toBe(0);
})->with([
    'pendiente' => 'assigned',
    'finalizado' => 'finished',
]);

it('rechaza con 400 reportar sobre un viaje ya eliminado', function () {
    $trip = Trip::factory()->inRoute()->trashed()->create();
    $pilot = User::findOrFail($trip->pilot_id);

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => now()->toIso8601String()],
    ]])
        ->assertBadRequest()
        ->assertJsonPath('message', 'El viaje ya fue eliminado');

    expect(TripPosition::count())->toBe(0);
});

it('rechaza con 404 reportar sobre un viaje que no existe', function () {
    asUser(userWithRole(UserRole::Pilot))->postJson('/api/trips/99999/positions', ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => now()->toIso8601String()],
    ]])
        ->assertNotFound()
        ->assertJsonPath('message', 'El viaje no existe');
});

it('rechaza con 422 una coordenada fuera del sistema de referencia', function (array $point, string $campo, string $mensaje) {
    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        [...$point, 'recordedAt' => now()->toIso8601String()],
    ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo])
        ->assertJsonFragment([$mensaje]);

    expect(TripPosition::count())->toBe(0);
})->with([
    'latitud por encima de 90' => [['latitude' => 91, 'longitude' => -90.5], 'positions.0.latitude', 'La latitud del punto 1 debe estar entre -90 y 90'],
    'latitud por debajo de -90' => [['latitude' => -91, 'longitude' => -90.5], 'positions.0.latitude', 'La latitud del punto 1 debe estar entre -90 y 90'],
    'longitud por encima de 180' => [['latitude' => 14.6, 'longitude' => 181], 'positions.0.longitude', 'La longitud del punto 1 debe estar entre -180 y 180'],
    'longitud por debajo de -180' => [['latitude' => 14.6, 'longitude' => -181], 'positions.0.longitude', 'La longitud del punto 1 debe estar entre -180 y 180'],
    'latitud no numérica' => [['latitude' => 'norte', 'longitude' => -90.5], 'positions.0.latitude', 'La latitud del punto 1 debe ser un número'],
    'longitud no numérica' => [['latitude' => 14.6, 'longitude' => 'oeste'], 'positions.0.longitude', 'La longitud del punto 1 debe ser un número'],
]);

it('rechaza con 422 el cuerpo vacío señalando las posiciones', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['positions'])
        ->assertJsonFragment(['Las posiciones son obligatorias']);

    expect(TripPosition::count())->toBe(0);
});

it('devuelve 200 con el punto anterior y no escribe nada antes de los cinco segundos', function () {
    Event::fake([TripPositionUpdated::class]);

    /** Se congela el reloj para que la hora mandada sea exacta al segundo. */
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    $primero = asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => now()->toIso8601String()],
    ]])->assertCreated();

    $this->travel(4)->seconds();

    $segundo = asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 15.111111, 'longitude' => -91.222222, 'recordedAt' => now()->toIso8601String()],
    ]])
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Posiciones recibidas correctamente')
        ->assertJsonPath('data.saved', 0)
        ->assertJsonPath('data.discarded', 1);

    expect(TripPosition::count())->toBe(1)
        ->and($segundo->json('data.lastPosition.id'))->toBe($primero->json('data.lastPosition.id'))
        ->and($segundo->json('data.lastPosition.latitude'))->toBe('14.62807400');

    /** El punto descartado no se anuncia: solo se emitió el primero. */
    Event::assertDispatchedTimes(TripPositionUpdated::class, 1);
});

it('registra y emite el segundo punto pasados los cinco segundos', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => now()->toIso8601String()],
    ]])->assertCreated();

    $this->travel(6)->seconds();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 15.111111, 'longitude' => -91.222222, 'recordedAt' => now()->toIso8601String()],
    ]])->assertCreated();

    expect(TripPosition::count())->toBe(2);

    Event::assertDispatchedTimes(TripPositionUpdated::class, 2);
});

it('responde 201 y guarda la fila aunque el broadcast reviente', function () {
    Log::spy();

    /**
     * Reverb caído se simula por donde el evento sale de verdad: el Dispatcher resuelve
     * la fábrica de broadcasting del contenedor y le pide queue(), así que basta con
     * sustituirla por una que lance. Nada se levanta y nada sale a la red.
     */
    app()->bind(BroadcastingFactory::class, fn (): BroadcastingFactory => new class implements BroadcastingFactory
    {
        public function connection($name = null): Broadcaster
        {
            throw new RuntimeException('Reverb no está corriendo');
        }

        public function queue(object $event): void
        {
            throw new RuntimeException('Reverb no está corriendo');
        }
    });

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => now()->toIso8601String()],
    ]])
        ->assertCreated()
        ->assertJsonPath('message', 'Posiciones registradas correctamente');

    $this->assertDatabaseHas('trip_positions', [
        'trip_id' => $trip->id,
        'pilot_id' => $pilot->id,
        'latitude' => '14.62807400',
    ]);

    Log::shouldHaveReceived('error')->once();
});

/*
|--------------------------------------------------------------------------
| GET /api/trips/{trip}/positions
|--------------------------------------------------------------------------
*/

it('devuelve el rastro ordenado por recorded_at ascendente a quien está en ámbito', function (string $actor) {
    ['trip' => $trip, 'owner' => $owner] = tripInRoute();

    tripPositionAt($trip, '2026-09-07 08:00:30', 14.3);
    tripPositionAt($trip, '2026-09-07 08:00:00', 14.1);
    tripPositionAt($trip, '2026-09-07 08:00:15', 14.2);

    $user = match ($actor) {
        'administrator' => userWithRole(UserRole::Administrator),
        'manager' => userWithRole(UserRole::Manager),
        default => $owner,
    };

    $response = asUser($user)->getJson("/api/trips/{$trip->id}/positions")
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Posiciones obtenidas correctamente');

    expect(array_column($response->json('data'), 'latitude'))
        ->toBe(['14.10000000', '14.20000000', '14.30000000']);
})->with(['administrator', 'manager', 'carrier']);

it('rechaza con 403 a cualquier piloto, incluido el asignado, al consultar el rastro', function (bool $asignado) {
    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    $user = $asignado ? $pilot : userWithRole(UserRole::Pilot);

    asUser($user)->getJson("/api/trips/{$trip->id}/positions")
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para consultar el rastro de un viaje',
            'data' => null,
        ]);
})->with([
    'el piloto asignado' => true,
    'un piloto ajeno' => false,
]);

it('rechaza con 403 al transportista de otra empresa', function () {
    ['trip' => $trip] = tripInRoute();

    asUser(Carrier::factory()->create()->owner)->getJson("/api/trips/{$trip->id}/positions")
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes acceder a un viaje que no pertenece a tu empresa transportista');
});

it('deja el rastro de un viaje pendiente sin tripulación a la vista de cualquier transportista', function () {
    $trip = Trip::factory()->create();

    asUser(Carrier::factory()->create()->owner)->getJson("/api/trips/{$trip->id}/positions")
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('rechaza con 404 el rastro de un viaje inexistente o borrado', function (bool $borrado) {
    $tripId = $borrado
        ? Trip::factory()->inRoute()->trashed()->create()->id
        : 99999;

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$tripId}/positions")
        ->assertNotFound()
        ->assertJsonPath('message', 'El viaje no existe');
})->with([
    'inexistente' => false,
    'borrado' => true,
]);

it('devuelve lista vacía con 200 para un viaje todavía sin puntos', function () {
    ['trip' => $trip] = tripInRoute();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$trip->id}/positions")
        ->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('devuelve la colección completa y ninguna clave de paginación sin limit', function () {
    ['trip' => $trip] = tripInRoute();

    for ($i = 0; $i < 12; $i++) {
        tripPositionAt($trip, now()->addSeconds($i * 15)->format('Y-m-d H:i:s'));
    }

    $response = asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$trip->id}/positions")
        ->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12);
});

it('devuelve los metadatos de paginación en la raíz del sobre', function () {
    ['trip' => $trip] = tripInRoute();

    for ($i = 0; $i < 12; $i++) {
        tripPositionAt($trip, now()->addSeconds($i * 15)->format('Y-m-d H:i:s'));
    }

    $response = asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$trip->id}/positions?limit=10")
        ->assertOk();

    expect($response->json())->toHaveKeys(['statusCode', 'message', 'data', 'total', 'currentPage', 'lastPage'])
        ->and($response->json('total'))->toBe(12)
        ->and($response->json('currentPage'))->toBe(1)
        ->and($response->json('lastPage'))->toBe(2)
        ->and($response->json('data'))->toHaveCount(10)
        ->and($response->json('meta'))->toBeNull();
});

it('acota el tamaño de página a [10, 100] también por HTTP', function (string $limit, int $esperado) {
    ['trip' => $trip] = tripInRoute();

    for ($i = 0; $i < 12; $i++) {
        tripPositionAt($trip, now()->addSeconds($i * 15)->format('Y-m-d H:i:s'));
    }

    $response = asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$trip->id}/positions?limit={$limit}")
        ->assertOk();

    expect($response->json('data'))->toHaveCount($esperado);
})->with([
    'por debajo del piso' => ['5', 10],
    'cero' => ['0', 10],
    'por encima del techo' => ['500', 12],
]);

it('no pagina cuando el limit no es numérico', function () {
    ['trip' => $trip] = tripInRoute();

    for ($i = 0; $i < 11; $i++) {
        tripPositionAt($trip, now()->addSeconds($i * 15)->format('Y-m-d H:i:s'));
    }

    $response = asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$trip->id}/positions?limit=abc")
        ->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(11);
});

it('devuelve cada punto con cinco claves, coordenadas string y la fecha del proyecto', function () {
    ['trip' => $trip] = tripInRoute();

    TripPosition::factory()->create([
        'trip_id' => $trip->id,
        'pilot_id' => $trip->pilot_id,
        'latitude' => 14.628074,
        'longitude' => -90.522554,
        'recorded_at' => '2026-09-07 08:14:03',
    ]);

    $punto = asUser(userWithRole(UserRole::Administrator))
        ->getJson("/api/trips/{$trip->id}/positions")
        ->assertOk()
        ->json('data.0');

    expect(array_keys($punto))->toBe(tripPositionResourceKeys())
        ->and($punto['latitude'])->toBe('14.62807400')
        ->and($punto['longitude'])->toBe('-90.52255400')
        ->and($punto['recordedAt'])->toBe('07-09-2026 08:14:03 AM')
        ->and($punto['pilotId'])->toBe($trip->pilot_id);
});

/*
|--------------------------------------------------------------------------
| Lo que no debe cambiar
|--------------------------------------------------------------------------
*/

it('no borra ninguna posición al dar de baja el viaje', function () {
    ['trip' => $trip] = tripInRoute();

    tripPositionAt($trip, '2026-09-07 08:00:00');
    tripPositionAt($trip, '2026-09-07 08:00:15');

    asUser(userWithRole(UserRole::Administrator))->deleteJson("/api/trips/{$trip->id}")->assertOk();

    expect(Trip::withTrashed()->findOrFail($trip->id)->trashed())->toBeTrue()
        ->and(TripPosition::where('trip_id', $trip->id)->count())->toBe(2);
});

it('no pinta el rastro en el listado de viajes, solo en el detalle', function () {
    ['trip' => $trip] = tripInRoute();
    tripPositionAt($trip, '2026-09-07 08:00:00');

    $admin = userWithRole(UserRole::Administrator);

    expect(asUser($admin)->getJson('/api/trips')->assertOk()->json('data.0'))->not->toHaveKey('positions')
        ->and(asUser($admin)->getJson("/api/trips/{$trip->id}")->assertOk()->json('data.positions'))->toHaveCount(1);
});

it('no publica ninguna ruta que edite o borre una posición', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();
    $position = tripPositionAt($trip, '2026-09-07 08:00:00');

    asUser($pilot)->patchJson("/api/trips/{$trip->id}/positions/{$position->id}")->assertNotFound();
    asUser($pilot)->deleteJson("/api/trips/{$trip->id}/positions/{$position->id}")->assertNotFound();

    expect(TripPosition::count())->toBe(1);
});

it('mantiene el viaje en ruta después de reportar, sin tocar sus columnas', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();
    $antes = $trip->only(['status', 'pilot_id', 'vehicle_id', 'start_date', 'end_date']);

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => now()->toIso8601String()],
    ]])->assertCreated();

    expect($trip->fresh()->only(['status', 'pilot_id', 'vehicle_id', 'start_date', 'end_date']))->toEqual($antes)
        ->and($trip->fresh()->status)->toBe(TripStatus::InRoute);
});

/**
 * El FormRequest se resuelve antes que el controlador, así que el 422 del cuerpo se
 * adelanta a las cuatro guardas del service: solo el middleware role:pilot va delante.
 * Estos dos casos fijan ese orden, que es justo el que la documentación decía al revés.
 */
it('valida el cuerpo antes que las guardas: id inexistente con cuerpo vacío es 422, no 404', function () {
    $pilot = userWithRole(UserRole::Pilot);

    asUser($pilot)->postJson('/api/trips/999999/positions', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['positions']);
});

it('valida el cuerpo antes que las guardas: viaje ajeno y fuera de ruta con cuerpo vacío es 422', function () {
    $pilot = userWithRole(UserRole::Pilot);
    $trip = Trip::factory()->create();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['positions']);

    expect(TripPosition::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| POST /api/trips/{trip}/positions — el lote (SPEC 40)
|--------------------------------------------------------------------------
*/

/**
 * A batch of points the given seconds after a base time, with the Guatemala offset the
 * way a device would send them. Each point moves north a little, so no stop opens.
 *
 * @param  list<int>  $seconds
 * @return list<array{latitude: float, longitude: float, recordedAt: string}>
 */
function tripPositionPoints(array $seconds, ?CarbonImmutable $base = null): array
{
    $base ??= CarbonImmutable::now()->subHour();

    return array_map(fn (int $offset): array => [
        'latitude' => 14.628074 + $offset / 10000,
        'longitude' => -90.522554,
        'recordedAt' => $base->addSeconds($offset)->setTimezone('America/Guatemala')->toIso8601String(),
    ], $seconds);
}

/**
 * Seconds after the base of every stored point of the trip, in id order.
 *
 * @return list<int>
 */
function tripPositionOffsets(Trip $trip, CarbonImmutable $base): array
{
    return TripPosition::query()
        ->where('trip_id', $trip->id)
        ->orderBy('id')
        ->get()
        ->map(fn (TripPosition $position): int => $position->recorded_at->timestamp - $base->timestamp)
        ->all();
}

it('rechaza con 422 el cuerpo viejo de un solo punto, señalando positions', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.628074,
        'longitude' => -90.522554,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['positions'])
        ->assertJsonFragment(['Las posiciones son obligatorias']);

    expect(TripPosition::count())->toBe(0);
});

it('rechaza con 422 un arreglo de posiciones vacío o que no es arreglo', function (mixed $positions, string $mensaje) {
    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => $positions])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['positions'])
        ->assertJsonFragment([$mensaje]);
})->with([
    'vacío' => [[], 'Las posiciones son obligatorias'],
    'no es arreglo' => ['muchas', 'Las posiciones deben enviarse como un arreglo'],
]);

it('rechaza con 422 un lote de 1001 puntos', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'positions' => tripPositionPoints(range(0, 5000, 5), CarbonImmutable::now()->subHours(2)),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['positions'])
        ->assertJsonFragment(['No puedes enviar más de 1000 posiciones por petición']);

    expect(TripPosition::count())->toBe(0);
});

it('acepta un lote de 1000 puntos', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'positions' => tripPositionPoints(range(0, 4995, 5), CarbonImmutable::now()->subHours(2)),
    ])
        ->assertCreated()
        ->assertJsonPath('data.received', 1000)
        ->assertJsonPath('data.saved', 1000)
        ->assertJsonPath('data.discarded', 0);

    expect(TripPosition::count())->toBe(1000);
});

it('rechaza con 422 el lote entero si un punto de en medio no trae latitud, nombrando ese punto', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    $points = tripPositionPoints(range(0, 95, 5));
    unset($points[9]['latitude']);

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => $points])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['positions.9.latitude'])
        ->assertJsonFragment(['La latitud del punto 10 es obligatoria']);

    expect(TripPosition::count())->toBe(0);
});

it('rechaza con 422 un recordedAt sin zona horaria o con otro formato', function (string $recordedAt) {
    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => $recordedAt],
    ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['positions.0.recordedAt'])
        ->assertJsonFragment(['La hora del punto 1 debe estar en formato ISO 8601 con zona horaria']);

    expect(TripPosition::count())->toBe(0);
})->with([
    'sin zona' => '2026-10-06T14:32:05',
    'con espacio en vez de T' => '2026-10-06 14:32:05-06:00',
    'formato del proyecto' => '06-10-2026 02:32:05 PM',
]);

it('rechaza con 422 un punto sin recordedAt', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554],
    ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['positions.0.recordedAt'])
        ->assertJsonFragment(['La hora del punto 1 es obligatoria']);
});

it('acepta recordedAt con milisegundos y Z, y con desfase sin milisegundos', function (string $format) {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    $recordedAt = now()->subMinutes(10)->startOfSecond();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => $format === 'Z'
            ? $recordedAt->copy()->utc()->format('Y-m-d\TH:i:s').'.123Z'
            : $recordedAt->copy()->setTimezone('America/Guatemala')->format('Y-m-d\TH:i:sP')],
    ]])->assertCreated();

    /** Los milisegundos se truncan: la columna guarda segundos. */
    expect(TripPosition::sole()->recorded_at->timestamp)->toBe($recordedAt->timestamp);
})->with(['Z', 'desfase']);

it('rechaza con 422 un punto a más de un minuto en el futuro y acepta uno a 30 segundos', function (int $seconds, int $status) {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    $response = asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => [
        ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => now()->addSeconds($seconds)->toIso8601String()],
    ]])->assertStatus($status);

    if ($status === 422) {
        $response->assertJsonValidationErrors(['positions.0.recordedAt'])
            ->assertJsonFragment(['La hora del punto 1 no puede estar en el futuro']);
    }

    expect(TripPosition::count())->toBe($status === 201 ? 1 : 0);
})->with([
    'a 61 segundos' => [61, 422],
    'a 30 segundos' => [30, 201],
]);

it('rechaza con 400 el lote entero si un punto es anterior al inicio del viaje', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute(['start_date' => now()->subHour()]);

    $points = tripPositionPoints([0, 6], CarbonImmutable::now()->subMinutes(30));
    $points[] = ['latitude' => 14.6, 'longitude' => -90.5, 'recordedAt' => now()->subHours(2)->toIso8601String()];

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", ['positions' => $points])
        ->assertBadRequest()
        ->assertJsonPath('message', 'La hora de un punto es anterior al inicio del viaje');

    expect(TripPosition::count())->toBe(0);
    Event::assertNotDispatched(TripPositionUpdated::class);
});

it('rechaza con 400 un lote sobre un viaje finalizado sin guardar nada', function () {
    $trip = Trip::factory()->finished()->create();
    $pilot = User::findOrFail($trip->pilot_id);

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'positions' => tripPositionPoints([0, 6, 12]),
    ])
        ->assertBadRequest()
        ->assertJsonPath('message', 'El viaje no está en ruta');

    expect(TripPosition::count())->toBe(0);
});

it('guarda en orden de recordedAt un lote enviado desordenado', function () {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();
    $base = CarbonImmutable::now()->subHour();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'positions' => tripPositionPoints([30, 0, 20, 10], $base),
    ])->assertCreated();

    expect(tripPositionOffsets($trip, $base))->toBe([0, 10, 20, 30]);
});

it('guarda 0, 6 y 12 s y descarta el de 3 s por el piso', function () {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();
    $base = CarbonImmutable::now()->subHour();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'positions' => tripPositionPoints([0, 3, 6, 12], $base),
    ])
        ->assertCreated()
        ->assertJsonPath('data.received', 4)
        ->assertJsonPath('data.saved', 3)
        ->assertJsonPath('data.discarded', 1);

    expect(tripPositionOffsets($trip, $base))->toBe([0, 6, 12]);
});

it('descarta el primer punto de un lote si cae a menos de 5 s del último guardado', function () {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();
    $base = CarbonImmutable::now()->subHour();

    tripPositionAt($trip, $base->toDateTimeString());

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'positions' => tripPositionPoints([3, 9], $base),
    ])
        ->assertCreated()
        ->assertJsonPath('data.saved', 1)
        ->assertJsonPath('data.discarded', 1);

    expect(tripPositionOffsets($trip, $base))->toBe([0, 9]);
});

it('descarta los puntos anteriores o iguales al último guardado y los cuenta', function () {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();
    $base = CarbonImmutable::now()->subHour();

    tripPositionAt($trip, $base->addSeconds(30)->toDateTimeString());

    $response = asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'positions' => tripPositionPoints([0, 15, 30, 45, 60], $base),
    ])
        ->assertCreated()
        ->assertJsonPath('data.received', 5)
        ->assertJsonPath('data.saved', 2)
        ->assertJsonPath('data.discarded', 3);

    expect(tripPositionOffsets($trip, $base))->toBe([30, 45, 60])
        ->and($response->json('data.received'))->toBe($response->json('data.saved') + $response->json('data.discarded'));
});

it('responde 200 sin crear filas al reenviar el mismo lote, con el último punto ya guardado', function () {
    Event::fake([TripPositionUpdated::class]);
    $this->freezeTime();

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();
    $body = ['positions' => tripPositionPoints([0, 6, 12])];

    $primero = asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", $body)->assertCreated();

    $segundo = asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", $body)
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Posiciones recibidas correctamente')
        ->assertJsonPath('data.received', 3)
        ->assertJsonPath('data.saved', 0)
        ->assertJsonPath('data.discarded', 3);

    expect(TripPosition::count())->toBe(3)
        ->and(array_keys($segundo->json('data')))->toBe(tripPositionBatchKeys())
        ->and($segundo->json('data.lastPosition'))->toBe($primero->json('data.lastPosition'))
        ->and(array_keys($segundo->json('data.lastPosition')))->toBe(tripPositionResourceKeys());

    /** El reintento no escribió nada, así que tampoco emitió. */
    Event::assertDispatchedTimes(TripPositionUpdated::class, 1);
});

it('emite un solo evento por un lote de 17 puntos escritos, con el último', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    $response = asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'positions' => tripPositionPoints(range(0, 80, 5)),
    ])
        ->assertCreated()
        ->assertJsonPath('data.saved', 17);

    $lastId = $response->json('data.lastPosition.id');

    expect($lastId)->toBe(TripPosition::query()->max('id'));

    Event::assertDispatchedTimes(TripPositionUpdated::class, 1);
    Event::assertDispatched(TripPositionUpdated::class, fn (TripPositionUpdated $event): bool => $event->position->id === $lastId);
});

it('no guarda ningún punto ni ninguna parada si la detección falla a mitad del lote', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    app()->instance(TripTimeoutServiceInterface::class, new class implements TripTimeoutServiceInterface
    {
        private int $calls = 0;

        public function getTimeouts(User $user, int $tripId, array $filters): LengthAwarePaginator|Collection
        {
            return new Collection;
        }

        public function trackPosition(TripPosition $position): void
        {
            /** El primero sí abre una parada: el rollback tiene que deshacerla también. */
            if (++$this->calls === 1) {
                TripTimeout::factory()->open()->create(['trip_id' => $position->trip_id]);

                return;
            }

            throw new RuntimeException('La detección falló');
        }
    });

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'positions' => tripPositionPoints([0, 6, 12]),
    ])->assertStatus(500);

    expect(TripPosition::count())->toBe(0)
        ->and(TripTimeout::count())->toBe(0);

    Event::assertNotDispatched(TripPositionUpdated::class);
});
