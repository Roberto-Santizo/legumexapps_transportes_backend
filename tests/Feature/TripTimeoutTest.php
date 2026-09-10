<?php

use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripPosition;
use App\Models\TripTimeout;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

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
 * took it.
 *
 * @return array{trip: Trip, pilot: User, owner: User}
 */
function tripWithTimeouts(): array
{
    $trip = Trip::factory()->inRoute()->create();

    return [
        'trip' => $trip,
        'pilot' => User::findOrFail($trip->pilot_id),
        'owner' => User::findOrFail($trip->assigned_by),
    ];
}

/**
 * Seed the given trip with stops, reusing a single pair of positions.
 *
 * The factory would build two `trip_positions` rows —and a trip of its own— for every
 * stop, which turns a hundred rows into a slow test for no gain: the listing never
 * joins them. The anchor is a real point of this very trip all the same, so the row
 * stays indistinguishable from one the detection would have written.
 *
 * @param  array<string, mixed>  $attributes
 * @return Collection<int, TripTimeout>
 */
function seedTripTimeouts(Trip $trip, int $count, array $attributes = []): Collection
{
    /** Un viaje de la bolsa no tiene piloto, y la parada exige uno: se le presta cualquiera. */
    $pilotId = $trip->pilot_id ?? userWithRole(UserRole::Pilot)->id;

    $anchor = TripPosition::factory()->create([
        'trip_id' => $trip->id,
        'pilot_id' => $pilotId,
        'recorded_at' => now()->subMinutes(30),
    ]);

    $closingPosition = TripPosition::factory()->create([
        'trip_id' => $trip->id,
        'pilot_id' => $pilotId,
        'recorded_at' => now(),
    ]);

    return TripTimeout::factory()->count($count)->create(array_merge([
        'trip_id' => $trip->id,
        'pilot_id' => $pilotId,
        'start_position_id' => $anchor->id,
        'end_position_id' => $closingPosition->id,
        'latitude' => $anchor->latitude,
        'longitude' => $anchor->longitude,
        'started_at' => $anchor->recorded_at,
        'ended_at' => $closingPosition->recorded_at,
    ], $attributes));
}

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

it('rechaza la consulta de paradas sin token', function () {
    $this->getJson('/api/trips/1/timeouts')
        ->assertStatus(401)
        ->assertJsonPath('message', 'El token de sesión no es válido o ha expirado');
});

it('rechaza a cualquier piloto, incluido el asignado al viaje', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripWithTimeouts();

    TripTimeout::factory()->create(['trip_id' => $trip->id]);

    asUser($pilot)->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertStatus(403)
        ->assertJsonPath('message', 'No tienes permisos para consultar las paradas de un viaje');
});

/*
|--------------------------------------------------------------------------
| Ámbito
|--------------------------------------------------------------------------
*/

it('deja al administrador y al gerente leer las paradas de cualquier viaje', function (UserRole $role) {
    ['trip' => $trip] = tripWithTimeouts();

    TripTimeout::factory()->create(['trip_id' => $trip->id]);

    asUser(userWithRole($role))->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertStatus(200)
        ->assertJsonPath('message', 'Paradas obtenidas correctamente')
        ->assertJsonCount(1, 'data');
})->with([
    'administrator' => UserRole::Administrator,
    'manager' => UserRole::Manager,
]);

it('deja al transportista leer las paradas de un viaje que asignó su empresa', function () {
    ['trip' => $trip, 'owner' => $owner] = tripWithTimeouts();

    TripTimeout::factory()->create(['trip_id' => $trip->id]);

    asUser($owner)->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertStatus(200)
        ->assertJsonCount(1, 'data');
});

/*
|--------------------------------------------------------------------------
| Lectura
|--------------------------------------------------------------------------
*/

it('devuelve las paradas ordenadas por su hora de inicio ascendente', function () {
    ['trip' => $trip] = tripWithTimeouts();

    $second = TripTimeout::factory()->create(['trip_id' => $trip->id, 'started_at' => now()->subHour()]);
    $first = TripTimeout::factory()->create(['trip_id' => $trip->id, 'started_at' => now()->subHours(4)]);

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertStatus(200)
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.1.id', $second->id);
});

