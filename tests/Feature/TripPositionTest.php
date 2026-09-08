<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Events\Trip\TripPositionUpdated;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripPosition;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
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

    asUser(userWithRole($role))->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.628074,
        'longitude' => -90.522554,
    ])
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

    $response = asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.628074,
        'longitude' => -90.522554,
    ])
        ->assertCreated()
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Posición registrada correctamente');

    $this->assertDatabaseHas('trip_positions', [
        'trip_id' => $trip->id,
        'pilot_id' => $pilot->id,
        'latitude' => '14.62807400',
        'longitude' => '-90.52255400',
    ]);

    expect($response->json('data.pilotId'))->toBe($pilot->id)
        ->and(array_keys($response->json('data')))->toBe(tripPositionResourceKeys());

    Event::assertDispatched(TripPositionUpdated::class);
});

it('pone la hora del servidor, no la del dispositivo', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    $this->travelTo(now()->setDate(2026, 9, 7)->setTime(8, 14, 3));

    $response = asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.628074,
        'longitude' => -90.522554,
    ])->assertCreated();

    expect($response->json('data.recordedAt'))->toBe('07-09-2026 08:14:03 AM')
        ->and(TripPosition::firstOrFail()->recorded_at->format('d-m-Y H:i:s'))->toBe('07-09-2026 08:14:03');
});

it('ignora recordedAt y pilotId mandados en el cuerpo', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();
    $otroPiloto = userWithRole(UserRole::Pilot);

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.628074,
        'longitude' => -90.522554,
        'recordedAt' => '01-01-2000 00:00:00 AM',
        'recorded_at' => '2000-01-01 00:00:00',
        'pilotId' => $otroPiloto->id,
        'pilot_id' => $otroPiloto->id,
    ])->assertCreated();

    $position = TripPosition::firstOrFail();

    expect($position->pilot_id)->toBe($pilot->id)
        ->and($position->recorded_at->year)->toBe(now()->year);
});

it('rechaza con 403 al piloto que no tiene el viaje asignado', function () {
    ['trip' => $trip] = tripInRoute();

    asUser(userWithRole(UserRole::Pilot))->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.628074,
        'longitude' => -90.522554,
    ])
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes reportar la posición de un viaje que no tienes asignado');

    expect(TripPosition::count())->toBe(0);
});

it('rechaza con 400 reportar sobre un viaje que no está en ruta', function (string $estado) {
    $trip = Trip::factory()->{$estado}()->create();
    $pilot = User::findOrFail($trip->pilot_id);

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.628074,
        'longitude' => -90.522554,
    ])
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

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.628074,
        'longitude' => -90.522554,
    ])
        ->assertBadRequest()
        ->assertJsonPath('message', 'El viaje ya fue eliminado');

    expect(TripPosition::count())->toBe(0);
});

it('rechaza con 404 reportar sobre un viaje que no existe', function () {
    asUser(userWithRole(UserRole::Pilot))->postJson('/api/trips/99999/positions', [
        'latitude' => 14.628074,
        'longitude' => -90.522554,
    ])
        ->assertNotFound()
        ->assertJsonPath('message', 'El viaje no existe');
});

it('rechaza con 422 una coordenada fuera del sistema de referencia', function (array $payload, string $campo, string $mensaje) {
    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo])
        ->assertJsonFragment([$mensaje]);

    expect(TripPosition::count())->toBe(0);
})->with([
    'latitud por encima de 90' => [['latitude' => 91, 'longitude' => -90.5], 'latitude', 'La latitud debe estar entre -90 y 90'],
    'latitud por debajo de -90' => [['latitude' => -91, 'longitude' => -90.5], 'latitude', 'La latitud debe estar entre -90 y 90'],
    'longitud por encima de 180' => [['latitude' => 14.6, 'longitude' => 181], 'longitude', 'La longitud debe estar entre -180 y 180'],
    'longitud por debajo de -180' => [['latitude' => 14.6, 'longitude' => -181], 'longitude', 'La longitud debe estar entre -180 y 180'],
    'latitud no numérica' => [['latitude' => 'norte', 'longitude' => -90.5], 'latitude', 'La latitud debe ser un número'],
    'longitud no numérica' => [['latitude' => 14.6, 'longitude' => 'oeste'], 'longitude', 'La longitud debe ser un número'],
]);

it('rechaza con 422 el cuerpo vacío señalando las dos coordenadas', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['latitude', 'longitude'])
        ->assertJsonFragment(['La latitud es obligatoria'])
        ->assertJsonFragment(['La longitud es obligatoria']);

    expect(TripPosition::count())->toBe(0);
});

it('devuelve 200 con el punto anterior y no escribe nada antes de los quince segundos', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    $primero = asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.628074,
        'longitude' => -90.522554,
    ])->assertCreated();

    $this->travel(14)->seconds();

    $segundo = asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 15.111111,
        'longitude' => -91.222222,
    ])
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Posición recibida correctamente');

    expect(TripPosition::count())->toBe(1)
        ->and($segundo->json('data.id'))->toBe($primero->json('data.id'))
        ->and($segundo->json('data.latitude'))->toBe('14.62807400');

    /** El punto descartado no se anuncia: solo se emitió el primero. */
    Event::assertDispatchedTimes(TripPositionUpdated::class, 1);
});

it('registra y emite el segundo punto pasados los quince segundos', function () {
    Event::fake([TripPositionUpdated::class]);

    ['trip' => $trip, 'pilot' => $pilot] = tripInRoute();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.628074,
        'longitude' => -90.522554,
    ])->assertCreated();

    $this->travel(16)->seconds();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 15.111111,
        'longitude' => -91.222222,
    ])->assertCreated();

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

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.628074,
        'longitude' => -90.522554,
    ])
        ->assertCreated()
        ->assertJsonPath('message', 'Posición registrada correctamente');

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

it('no expone una relación positions() en el viaje', function () {
    expect(method_exists(Trip::class, 'positions'))->toBeFalse();
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

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.628074,
        'longitude' => -90.522554,
    ])->assertCreated();

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
        ->assertJsonValidationErrors(['latitude', 'longitude']);
});

it('valida el cuerpo antes que las guardas: viaje ajeno y fuera de ruta con cuerpo vacío es 422', function () {
    $pilot = userWithRole(UserRole::Pilot);
    $trip = Trip::factory()->create();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['latitude', 'longitude']);

    expect(TripPosition::count())->toBe(0);
});