it('devuelve 200 con data vacío para un viaje sin paradas', function () {
    ['trip' => $trip] = tripWithTimeouts();

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');
});

it('devuelve las nueve claves del recurso', function () {
    ['trip' => $trip] = tripWithTimeouts();

    TripTimeout::factory()->create(['trip_id' => $trip->id]);

    $response = asUser(userWithRole(UserRole::Administrator))
        ->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertStatus(200);

    expect(array_keys($response->json('data.0')))->toBe([
        'id',
        'latitude',
        'longitude',
        'startedAt',
        'endedAt',
        'durationMinutes',
        'pilotId',
        'startPositionId',
        'endPositionId',
    ]);
});

/*
|--------------------------------------------------------------------------
| Cierre por fin de viaje
|--------------------------------------------------------------------------
*/

it('cierra la parada abierta al finalizar el viaje y deja el punto de cierre en null', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripWithTimeouts();

    $timeout = TripTimeout::factory()->open()->create(['trip_id' => $trip->id]);

    asUser($pilot)->patchJson("/api/trips/{$trip->id}/finish")->assertStatus(200);

    $timeout->refresh();

    expect($timeout->ended_at)->not->toBeNull()
        ->and($timeout->end_position_id)->toBeNull();
});

it('no escribe nada en las paradas al finalizar un viaje que no tenía ninguna abierta', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripWithTimeouts();

    $closed = TripTimeout::factory()->create(['trip_id' => $trip->id]);
    $endedAt = $closed->ended_at;
    $endPositionId = $closed->end_position_id;

    asUser($pilot)->patchJson("/api/trips/{$trip->id}/finish")->assertStatus(200);

    $closed->refresh();

    expect(TripTimeout::where('trip_id', $trip->id)->count())->toBe(1)
        ->and($closed->ended_at->toDateTimeString())->toBe($endedAt->toDateTimeString())
        ->and($closed->end_position_id)->toBe($endPositionId);
});

it('no toca ninguna parada al iniciar el viaje', function () {
    $trip = Trip::factory()->assigned()->create();
    $pilot = User::findOrFail($trip->pilot_id);

    $timeout = TripTimeout::factory()->open()->create(['trip_id' => $trip->id]);

    asUser($pilot)->patchJson("/api/trips/{$trip->id}/start")->assertStatus(200);

    expect($timeout->refresh()->ended_at)->toBeNull();
});

it('no toca ninguna parada con el PATCH general ni con el DELETE del administrador', function () {
    ['trip' => $trip] = tripWithTimeouts();

    $timeout = TripTimeout::factory()->open()->create(['trip_id' => $trip->id]);
    $administrator = userWithRole(UserRole::Administrator);

    asUser($administrator)->patchJson("/api/trips/{$trip->id}", ['observations' => 'Revisión en ruta'])
        ->assertStatus(200);

    expect($timeout->refresh()->ended_at)->toBeNull();

    asUser($administrator)->deleteJson("/api/trips/{$trip->id}")->assertStatus(200);

    /** La baja lógica del viaje no se lleva por delante su rastro ni sus paradas. */
    expect($timeout->refresh()->ended_at)->toBeNull()
        ->and(TripTimeout::where('trip_id', $trip->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Detección por el POST de posiciones
|--------------------------------------------------------------------------
|
| Se viaja en el tiempo entre peticiones porque el piso de 15 segundos de SPEC 26
| descartaría la segunda y la tercera, y un punto descartado no abre ni cierra nada.
|
*/

it('abre y luego cierra una parada a partir de las posiciones que reporta el piloto', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripWithTimeouts();
    $administrator = userWithRole(UserRole::Administrator);

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.6282,
        'longitude' => -90.5229,
    ])->assertStatus(201);

    $this->travel(20)->seconds();

    /** Dos metros escasos: el camión está parado y la parada se abre en el punto anterior. */
    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.62822,
        'longitude' => -90.5229,
    ])->assertStatus(201);

    $open = asUser($administrator)->getJson("/api/trips/{$trip->id}/timeouts")->json('data');

    expect($open)->toHaveCount(1)
        ->and($open[0]['endedAt'])->toBeNull()
        ->and($open[0]['durationMinutes'])->toBeNull()
        ->and($open[0]['latitude'])->toBe('14.62820000');

    $this->travel(20)->seconds();

    /** Más de cien metros: el camión arrancó y el punto cierra la parada. */
    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.6292,
        'longitude' => -90.5229,
    ])->assertStatus(201);

    $closed = asUser($administrator)->getJson("/api/trips/{$trip->id}/timeouts")->json('data');

    expect($closed)->toHaveCount(1)
        ->and($closed[0]['endedAt'])->not->toBeNull()
        ->and($closed[0]['endPositionId'])->not->toBeNull()
        ->and($closed[0]['durationMinutes'])->toBeGreaterThan(0);
});

it('no toca ninguna parada cuando el piso de quince segundos descarta la petición', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripWithTimeouts();

    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.6282,
        'longitude' => -90.5229,
    ])->assertStatus(201);

    /** Sin viajar en el tiempo: la segunda llega dentro del piso y se descarta. */
    asUser($pilot)->postJson("/api/trips/{$trip->id}/positions", [
        'latitude' => 14.62822,
        'longitude' => -90.5229,
    ])->assertStatus(200);

    expect(TripTimeout::where('trip_id', $trip->id)->count())->toBe(0);
});

it('acota el tamaño de página a diez y aplana los metadatos en la raíz', function () {
    ['trip' => $trip] = tripWithTimeouts();

    TripTimeout::factory()->count(12)->create(['trip_id' => $trip->id]);

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$trip->id}/timeouts?limit=3")
        ->assertStatus(200)
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('total', 12)
        ->assertJsonPath('currentPage', 1);
});

/*
|--------------------------------------------------------------------------
| Ámbito: la bolsa libre y la empresa ajena
|--------------------------------------------------------------------------
|
| El GET no lleva role:, así que quién alcanza qué viaje lo decide el ámbito de
| SPEC 24 dentro del service, y fuera de ámbito es 403 —el viaje existe y se publicó—,
| nunca el 404 de un viaje inexistente.
|
*/

it('deja al transportista leer las paradas de un viaje de la bolsa libre', function () {
    /** Pendiente y sin tripulación: la bolsa está a la vista de cualquier empresa. */
    $trip = Trip::factory()->create();

    seedTripTimeouts($trip, 1);

    asUser(Carrier::factory()->create()->owner)->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertOk()
        ->assertJsonPath('message', 'Paradas obtenidas correctamente')
        ->assertJsonCount(1, 'data');
});

it('rechaza con 403 al transportista de una empresa que no asignó el viaje', function () {
    ['trip' => $trip] = tripWithTimeouts();

    seedTripTimeouts($trip, 1);

    asUser(Carrier::factory()->create()->owner)->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No puedes acceder a un viaje que no pertenece a tu empresa transportista',
            'data' => null,
        ]);
});

it('rechaza con 404 y el mismo mensaje las paradas de un viaje inexistente o borrado', function (bool $borrado) {
    $tripId = $borrado
        ? Trip::factory()->inRoute()->trashed()->create()->id
        : 99999;

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$tripId}/timeouts")
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El viaje no existe',
            'data' => null,
        ]);
})->with([
    'inexistente' => false,
    'borrado' => true,
]);

/*
|--------------------------------------------------------------------------
| Paginación opt-in
|--------------------------------------------------------------------------
*/

it('devuelve todas las paradas y ninguna clave de paginación sin limit', function () {
    ['trip' => $trip] = tripWithTimeouts();

    seedTripTimeouts($trip, 12);

    $response = asUser(userWithRole(UserRole::Administrator))
        ->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12);
});

it('acota el tamaño de página a cien cuando el limit se pasa de rosca', function () {
    ['trip' => $trip] = tripWithTimeouts();

    seedTripTimeouts($trip, 101);

    $response = asUser(userWithRole(UserRole::Administrator))
        ->getJson("/api/trips/{$trip->id}/timeouts?limit=500")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(100)
        ->and($response->json('total'))->toBe(101)
        ->and($response->json('currentPage'))->toBe(1)
        ->and($response->json('lastPage'))->toBe(2)
        ->and($response->json('meta'))->toBeNull();
});

it('ignora un limit no numérico y devuelve el listado completo', function () {
    ['trip' => $trip] = tripWithTimeouts();

    seedTripTimeouts($trip, 12);

    $response = asUser(userWithRole(UserRole::Administrator))
        ->getJson("/api/trips/{$trip->id}/timeouts?limit=abc")
        ->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12);
});

it('ignora cualquier query param que no sea limit', function () {
    ['trip' => $trip] = tripWithTimeouts();

    seedTripTimeouts($trip, 12);

    /** Ni filtros ni orden configurable: SPEC 27 los dejó fuera y no dan 422, se ignoran. */
    $response = asUser(userWithRole(UserRole::Administrator))
        ->getJson("/api/trips/{$trip->id}/timeouts?open=true&dateFrom=2026-01-01&sortDir=desc")
        ->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12);
});

/*
|--------------------------------------------------------------------------
| Forma del recurso sobre la respuesta HTTP
|--------------------------------------------------------------------------
|
| TripTimeoutResourceTest ya prueba el cálculo sobre un modelo en memoria; aquí se
| comprueba que lo mismo sobrevive al viaje de ida y vuelta a la base y al sobre.
|
*/

it('saca las coordenadas como string de ocho decimales y las horas en d-m-Y h:i:s A', function () {
    ['trip' => $trip] = tripWithTimeouts();

    seedTripTimeouts($trip, 1, [
        'latitude' => '14.6282',
        'longitude' => '-90.5229',
        'started_at' => '2026-09-10 08:14:00',
        'ended_at' => '2026-09-10 08:41:30',
    ]);

    $stop = asUser(userWithRole(UserRole::Administrator))
        ->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertOk()
        ->json('data.0');

    expect($stop['latitude'])->toBe('14.62820000')
        ->and($stop['longitude'])->toBe('-90.52290000')
        ->and($stop['startedAt'])->toBe('10-09-2026 08:14:00 AM')
        ->and($stop['endedAt'])->toBe('10-09-2026 08:41:30 AM');
});

it('devuelve 27.5 minutos para una parada de veintisiete minutos y medio', function () {
    ['trip' => $trip] = tripWithTimeouts();

    seedTripTimeouts($trip, 1, [
        'started_at' => '2026-09-10 08:14:00',
        'ended_at' => '2026-09-10 08:41:30',
    ]);

    $stop = asUser(userWithRole(UserRole::Administrator))
        ->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertOk()
        ->json('data.0');

    expect($stop['durationMinutes'])->toBe(27.5);
});

it('devuelve la hora de cierre y la duración en null para una parada abierta', function () {
    ['trip' => $trip] = tripWithTimeouts();

    seedTripTimeouts($trip, 1, ['ended_at' => null, 'end_position_id' => null]);

    $stop = asUser(userWithRole(UserRole::Administrator))
        ->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertOk()
        ->json('data.0');

    expect($stop['endedAt'])->toBeNull()
        ->and($stop['durationMinutes'])->toBeNull()
        ->and($stop['endPositionId'])->toBeNull()
        ->and($stop['startedAt'])->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Lo que no debe cambiar (SPEC 26)
|--------------------------------------------------------------------------
*/

it('deja el rastro de posiciones respondiendo exactamente igual que antes', function () {
    ['trip' => $trip] = tripWithTimeouts();

    TripPosition::factory()->create([
        'trip_id' => $trip->id,
        'pilot_id' => $trip->pilot_id,
    ]);

    /** Una aserción de humo: el detalle del rastro es cosa de TripPositionTest. */
    $response = asUser(userWithRole(UserRole::Administrator))
        ->getJson("/api/trips/{$trip->id}/positions")
        ->assertOk()
        ->assertJsonPath('message', 'Posiciones obtenidas correctamente');

    expect(array_keys($response->json('data.0')))
        ->toBe(['id', 'latitude', 'longitude', 'recordedAt', 'pilotId']);
});
