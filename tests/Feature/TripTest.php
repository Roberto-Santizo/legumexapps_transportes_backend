<?php

use App\Enums\LocationType;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Enums\VehicleStatus;
use App\Models\Carrier;
use App\Models\Client;
use App\Models\DeparturePoint;
use App\Models\Location;
use App\Models\ShippingLine;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Place\PolylineDecoder;
use Database\Factories\TripFactory;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Every trips endpoint as method and URI, for the middleware datasets.
 *
 * Eight: the five of the apiResource plus the three fixed action routes, declared
 * before it so the {trip} wildcard does not swallow them.
 *
 * @return array<string, array{string, string}>
 */
function tripEndpoints(): array
{
    return [
        'index' => ['GET', '/api/trips'],
        'store' => ['POST', '/api/trips'],
        'show' => ['GET', '/api/trips/1'],
        'update' => ['PATCH', '/api/trips/1'],
        'destroy' => ['DELETE', '/api/trips/1'],
        'assignment' => ['PATCH', '/api/trips/1/assignment'],
        'start' => ['PATCH', '/api/trips/1/start'],
        'finish' => ['PATCH', '/api/trips/1/finish'],
    ];
}

/**
 * The three endpoints restricted to the administrator.
 *
 * @return array<string, array{string, string}>
 */
function tripWriteEndpoints(): array
{
    return [
        'store' => ['POST', '/api/trips'],
        'update' => ['PATCH', '/api/trips/1'],
        'destroy' => ['DELETE', '/api/trips/1'],
    ];
}

/**
 * The two execution marks, reserved for the assigned pilot.
 *
 * @return array<string, array{string, string}>
 */
function tripPilotEndpoints(): array
{
    return [
        'start' => ['PATCH', '/api/trips/1/start'],
        'finish' => ['PATCH', '/api/trips/1/finish'],
    ];
}

/**
 * The roles that never reach the administrator's writing endpoints.
 *
 * @return array<string, UserRole>
 */
function tripNonAdminRoles(): array
{
    return [
        'carrier' => UserRole::Carrier,
        'pilot' => UserRole::Pilot,
        'manager' => UserRole::Manager,
    ];
}

/**
 * The roles that never reach `PATCH /{trip}/assignment`.
 *
 * @return array<string, UserRole>
 */
function tripNonAssignerRoles(): array
{
    return [
        'administrator' => UserRole::Administrator,
        'manager' => UserRole::Manager,
        'pilot' => UserRole::Pilot,
    ];
}

/**
 * The roles that never reach `/start` nor `/finish`.
 *
 * @return array<string, UserRole>
 */
function tripNonPilotRoles(): array
{
    return [
        'administrator' => UserRole::Administrator,
        'carrier' => UserRole::Carrier,
        'manager' => UserRole::Manager,
    ];
}

/**
 * Every role of the project: all four list the trips, each one within its own scope.
 *
 * @return array<string, UserRole>
 */
function tripReaderRoles(): array
{
    return tripNonAdminRoles() + ['administrator' => UserRole::Administrator];
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
 * Build a whole carrier company: its owner, a pilot linked through `carrier_pilots`
 * and an active vehicle of its own.
 *
 * The three of them are what an assignment needs, and the pilot and the vehicle have
 * to belong to the same company or the service refuses the crew.
 *
 * @return array{carrier: Carrier, owner: User, pilot: User, vehicle: Vehicle}
 */
function tripTeam(): array
{
    $carrier = Carrier::factory()->create();
    $pilot = User::factory()->create(['role' => UserRole::Pilot]);
    $carrier->pilots()->attach($pilot);

    return [
        'carrier' => $carrier,
        'owner' => $carrier->owner,
        'pilot' => $pilot,
        'vehicle' => Vehicle::factory()->create(['carrier_id' => $carrier->id]),
    ];
}

/**
 * A trip already taken by the given team, exactly as `/assignment` leaves it.
 *
 * @param  array{carrier: Carrier, owner: User, pilot: User, vehicle: Vehicle}  $team
 * @param  array<string, mixed>  $attributes
 */
function tripAssignedTo(array $team, array $attributes = []): Trip
{
    return Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
        ...$attributes,
    ]);
}

/**
 * A valid store payload with every field `StoreTripRequest` declares as required.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tripPayload(array $overrides = []): array
{
    return array_merge([
        'order' => 'ord-2026-0001',
        'clientId' => Client::factory()->create()->id,
        'shippingLineId' => ShippingLine::factory()->create()->id,
        'departurePointId' => DeparturePoint::factory()->create()->id,
        'locationId' => Location::factory()->port()->active()->create()->id,
        'destination' => 'Rotterdam, Países Bajos',
        'container' => 'msku 123456 7',
        'transport' => 'Rastra 40 pies',
        'recolectionDate' => now()->addDays(3)->startOfSecond()->format('Y-m-d H:i:s'),
        'shipDate' => now()->addDays(5)->startOfSecond()->format('Y-m-d H:i:s'),
        'polyline' => TripFactory::POLYLINE,
        'observations' => 'Cargar a primera hora',
    ], $overrides);
}

/**
 * The 31 keys `TripResource` promises, in the order the resource declares them.
 *
 * The largest resource of the project: the six relations go out flat, as an id plus
 * its name, and `points` is derived from `polyline` on every read.
 *
 * @return array<int, string>
 */
function tripResourceKeys(): array
{
    return [
        'id', 'order', 'status',
        'clientId', 'clientName',
        'shippingLineId', 'shippingLineName',
        'departurePointId', 'departurePointName',
        'locationId', 'locationName',
        'destination', 'container', 'transport',
        'recolectionDate', 'shipDate', 'startDate', 'endDate',
        'polyline', 'points', 'observations',
        'pilotId', 'pilotName',
        'vehicleId', 'vehiclePlate',
        'assignedById', 'assignedByName', 'registeredByName',
        'createdAt', 'updatedAt', 'deletedAt',
    ];
}

/**
 * The 15 keys `TripListResource` promises, in the order the resource declares them.
 *
 * El listado es una vista de tabla: no trae los ids de las relaciones, ni `clientName`,
 * ni `destination`, ni `transport`, ni `polyline`, ni `points`, ni las tres marcas de
 * tiempo de la fila. Para todo eso está el detalle.
 *
 * @return array<int, string>
 */
function tripListResourceKeys(): array
{
    return [
        'id', 'order', 'status',
        'shippingLineName', 'departurePointName', 'locationName',
        'container',
        'recolectionDate', 'shipDate', 'startDate', 'endDate',
        'observations',
        'pilotName', 'vehiclePlate', 'registeredByName',
    ];
}

/**
 * La expresión del formato de fecha `d-m-Y h:i:s A` que promete el TripResource.
 */
function tripDatePattern(): string
{
    return '/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} (AM|PM)$/';
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth, role y carrier.required
|--------------------------------------------------------------------------
*/

it('rechaza con 401 cualquiera de las ocho rutas de viajes sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(tripEndpoints());

it('rechaza con 403 a quien no es administrador en el alta, la edición y la baja', function (string $method, string $uri, UserRole $role) {
    asUser(userWithRole($role))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(tripWriteEndpoints())->with(tripNonAdminRoles());

it('rechaza con 403 la asignación a todo rol que no sea transportista', function (UserRole $role) {
    asUser(userWithRole($role))->patchJson('/api/trips/1/assignment', ['pilotId' => 1, 'vehicleId' => 1])
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(tripNonAssignerRoles());

it('rechaza con 403 la asignación de un transportista sin empresa, por carrier.required', function () {
    $carrier = userWithRole(UserRole::Carrier);

    expect($carrier->currentCarrier())->toBeNull();

    asUser($carrier)->patchJson('/api/trips/1/assignment', ['pilotId' => 1, 'vehicleId' => 1])
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'Debes estar vinculado a un transportista para acceder a este recurso',
            'data' => null,
        ]);
});

it('rechaza con 403 el inicio y el cierre a todo rol que no sea piloto', function (string $method, string $uri, UserRole $role) {
    asUser(userWithRole($role))->json($method, $uri)
        ->assertForbidden()
        ->assertJsonPath('message', 'No tienes permisos para acceder a este recurso');
})->with(tripPilotEndpoints())->with(tripNonPilotRoles());

it('deja listar los viajes a los cuatro roles', function (UserRole $role) {
    asUser(userWithRole($role))->getJson('/api/trips')
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Viajes obtenidos correctamente');
})->with(tripReaderRoles());

/*
|--------------------------------------------------------------------------
| Ámbito de lectura
|--------------------------------------------------------------------------
*/

it('deja ver todos los viajes, asignados o no, al administrador y al gerente', function (UserRole $role) {
    $libre = Trip::factory()->create();
    $tomado = Trip::factory()->assigned()->create();

    $ids = collect(asUser(userWithRole($role))->getJson('/api/trips')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($libre->id)
        ->and($ids)->toContain($tomado->id);
})->with([
    'administrador' => UserRole::Administrator,
    'gerente' => UserRole::Manager,
]);

it('deja ver al transportista la bolsa de viajes pendientes y sin tripulación', function () {
    $team = tripTeam();
    $enLaBolsa = Trip::factory()->create();
    $deOtraEmpresa = Trip::factory()->assigned()->create();

    $data = asUser($team['owner'])->getJson('/api/trips')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($enLaBolsa->id)
        /** El listado no trae pilotId ni vehicleId: la bolsa se reconoce por los nombres en null. */
        ->and($data[0]['pilotName'])->toBeNull()
        ->and($data[0]['vehiclePlate'])->toBeNull()
        ->and($data[0]['status'])->toBe('pending')
        ->and(collect($data)->pluck('id')->all())->not->toContain($deOtraEmpresa->id);
});

it('saca el viaje del listado de la empresa B en cuanto lo asigna la A, y lo deja en el de la A', function () {
    $empresaA = tripTeam();
    $empresaB = tripTeam();
    $trip = Trip::factory()->create();

    /** Mientras nadie lo toma, las dos empresas lo ven en la bolsa. */
    expect(collect(asUser($empresaA['owner'])->getJson('/api/trips')->json('data'))->pluck('id')->all())->toContain($trip->id)
        ->and(collect(asUser($empresaB['owner'])->getJson('/api/trips')->json('data'))->pluck('id')->all())->toContain($trip->id);

    asUser($empresaA['owner'])->patchJson("/api/trips/{$trip->id}/assignment", [
        'pilotId' => $empresaA['pilot']->id,
        'vehicleId' => $empresaA['vehicle']->id,
    ])->assertOk();

    expect(collect(asUser($empresaA['owner'])->getJson('/api/trips')->json('data'))->pluck('id')->all())->toBe([$trip->id])
        ->and(asUser($empresaB['owner'])->getJson('/api/trips')->json('data'))->toBe([]);
});

it('deja ver a un segundo usuario de la empresa A el viaje que tomó su compañero', function () {
    $empresaA = tripTeam();
    $trip = tripAssignedTo($empresaA);

    /** Otro usuario vinculado a la misma empresa: el ámbito compara la empresa, no la persona. */
    $companero = userWithRole(UserRole::Carrier);
    $empresaA['carrier']->pilots()->attach($companero);

    $data = asUser($companero)->getJson('/api/trips')->assertOk()->json('data');

    expect(collect($data)->pluck('id')->all())->toBe([$trip->id]);

    asUser($companero)->getJson("/api/trips/{$trip->id}")->assertOk();
});

it('deja ver al piloto solo sus viajes, y nunca la bolsa', function () {
    $team = tripTeam();
    $suyo = tripAssignedTo($team);
    $enLaBolsa = Trip::factory()->create();
    $deOtroPiloto = Trip::factory()->assigned()->create();

    $data = asUser($team['pilot'])->getJson('/api/trips')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($suyo->id)
        ->and(collect($data)->pluck('id')->all())->not->toContain($enLaBolsa->id)
        ->and(collect($data)->pluck('id')->all())->not->toContain($deOtroPiloto->id);
});

it('responde 403, y no 404, en el detalle de un viaje fuera del ámbito del transportista', function () {
    $empresaB = tripTeam();
    $trip = Trip::factory()->assigned()->create();

    asUser($empresaB['owner'])->getJson("/api/trips/{$trip->id}")
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No puedes acceder a un viaje que no pertenece a tu empresa transportista',
            'data' => null,
        ]);
});

it('responde 403 en el detalle de un viaje que no es del piloto, incluso si está en la bolsa', function (bool $enLaBolsa) {
    $piloto = tripTeam()['pilot'];

    $trip = $enLaBolsa ? Trip::factory()->create() : Trip::factory()->assigned()->create();

    asUser($piloto)->getJson("/api/trips/{$trip->id}")
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No puedes acceder a un viaje que no tienes asignado',
            'data' => null,
        ]);
})->with([
    'un viaje de la bolsa' => true,
    'un viaje de otro piloto' => false,
]);

it('aplica el ámbito antes que los filtros: status=pending no revela lo que tomó otra empresa', function () {
    $empresaA = tripTeam();
    $empresaB = tripTeam();

    /** Tomado por la A y todavía pendiente: es justo el caso que un filtro ingenuo revelaría. */
    $deLaA = tripAssignedTo($empresaA);
    $enLaBolsa = Trip::factory()->create();

    $data = asUser($empresaB['owner'])->getJson('/api/trips?status=pending')->assertOk()->json('data');

    expect($deLaA->fresh()->status)->toBe(TripStatus::Pending)
        ->and(collect($data)->pluck('id')->all())->toBe([$enLaBolsa->id]);
});

it('aplica el ámbito antes que los filtros también para el piloto', function () {
    $team = tripTeam();
    $suyo = tripAssignedTo($team);
    Trip::factory()->create();

    $data = asUser($team['pilot'])->getJson('/api/trips?status=pending')->assertOk()->json('data');

    expect(collect($data)->pluck('id')->all())->toBe([$suyo->id]);
});

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('registra el viaje con todos sus campos y devuelve 201', function () {
    $admin = userWithRole(UserRole::Administrator);
    $payload = tripPayload();

    $response = asUser($admin)->postJson('/api/trips', $payload)
        ->assertCreated()
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Viaje registrado correctamente')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.pilotId', null)
        ->assertJsonPath('data.vehicleId', null)
        ->assertJsonPath('data.assignedById', null)
        ->assertJsonPath('data.assignedByName', null)
        ->assertJsonPath('data.startDate', null)
        ->assertJsonPath('data.endDate', null)
        ->assertJsonPath('data.deletedAt', null)
        ->assertJsonPath('data.registeredByName', $admin->name);

    expect(array_keys($response->json('data')))->toBe(tripResourceKeys());

    $this->assertDatabaseHas('trips', [
        'id' => $response->json('data.id'),
        'order' => 'ORD-2026-0001',
        'container' => 'MSKU 123456 7',
        'client_id' => $payload['clientId'],
        'shipping_line_id' => $payload['shippingLineId'],
        'departure_point_id' => $payload['departurePointId'],
        'location_id' => $payload['locationId'],
        'status' => TripStatus::Pending->value,
        'pilot_id' => null,
        'vehicle_id' => null,
        'assigned_by' => null,
        'registered_by' => $admin->id,
        'deleted_at' => null,
    ]);
});

it('descarta sin error el estado, la tripulación y las dos autorías que mande el body', function () {
    $admin = userWithRole(UserRole::Administrator);
    $otro = userWithRole(UserRole::Administrator);
    $team = tripTeam();

    $response = asUser($admin)->postJson('/api/trips', tripPayload([
        'status' => TripStatus::Finished->value,
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
        'assignedBy' => $team['owner']->id,
        'registeredBy' => $otro->id,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.pilotId', null)
        ->assertJsonPath('data.vehicleId', null)
        ->assertJsonPath('data.assignedById', null)
        ->assertJsonPath('data.registeredByName', $admin->name);

    $this->assertDatabaseHas('trips', [
        'id' => $response->json('data.id'),
        'status' => TripStatus::Pending->value,
        'pilot_id' => null,
        'vehicle_id' => null,
        'assigned_by' => null,
        'registered_by' => $admin->id,
    ]);
});

it('rechaza con 422 el alta cuando falta cualquiera de los campos obligatorios', function (string $campo, string $mensaje) {
    $payload = tripPayload();
    unset($payload[$campo]);

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/trips', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo])
        ->assertJsonFragment([$mensaje]);

    expect(Trip::withTrashed()->count())->toBe(0);
})->with([
    'order' => ['order', 'La orden es obligatoria'],
    'clientId' => ['clientId', 'El cliente es obligatorio'],
    'shippingLineId' => ['shippingLineId', 'La naviera es obligatoria'],
    'departurePointId' => ['departurePointId', 'El punto de partida es obligatorio'],
    'locationId' => ['locationId', 'El puerto de destino es obligatorio'],
    'destination' => ['destination', 'El destino final es obligatorio'],
    'container' => ['container', 'El contenedor es obligatorio'],
    'transport' => ['transport', 'El transporte es obligatorio'],
    'recolectionDate' => ['recolectionDate', 'La fecha de recolección es obligatoria'],
    'shipDate' => ['shipDate', 'La fecha de embarque es obligatoria'],
    'polyline' => ['polyline', 'La ruta es obligatoria'],
    'observations' => ['observations', 'Las observaciones son obligatorias'],
]);

it('rechaza con 422 un alta con el cuerpo vacío señalando los doce campos', function () {
    $response = asUser(userWithRole(UserRole::Administrator))->postJson('/api/trips', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'order', 'clientId', 'shippingLineId', 'departurePointId', 'locationId',
            'destination', 'container', 'transport', 'recolectionDate', 'shipDate',
            'polyline', 'observations',
        ]);

    /** El 422 sale con el formato de Laravel, no con el sobre del ResponseHandler. */
    expect(array_keys($response->json()))->toBe(['message', 'errors']);
});

it('guarda la orden y el contenedor en mayúsculas con los espacios colapsados', function () {
    $response = asUser(userWithRole(UserRole::Administrator))->postJson('/api/trips', tripPayload([
        'order' => '  ord-2026   0001 ',
        'container' => "msku\t123456   7 ",
    ]))
        ->assertCreated()
        ->assertJsonPath('data.order', 'ORD-2026 0001')
        ->assertJsonPath('data.container', 'MSKU 123456 7');

    $this->assertDatabaseHas('trips', [
        'id' => $response->json('data.id'),
        'order' => 'ORD-2026 0001',
        'container' => 'MSKU 123456 7',
    ]);
});

it('conserva las mayúsculas y minúsculas del destino, el transporte y las observaciones, con solo trim', function () {
    $response = asUser(userWithRole(UserRole::Administrator))->postJson('/api/trips', tripPayload([
        'destination' => '  Rotterdam, Países   Bajos  ',
        'transport' => '  Rastra 40 pies  ',
        'observations' => '  Cargar a  primera hora  ',
    ]))
        ->assertCreated()
        /** Solo trim: ni mayúsculas ni colapso de espacios interiores. */
        ->assertJsonPath('data.destination', 'Rotterdam, Países   Bajos')
        ->assertJsonPath('data.transport', 'Rastra 40 pies')
        ->assertJsonPath('data.observations', 'Cargar a  primera hora');

    $this->assertDatabaseHas('trips', [
        'id' => $response->json('data.id'),
        'destination' => 'Rotterdam, Países   Bajos',
        'observations' => 'Cargar a  primera hora',
    ]);
});

it('deja que dos viajes compartan la misma orden y el mismo contenedor', function () {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/trips', tripPayload())->assertCreated();
    asUser($admin)->postJson('/api/trips', tripPayload())->assertCreated();

    expect(Trip::query()->where('order', 'ORD-2026-0001')->count())->toBe(2)
        ->and(Trip::query()->where('container', 'MSKU 123456 7')->count())->toBe(2);
});

it('rechaza con 422 una fecha planificada en el pasado', function (string $campo, string $mensaje) {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/trips', tripPayload([
        $campo => now()->subDay()->format('Y-m-d H:i:s'),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo])
        ->assertJsonFragment([$mensaje]);

    expect(Trip::withTrashed()->count())->toBe(0);
})->with([
    'recolección' => ['recolectionDate', 'La fecha de recolección debe ser futura'],
    'embarque' => ['shipDate', 'La fecha de embarque debe ser futura'],
]);

it('rechaza con 422 un embarque anterior a la recolección', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/trips', tripPayload([
        'recolectionDate' => now()->addDays(10)->format('Y-m-d H:i:s'),
        'shipDate' => now()->addDays(5)->format('Y-m-d H:i:s'),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['shipDate'])
        ->assertJsonFragment(['La fecha de embarque no puede ser anterior a la de recolección']);

    expect(Trip::withTrashed()->count())->toBe(0);
});

it('acepta un embarque exactamente igual a la recolección', function () {
    $fecha = now()->addDays(4)->startOfSecond()->format('Y-m-d H:i:s');

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/trips', tripPayload([
        'recolectionDate' => $fecha,
        'shipDate' => $fecha,
    ]))->assertCreated();
});

it('rechaza con 422 una FK inventada, que es lo que caza el exists del FormRequest', function (string $campo, string $mensaje) {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/trips', tripPayload([$campo => 999999]))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo])
        ->assertJsonFragment([$mensaje]);
})->with([
    'cliente' => ['clientId', 'El cliente seleccionado no existe'],
    'naviera' => ['shippingLineId', 'La naviera seleccionada no existe'],
    'punto de partida' => ['departurePointId', 'El punto de partida seleccionado no existe'],
    'puerto de destino' => ['locationId', 'El puerto de destino seleccionado no existe'],
]);

it('rechaza con 400 un destino que no es un puerto', function () {
    $destino = Location::factory()->active()->create(['type' => LocationType::Destination]);

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/trips', tripPayload(['locationId' => $destino->id]))
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El destino seleccionado no es un puerto',
            'data' => null,
        ]);

    expect(Trip::withTrashed()->count())->toBe(0);
});

it('rechaza con 400 un puerto inactivo', function () {
    $puerto = Location::factory()->port()->inactive()->create();

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/trips', tripPayload(['locationId' => $puerto->id]))
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El puerto de destino está inactivo',
            'data' => null,
        ]);
});

it('rechaza con 400 un punto de partida inactivo', function () {
    $punto = DeparturePoint::factory()->inactive()->create();

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/trips', tripPayload(['departurePointId' => $punto->id]))
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El punto de partida está inactivo',
            'data' => null,
        ]);
});

it('rechaza con 400 un cliente borrado, que el exists de Laravel no ve', function () {
    $cliente = Client::factory()->trashed()->create();

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/trips', tripPayload(['clientId' => $cliente->id]))
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El cliente seleccionado fue eliminado',
            'data' => null,
        ]);

    expect(Trip::withTrashed()->count())->toBe(0);
});

it('rechaza con 400 una naviera borrada, que el exists de Laravel no ve', function () {
    $naviera = ShippingLine::factory()->trashed()->create();

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/trips', tripPayload(['shippingLineId' => $naviera->id]))
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'La naviera seleccionada fue eliminada',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| Edición del administrador
|--------------------------------------------------------------------------
*/

it('acepta un PATCH con el cuerpo vacío como no-op', function () {
    $trip = Trip::factory()->create(['order' => 'ORD-2026-0001']);

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/trips/{$trip->id}", [])
        ->assertOk()
        ->assertJsonPath('message', 'Viaje actualizado correctamente')
        ->assertJsonPath('data.order', 'ORD-2026-0001')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('trips', ['id' => $trip->id, 'order' => 'ORD-2026-0001']);
});

it('edita los campos que llegan normalizando la orden y el contenedor', function () {
    $trip = Trip::factory()->create();

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/trips/{$trip->id}", [
        'order' => '  ord-2026   9999 ',
        'container' => ' tclu  777777 3 ',
        'destination' => '  Hamburgo, Alemania ',
        'observations' => '  Revisar  el sello ',
    ])
        ->assertOk()
        ->assertJsonPath('data.order', 'ORD-2026 9999')
        ->assertJsonPath('data.container', 'TCLU 777777 3')
        ->assertJsonPath('data.destination', 'Hamburgo, Alemania')
        ->assertJsonPath('data.observations', 'Revisar  el sello');

    $this->assertDatabaseHas('trips', [
        'id' => $trip->id,
        'order' => 'ORD-2026 9999',
        'container' => 'TCLU 777777 3',
    ]);
});

it('ignora con 200 el piloto y el vehículo mandados al PATCH, conservando la asignación', function () {
    $empresaA = tripTeam();
    $empresaB = tripTeam();
    $trip = tripAssignedTo($empresaA);

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/trips/{$trip->id}", [
        'pilotId' => $empresaB['pilot']->id,
        'vehicleId' => $empresaB['vehicle']->id,
        'order' => 'ord-2026-9999',
    ])
        ->assertOk()
        ->assertJsonPath('data.pilotId', $empresaA['pilot']->id)
        ->assertJsonPath('data.vehicleId', $empresaA['vehicle']->id);

    $this->assertDatabaseHas('trips', [
        'id' => $trip->id,
        'pilot_id' => $empresaA['pilot']->id,
        'vehicle_id' => $empresaA['vehicle']->id,
        'order' => 'ORD-2026-9999',
    ]);
});

it('mueve el estado hacia atrás conservando las dos fechas de ejecución', function () {
    $trip = Trip::factory()->finished()->create();

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/trips/{$trip->id}", ['status' => 'pending'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.startDate', $trip->start_date->format('d-m-Y h:i:s A'))
        ->assertJsonPath('data.endDate', $trip->end_date->format('d-m-Y h:i:s A'));

    $fresco = $trip->fresh();

    expect($fresco->status)->toBe(TripStatus::Pending)
        ->and($fresco->start_date)->not->toBeNull()
        ->and($fresco->end_date)->not->toBeNull();
});

it('acepta los tres estados del enum en el PATCH', function (string $status) {
    $trip = Trip::factory()->create();

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/trips/{$trip->id}", ['status' => $status])
        ->assertOk()
        ->assertJsonPath('data.status', $status);
})->with(['pending', 'in_route', 'finished']);

it('rechaza con 422 un estado fuera del enum', function (mixed $status) {
    $trip = Trip::factory()->create();

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/trips/{$trip->id}", ['status' => $status])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect($trip->fresh()->status)->toBe(TripStatus::Pending);
})->with([
    'un valor inventado' => 'cancelled',
    'el estado en español' => 'pendiente',
    'en mayúsculas' => 'PENDING',
]);

it('revalida los catálogos aunque el PATCH solo mueva una fecha', function () {
    $puerto = Location::factory()->port()->active()->create();
    $trip = Trip::factory()->create(['location_id' => $puerto->id]);

    $puerto->update(['status' => false]);

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/trips/{$trip->id}", [
        'recolectionDate' => now()->addDays(9)->format('Y-m-d H:i:s'),
    ])
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El puerto de destino está inactivo',
            'data' => null,
        ]);

    expect($trip->fresh()->recolection_date->equalTo($trip->recolection_date))->toBeTrue();
});

it('revalida el cliente borrado aunque el PATCH solo mueva la orden', function () {
    $cliente = Client::factory()->create();
    $trip = Trip::factory()->create(['client_id' => $cliente->id]);

    $cliente->delete();

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/trips/{$trip->id}", ['order' => 'ord-2026-9999'])
        ->assertStatus(400)
        ->assertJsonPath('message', 'El cliente seleccionado fue eliminado');

    expect($trip->fresh()->order)->toBe($trip->order);
});

it('no reescribe al responsable del alta ni a quien asignó el viaje', function () {
    $admin = userWithRole(UserRole::Administrator);
    $otro = userWithRole(UserRole::Administrator);
    $empresaA = tripTeam();
    $trip = tripAssignedTo($empresaA, ['registered_by' => $admin->id]);

    asUser($otro)->patchJson("/api/trips/{$trip->id}", [
        'order' => 'ord-2026-9999',
        'registeredBy' => $otro->id,
        'registered_by' => $otro->id,
        'assignedBy' => $otro->id,
        'assigned_by' => $otro->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.registeredByName', $admin->name)
        ->assertJsonPath('data.assignedById', $empresaA['owner']->id);

    $this->assertDatabaseHas('trips', [
        'id' => $trip->id,
        'registered_by' => $admin->id,
        'assigned_by' => $empresaA['owner']->id,
    ]);
});

it('responde 404 al editar un id inexistente', function () {
    asUser(userWithRole(UserRole::Administrator))->patchJson('/api/trips/999999', ['order' => 'ord-2026-9999'])
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El viaje no existe',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| Asignación
|--------------------------------------------------------------------------
*/

it('asigna piloto y vehículo escribiendo los tres campos juntos', function () {
    $team = tripTeam();
    $trip = Trip::factory()->create();

    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
    ])
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Viaje asignado correctamente')
        ->assertJsonPath('data.pilotId', $team['pilot']->id)
        ->assertJsonPath('data.pilotName', $team['pilot']->name)
        ->assertJsonPath('data.vehicleId', $team['vehicle']->id)
        ->assertJsonPath('data.vehiclePlate', $team['vehicle']->plate)
        ->assertJsonPath('data.assignedById', $team['owner']->id)
        ->assertJsonPath('data.assignedByName', $team['owner']->name)
        /** Asignar no arranca nada: el viaje sigue pendiente hasta el /start del piloto. */
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('trips', [
        'id' => $trip->id,
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
        'status' => TripStatus::Pending->value,
    ]);
});

it('rechaza con 422 una asignación a la que le falta uno de los dos campos', function (array $payload, string $campo, string $mensaje) {
    $team = tripTeam();
    $trip = Trip::factory()->create();

    $payload = array_map(fn (string $llave) => match ($llave) {
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
    }, $payload);

    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo])
        ->assertJsonFragment([$mensaje]);

    expect($trip->fresh()->assigned_by)->toBeNull();
})->with([
    'solo el piloto' => [['pilotId' => 'pilotId'], 'vehicleId', 'El vehículo es obligatorio'],
    'solo el vehículo' => [['vehicleId' => 'vehicleId'], 'pilotId', 'El piloto es obligatorio'],
    'ninguno de los dos' => [[], 'pilotId', 'El piloto es obligatorio'],
]);

it('rechaza con 422 un null en cualquiera de los dos campos: la desasignación no existe', function (string $campo) {
    $team = tripTeam();
    $trip = Trip::factory()->create();

    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
        $campo => null,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo]);

    expect($trip->fresh()->assigned_by)->toBeNull();
})->with(['pilotId', 'vehicleId']);

it('rechaza con 422 un piloto o un vehículo inexistentes', function (string $campo, string $mensaje) {
    $team = tripTeam();
    $trip = Trip::factory()->create();

    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
        $campo => 999999,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo])
        ->assertJsonFragment([$mensaje]);
})->with([
    'piloto' => ['pilotId', 'El piloto seleccionado no existe'],
    'vehículo' => ['vehicleId', 'El vehículo seleccionado no existe'],
]);

it('rechaza con 400 un usuario que no tiene rol de piloto', function (UserRole $role) {
    $team = tripTeam();
    $trip = Trip::factory()->create();
    $noPiloto = userWithRole($role);
    $team['carrier']->pilots()->attach($noPiloto);

    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", [
        'pilotId' => $noPiloto->id,
        'vehicleId' => $team['vehicle']->id,
    ])
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El usuario seleccionado no es un piloto',
            'data' => null,
        ]);

    expect($trip->fresh()->assigned_by)->toBeNull();
})->with([
    'un transportista' => UserRole::Carrier,
    'un gerente' => UserRole::Manager,
    'un administrador' => UserRole::Administrator,
]);

it('rechaza con 400 un piloto que no pertenece a ninguna empresa', function () {
    $team = tripTeam();
    $trip = Trip::factory()->create();
    $suelto = userWithRole(UserRole::Pilot);

    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", [
        'pilotId' => $suelto->id,
        'vehicleId' => $team['vehicle']->id,
    ])
        ->assertStatus(400)
        ->assertJsonPath('message', 'El piloto seleccionado no pertenece a ninguna empresa transportista');
});

it('rechaza con 400 un vehículo que no está activo', function (VehicleStatus $status) {
    $team = tripTeam();
    $trip = Trip::factory()->create();
    $vehiculo = Vehicle::factory()->create(['carrier_id' => $team['carrier']->id, 'status' => $status]);

    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $vehiculo->id,
    ])
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El vehículo seleccionado no está activo',
            'data' => null,
        ]);

    expect($trip->fresh()->vehicle_id)->toBeNull();
})->with([
    'inactivo' => VehicleStatus::Inactive,
    'en reparación' => VehicleStatus::UnderRepair,
]);

it('rechaza con 400 un piloto y un vehículo de empresas distintas', function () {
    $empresaA = tripTeam();
    $empresaB = tripTeam();
    $trip = Trip::factory()->create();

    asUser($empresaA['owner'])->patchJson("/api/trips/{$trip->id}/assignment", [
        'pilotId' => $empresaA['pilot']->id,
        'vehicleId' => $empresaB['vehicle']->id,
    ])
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El piloto y el vehículo deben pertenecer a la misma empresa transportista',
            'data' => null,
        ]);

    expect($trip->fresh()->assigned_by)->toBeNull();
});

it('rechaza con 403 al transportista B sobre un viaje que ya tomó la empresa A', function () {
    $empresaA = tripTeam();
    $empresaB = tripTeam();
    $trip = tripAssignedTo($empresaA);

    asUser($empresaB['owner'])->patchJson("/api/trips/{$trip->id}/assignment", [
        'pilotId' => $empresaB['pilot']->id,
        'vehicleId' => $empresaB['vehicle']->id,
    ])
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No puedes asignar un viaje que ya tomó otra empresa transportista',
            'data' => null,
        ]);

    $this->assertDatabaseHas('trips', [
        'id' => $trip->id,
        'pilot_id' => $empresaA['pilot']->id,
        'vehicle_id' => $empresaA['vehicle']->id,
        'assigned_by' => $empresaA['owner']->id,
    ]);
});

it('deja que la empresa A reasigne su propio viaje mientras siga pendiente', function () {
    $empresaA = tripTeam();
    $trip = tripAssignedTo($empresaA);

    $otroPiloto = userWithRole(UserRole::Pilot);
    $empresaA['carrier']->pilots()->attach($otroPiloto);
    $otroVehiculo = Vehicle::factory()->create(['carrier_id' => $empresaA['carrier']->id]);

    /** Reasigna un compañero de la misma empresa: assigned_by pasa a ser él. */
    $companero = userWithRole(UserRole::Carrier);
    $empresaA['carrier']->pilots()->attach($companero);

    asUser($companero)->patchJson("/api/trips/{$trip->id}/assignment", [
        'pilotId' => $otroPiloto->id,
        'vehicleId' => $otroVehiculo->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.pilotId', $otroPiloto->id)
        ->assertJsonPath('data.vehicleId', $otroVehiculo->id)
        ->assertJsonPath('data.assignedById', $companero->id);

    $this->assertDatabaseHas('trips', [
        'id' => $trip->id,
        'pilot_id' => $otroPiloto->id,
        'vehicle_id' => $otroVehiculo->id,
        'assigned_by' => $companero->id,
    ]);
});

it('rechaza con 400 reasignar un viaje que ya no está pendiente', function (string $estado) {
    $empresaA = tripTeam();
    $trip = tripAssignedTo($empresaA, [
        'status' => $estado === 'in_route' ? TripStatus::InRoute : TripStatus::Finished,
        'start_date' => now()->subHours(3),
        'end_date' => $estado === 'finished' ? now() : null,
    ]);

    $otroPiloto = userWithRole(UserRole::Pilot);
    $empresaA['carrier']->pilots()->attach($otroPiloto);

    asUser($empresaA['owner'])->patchJson("/api/trips/{$trip->id}/assignment", [
        'pilotId' => $otroPiloto->id,
        'vehicleId' => $empresaA['vehicle']->id,
    ])
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'Solo se puede asignar un viaje pendiente',
            'data' => null,
        ]);

    expect($trip->fresh()->pilot_id)->toBe($empresaA['pilot']->id);
})->with(['in_route', 'finished']);

it('deja una sola escritura cuando dos empresas se disputan el mismo viaje libre', function () {
    $empresaA = tripTeam();
    $empresaB = tripTeam();
    $trip = Trip::factory()->create();

    asUser($empresaA['owner'])->patchJson("/api/trips/{$trip->id}/assignment", [
        'pilotId' => $empresaA['pilot']->id,
        'vehicleId' => $empresaA['vehicle']->id,
    ])->assertOk();

    asUser($empresaB['owner'])->patchJson("/api/trips/{$trip->id}/assignment", [
        'pilotId' => $empresaB['pilot']->id,
        'vehicleId' => $empresaB['vehicle']->id,
    ])->assertForbidden();

    $fresco = $trip->fresh();

    expect($fresco->assigned_by)->toBe($empresaA['owner']->id)
        ->and($fresco->pilot_id)->toBe($empresaA['pilot']->id)
        ->and($fresco->vehicle_id)->toBe($empresaA['vehicle']->id);
});

it('responde 404 al asignar un id inexistente', function () {
    $team = tripTeam();

    asUser($team['owner'])->patchJson('/api/trips/999999/assignment', [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
    ])
        ->assertNotFound()
        ->assertJsonPath('message', 'El viaje no existe');
});

/*
|--------------------------------------------------------------------------
| Inicio y cierre
|--------------------------------------------------------------------------
*/

it('arranca el viaje del piloto asignado con la hora del servidor', function () {
    $team = tripTeam();
    $trip = tripAssignedTo($team);

    $antes = now()->subSecond();

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/start")
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Viaje iniciado correctamente')
        ->assertJsonPath('data.status', 'in_route')
        ->assertJsonPath('data.endDate', null);

    $fresco = $trip->fresh();

    expect($fresco->status)->toBe(TripStatus::InRoute)
        ->and($fresco->start_date)->not->toBeNull()
        ->and($fresco->start_date->greaterThanOrEqualTo($antes))->toBeTrue()
        ->and($fresco->start_date->lessThanOrEqualTo(now()->addSecond()))->toBeTrue();
});

it('ignora la fecha que venga en el cuerpo de /start', function () {
    $team = tripTeam();
    $trip = tripAssignedTo($team);

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/start", [
        'startDate' => '01-01-2020 08:00:00',
        'start_date' => '2020-01-01 08:00:00',
    ])->assertOk();

    expect($trip->fresh()->start_date->year)->toBe(now()->year);
});

it('rechaza con 403 el arranque de un piloto que no es el asignado', function () {
    $team = tripTeam();
    $trip = tripAssignedTo($team);

    $otroPiloto = userWithRole(UserRole::Pilot);
    $team['carrier']->pilots()->attach($otroPiloto);

    asUser($otroPiloto)->patchJson("/api/trips/{$trip->id}/start")
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No puedes iniciar un viaje que no tienes asignado',
            'data' => null,
        ]);

    expect($trip->fresh()->start_date)->toBeNull();
});

it('rechaza con 400 el segundo arranque del mismo viaje', function () {
    $team = tripTeam();
    $trip = tripAssignedTo($team);

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/start")->assertOk();

    $primerArranque = $trip->fresh()->start_date;

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/start")
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El viaje ya fue iniciado',
            'data' => null,
        ]);

    expect($trip->fresh()->start_date->equalTo($primerArranque))->toBeTrue();
});

it('cierra el viaje del piloto asignado dejando la fecha de fin y el estado finalizado', function () {
    $team = tripTeam();
    $trip = tripAssignedTo($team, ['status' => TripStatus::InRoute, 'start_date' => now()->subHours(4)]);

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/finish")
        ->assertOk()
        ->assertJsonPath('message', 'Viaje finalizado correctamente')
        ->assertJsonPath('data.status', 'finished');

    $fresco = $trip->fresh();

    expect($fresco->status)->toBe(TripStatus::Finished)
        ->and($fresco->end_date)->not->toBeNull()
        ->and($fresco->end_date->lessThanOrEqualTo(now()->addSecond()))->toBeTrue();
});

it('ignora la fecha que venga en el cuerpo de /finish', function () {
    $team = tripTeam();
    $trip = tripAssignedTo($team, ['status' => TripStatus::InRoute, 'start_date' => now()->subHours(4)]);

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/finish", [
        'endDate' => '01-01-2020 08:00:00',
        'end_date' => '2020-01-01 08:00:00',
    ])->assertOk();

    expect($trip->fresh()->end_date->year)->toBe(now()->year);
});

it('rechaza con 400 el cierre de un viaje que nunca arrancó', function () {
    $team = tripTeam();
    $trip = tripAssignedTo($team);

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/finish")
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El viaje no ha sido iniciado',
            'data' => null,
        ]);

    expect($trip->fresh()->end_date)->toBeNull();
});

it('rechaza con 400 el segundo cierre del mismo viaje', function () {
    $team = tripTeam();
    $trip = tripAssignedTo($team, ['status' => TripStatus::InRoute, 'start_date' => now()->subHours(4)]);

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/finish")->assertOk();

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/finish")
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El viaje ya fue finalizado',
            'data' => null,
        ]);
});

it('rechaza con 403 el cierre de un piloto que no es el asignado', function () {
    $team = tripTeam();
    $trip = tripAssignedTo($team, ['status' => TripStatus::InRoute, 'start_date' => now()->subHours(4)]);

    $otroPiloto = userWithRole(UserRole::Pilot);
    $team['carrier']->pilots()->attach($otroPiloto);

    asUser($otroPiloto)->patchJson("/api/trips/{$trip->id}/finish")
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes finalizar un viaje que no tienes asignado');
});

it('responde 404 al arrancar o cerrar un id inexistente', function (string $accion) {
    asUser(userWithRole(UserRole::Pilot))->patchJson("/api/trips/999999/{$accion}")
        ->assertNotFound()
        ->assertJsonPath('message', 'El viaje no existe');
})->with(['start', 'finish']);

/*
|--------------------------------------------------------------------------
| Listado, filtros y paginación
|--------------------------------------------------------------------------
*/

it('ordena el listado por fecha de recolección descendente y por id descendente', function () {
    $admin = userWithRole(UserRole::Administrator);

    $viejo = Trip::factory()->create(['recolection_date' => now()->addDays(2)]);
    $mismoInstanteA = Trip::factory()->create(['recolection_date' => now()->addDays(9)]);
    $mismoInstanteB = Trip::factory()->create(['recolection_date' => now()->addDays(9)]);
    $nuevo = Trip::factory()->create(['recolection_date' => now()->addDays(20)]);

    $ids = collect(asUser($admin)->getJson('/api/trips')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$nuevo->id, $mismoInstanteB->id, $mismoInstanteA->id, $viejo->id]);
});

it('no incluye los viajes borrados en el listado', function () {
    $admin = userWithRole(UserRole::Administrator);
    $vivo = Trip::factory()->create();
    $borrado = Trip::factory()->trashed()->create();

    $ids = collect(asUser($admin)->getJson('/api/trips')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$vivo->id])
        ->and($ids)->not->toContain($borrado->id);
});

it('filtra por estado', function () {
    $admin = userWithRole(UserRole::Administrator);
    $pendiente = Trip::factory()->create();
    $enRuta = Trip::factory()->inRoute()->create();

    $data = asUser($admin)->getJson('/api/trips?status=in_route')->assertOk()->json('data');

    expect(collect($data)->pluck('id')->all())->toBe([$enRuta->id])
        ->and(collect($data)->pluck('id')->all())->not->toContain($pendiente->id);
});

it('filtra por cada uno de los cinco identificadores', function (string $filtro) {
    $admin = userWithRole(UserRole::Administrator);
    $team = tripTeam();

    $buscado = tripAssignedTo($team);
    Trip::factory()->assigned()->create();

    $valor = match ($filtro) {
        'clientId' => $buscado->client_id,
        'shippingLineId' => $buscado->shipping_line_id,
        'locationId' => $buscado->location_id,
        'pilotId' => $buscado->pilot_id,
        'vehicleId' => $buscado->vehicle_id,
    };

    $data = asUser($admin)->getJson("/api/trips?{$filtro}={$valor}")->assertOk()->json('data');

    expect(collect($data)->pluck('id')->all())->toBe([$buscado->id]);
})->with(['clientId', 'shippingLineId', 'locationId', 'pilotId', 'vehicleId']);

it('filtra por rango de fechas de recolección, incluyendo el día completo', function () {
    $admin = userWithRole(UserRole::Administrator);

    $dentro = Trip::factory()->create(['recolection_date' => now()->addDays(5)->setTime(18, 30)]);
    $antes = Trip::factory()->create(['recolection_date' => now()->addDays(2)]);
    $despues = Trip::factory()->create(['recolection_date' => now()->addDays(12)]);

    $desde = now()->addDays(4)->format('Y-m-d');
    $hasta = now()->addDays(5)->format('Y-m-d');

    $data = asUser($admin)->getJson("/api/trips?dateFrom={$desde}&dateTo={$hasta}")->assertOk()->json('data');

    expect(collect($data)->pluck('id')->all())->toBe([$dentro->id])
        ->and(collect($data)->pluck('id')->all())->not->toContain($antes->id)
        ->and(collect($data)->pluck('id')->all())->not->toContain($despues->id);
});

it('busca en la orden y en el contenedor sin distinguir mayúsculas', function (string $search, string $campo) {
    $admin = userWithRole(UserRole::Administrator);

    $buscado = Trip::factory()->create(['order' => 'ORD-2026-0001', 'container' => 'MSKU 123456 7']);
    Trip::factory()->create(['order' => 'ORD-2026-9999', 'container' => 'TCLU 777777 3']);

    $data = asUser($admin)->getJson('/api/trips?search='.urlencode($search))->assertOk()->json('data');

    expect(collect($data)->pluck('id')->all())->toBe([$buscado->id])
        ->and($data[0][$campo])->toBeString();
})->with([
    'trozo de la orden en minúsculas' => ['ord-2026-0001', 'order'],
    'trozo de la orden en mayúsculas' => ['ORD-2026-00', 'order'],
    'contenedor en minúsculas' => ['msku', 'container'],
    'contenedor con espacios de sobra' => ['  msku   123456 ', 'container'],
]);

it('devuelve el listado completo cuando el término de búsqueda viene vacío', function () {
    $admin = userWithRole(UserRole::Administrator);
    Trip::factory()->count(3)->create();

    expect(asUser($admin)->getJson('/api/trips?search=')->assertOk()->json('data'))->toHaveCount(3);
});

it('ignora un valor inválido en cualquiera de los ocho filtros, sin vaciar el listado ni dar 422', function (string $query) {
    $admin = userWithRole(UserRole::Administrator);
    Trip::factory()->count(3)->create();

    expect(asUser($admin)->getJson("/api/trips?{$query}")->assertOk()->json('data'))->toHaveCount(3);
})->with([
    'status inventado' => 'status=cancelled',
    'status en mayúsculas' => 'status=PENDING',
    'clientId no numérico' => 'clientId=abc',
    'shippingLineId no numérico' => 'shippingLineId=abc',
    'locationId no numérico' => 'locationId=abc',
    'pilotId no numérico' => 'pilotId=abc',
    'vehicleId no numérico' => 'vehicleId=abc',
    'dateFrom malformada' => 'dateFrom=ayer',
    'dateTo malformada' => 'dateTo=31-12-2026',
]);

it('devuelve el listado vacío, y nunca 422, cuando la búsqueda no encuentra nada', function () {
    $admin = userWithRole(UserRole::Administrator);
    Trip::factory()->create(['order' => 'ORD-2026-0001', 'container' => 'MSKU 123456 7']);

    expect(asUser($admin)->getJson('/api/trips?search=inexistente')->assertOk()->json('data'))->toBe([]);
});

it('devuelve la colección completa y ninguna clave de paginación sin limit', function () {
    $admin = userWithRole(UserRole::Administrator);
    Trip::factory()->count(12)->create();

    $response = asUser($admin)->getJson('/api/trips')->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12);
});

it('devuelve los metadatos de paginación en la raíz del sobre', function () {
    $admin = userWithRole(UserRole::Administrator);
    Trip::factory()->count(12)->create();

    $response = asUser($admin)->getJson('/api/trips?limit=10')->assertOk();

    expect($response->json())->toHaveKeys(['statusCode', 'message', 'data', 'total', 'currentPage', 'lastPage'])
        ->and($response->json('total'))->toBe(12)
        ->and($response->json('currentPage'))->toBe(1)
        ->and($response->json('lastPage'))->toBe(2)
        ->and($response->json('data'))->toHaveCount(10)
        ->and($response->json('meta'))->toBeNull();
});

it('acota el tamaño de página a [10, 100] también por HTTP', function () {
    $admin = userWithRole(UserRole::Administrator);
    Trip::factory()->count(11)->create();

    $porDebajo = asUser($admin)->getJson('/api/trips?limit=1')->assertOk();
    $porEncima = asUser($admin)->getJson('/api/trips?limit=500')->assertOk();

    expect($porDebajo->json('data'))->toHaveCount(10)
        ->and($porDebajo->json('lastPage'))->toBe(2)
        ->and($porEncima->json('data'))->toHaveCount(11)
        ->and($porEncima->json('lastPage'))->toBe(1);
});

it('no pagina cuando el limit no es numérico', function () {
    $admin = userWithRole(UserRole::Administrator);
    Trip::factory()->count(11)->create();

    $response = asUser($admin)->getJson('/api/trips?limit=abc')->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(11);
});

it('no dispara N+1 al listar viajes con sus seis relaciones', function () {
    Trip::factory()->count(10)->assigned()->create();

    /** El token se emite antes de escuchar: sus claims consultan la empresa del usuario. */
    $token = JWTAuth::fromUser(userWithRole(UserRole::Administrator));

    resetAuthState();

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withToken($token)->getJson('/api/trips')->assertOk()->assertJsonCount(10, 'data');

    $porTabla = fn (string $tabla) => collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "'.$tabla.'"'))->count();

    /** Una consulta por el listado y una por cada relación cargada con with(). */
    expect($porTabla('trips'))->toBe(1)
        /** El listado no pinta el cliente, así que tampoco lo carga. */
        ->and($porTabla('clients'))->toBe(0)
        ->and($porTabla('shipping_lines'))->toBe(1)
        ->and($porTabla('departure_points'))->toBe(1)
        ->and($porTabla('locations'))->toBe(1)
        ->and($porTabla('vehicles'))->toBe(1)
        ->and(count($queries))->toBeLessThan(15);
});

/*
|--------------------------------------------------------------------------
| Baja
|--------------------------------------------------------------------------
*/

it('borra el viaje dejando la fila con deleted_at', function () {
    $admin = userWithRole(UserRole::Administrator);
    $trip = Trip::factory()->create();

    $data = asUser($admin)->deleteJson("/api/trips/{$trip->id}")
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Viaje eliminado correctamente')
        ->assertJsonPath('data.id', $trip->id)
        ->json('data');

    $this->assertSoftDeleted('trips', ['id' => $trip->id]);

    /** La única respuesta de la API con deletedAt no nulo. */
    expect($data['deletedAt'])->toMatch(tripDatePattern())
        ->and(array_keys($data))->toBe(tripResourceKeys())
        ->and(Trip::query()->find($trip->id))->toBeNull()
        ->and(Trip::withTrashed()->find($trip->id))->not->toBeNull();
});

it('responde 400 en el segundo DELETE y 404 en un id inexistente', function () {
    $admin = userWithRole(UserRole::Administrator);
    $trip = Trip::factory()->create();

    asUser($admin)->deleteJson("/api/trips/{$trip->id}")->assertOk();

    asUser($admin)->deleteJson("/api/trips/{$trip->id}")
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El viaje ya fue eliminado',
            'data' => null,
        ]);

    asUser($admin)->deleteJson('/api/trips/999999')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El viaje no existe',
            'data' => null,
        ]);
});

it('deja de listar y de mostrar el viaje borrado', function () {
    $admin = userWithRole(UserRole::Administrator);
    $borrado = Trip::factory()->create();
    Trip::factory()->count(2)->create();

    asUser($admin)->deleteJson("/api/trips/{$borrado->id}")->assertOk();

    $data = asUser($admin)->getJson('/api/trips')->assertOk()->json('data');

    expect($data)->toHaveCount(2)
        ->and(collect($data)->pluck('id')->all())->not->toContain($borrado->id);

    /** Para quien lee, un viaje borrado no se distingue de uno que nunca existió. */
    asUser($admin)->getJson("/api/trips/{$borrado->id}")
        ->assertNotFound()
        ->assertJsonPath('message', 'El viaje no existe');
});

it('responde 400, y no 404, en las cuatro escrituras sobre un viaje borrado', function () {
    $team = tripTeam();
    $trip = Trip::factory()->trashed()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
        'start_date' => now()->subHours(3),
    ]);

    $esperado = [
        'statusCode' => 400,
        'message' => 'El viaje ya fue eliminado',
        'data' => null,
    ];

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/trips/{$trip->id}", ['order' => 'ord-2026-9999'])
        ->assertStatus(400)
        ->assertExactJson($esperado);

    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
    ])
        ->assertStatus(400)
        ->assertExactJson($esperado);

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/start")
        ->assertStatus(400)
        ->assertExactJson($esperado);

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/finish")
        ->assertStatus(400)
        ->assertExactJson($esperado);
});

/*
|--------------------------------------------------------------------------
| Impacto sobre SPEC 22 y SPEC 23
|--------------------------------------------------------------------------
*/

it('rechaza con 400 borrar un cliente con viajes, aunque esos viajes estén borrados', function (bool $viajeBorrado) {
    $admin = userWithRole(UserRole::Administrator);
    $cliente = Client::factory()->create();

    $viajeBorrado
        ? Trip::factory()->trashed()->create(['client_id' => $cliente->id])
        : Trip::factory()->create(['client_id' => $cliente->id]);

    asUser($admin)->deleteJson("/api/clients/{$cliente->id}")
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'No se puede eliminar el cliente porque tiene viajes asociados',
            'data' => null,
        ]);

    expect($cliente->fresh()->deleted_at)->toBeNull();
})->with([
    'con un viaje vivo' => false,
    'con un viaje borrado' => true,
]);

it('rechaza con 400 borrar una naviera con viajes, aunque esos viajes estén borrados', function (bool $viajeBorrado) {
    $admin = userWithRole(UserRole::Administrator);
    $naviera = ShippingLine::factory()->create();

    $viajeBorrado
        ? Trip::factory()->trashed()->create(['shipping_line_id' => $naviera->id])
        : Trip::factory()->create(['shipping_line_id' => $naviera->id]);

    asUser($admin)->deleteJson("/api/shipping-lines/{$naviera->id}")
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'No se puede eliminar la naviera porque tiene viajes asociados',
            'data' => null,
        ]);

    expect($naviera->fresh()->deleted_at)->toBeNull();
})->with([
    'con un viaje vivo' => false,
    'con un viaje borrado' => true,
]);

it('sigue borrando con 200 un cliente y una naviera sin viajes', function () {
    $admin = userWithRole(UserRole::Administrator);
    $cliente = Client::factory()->create();
    $naviera = ShippingLine::factory()->create();

    asUser($admin)->deleteJson("/api/clients/{$cliente->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Cliente eliminado correctamente');

    asUser($admin)->deleteJson("/api/shipping-lines/{$naviera->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Naviera eliminada correctamente');

    $this->assertSoftDeleted('clients', ['id' => $cliente->id]);
    $this->assertSoftDeleted('shipping_lines', ['id' => $naviera->id]);
});

/*
|--------------------------------------------------------------------------
| Forma de la respuesta
|--------------------------------------------------------------------------
*/

it('devuelve 15 claves en el listado y las 31 del detalle, y no las confunde', function () {
    $admin = userWithRole(UserRole::Administrator);
    $team = tripTeam();
    $trip = tripAssignedTo($team, ['registered_by' => $admin->id]);

    $delListado = asUser($admin)->getJson('/api/trips')->assertOk()->json('data.0');
    $detalle = asUser($admin)->getJson("/api/trips/{$trip->id}")->assertOk()->json('data');

    expect(tripResourceKeys())->toHaveCount(31)
        ->and(tripListResourceKeys())->toHaveCount(15)
        ->and(array_keys($delListado))->toBe(tripListResourceKeys())
        ->and(array_keys($detalle))->toBe(tripResourceKeys());
});

it('deja fuera del listado las claves que solo pinta el detalle', function () {
    $admin = userWithRole(UserRole::Administrator);
    $team = tripTeam();
    tripAssignedTo($team, ['registered_by' => $admin->id]);

    $delListado = asUser($admin)->getJson('/api/trips')->assertOk()->json('data.0');

    expect(array_diff(tripResourceKeys(), tripListResourceKeys()))->not->toBeEmpty()
        ->and($delListado)->not->toHaveKeys([
            'clientId', 'clientName',
            'shippingLineId', 'departurePointId', 'locationId',
            'destination', 'transport',
            'polyline', 'points',
            'pilotId', 'vehicleId', 'assignedById', 'assignedByName',
            'createdAt', 'updatedAt', 'deletedAt',
        ]);
});

it('devuelve las seis relaciones como par id + nombre plano, nunca como objeto anidado', function () {
    $admin = userWithRole(UserRole::Administrator);
    $team = tripTeam();
    $trip = tripAssignedTo($team, ['registered_by' => $admin->id]);
    $trip->load(['client', 'shippingLine', 'departurePoint', 'location']);

    $data = asUser($admin)->getJson("/api/trips/{$trip->id}")->assertOk()->json('data');

    expect($data['clientId'])->toBe($trip->client_id)
        ->and($data['clientName'])->toBe($trip->client->name)
        ->and($data['shippingLineId'])->toBe($trip->shipping_line_id)
        ->and($data['shippingLineName'])->toBe($trip->shippingLine->name)
        ->and($data['departurePointId'])->toBe($trip->departure_point_id)
        ->and($data['departurePointName'])->toBe($trip->departurePoint->name)
        ->and($data['locationId'])->toBe($trip->location_id)
        ->and($data['locationName'])->toBe($trip->location->name)
        ->and($data['pilotId'])->toBe($team['pilot']->id)
        ->and($data['pilotName'])->toBe($team['pilot']->name)
        ->and($data['vehicleId'])->toBe($team['vehicle']->id)
        ->and($data['vehiclePlate'])->toBe($team['vehicle']->plate)
        ->and($data['assignedById'])->toBe($team['owner']->id)
        ->and($data['assignedByName'])->toBe($team['owner']->name)
        ->and($data['registeredByName'])->toBe($admin->name)
        /** Ni un solo objeto anidado: las seis relaciones salen aplanadas. */
        ->and(collect($data)->except('points')->filter(fn ($valor) => is_array($valor))->all())->toBe([]);
});

it('decodifica points desde la polilínea, igual que PolylineDecoder', function () {
    $admin = userWithRole(UserRole::Administrator);
    $trip = Trip::factory()->create(['polyline' => TripFactory::POLYLINE]);

    $data = asUser($admin)->getJson("/api/trips/{$trip->id}")->assertOk()->json('data');

    expect($data['polyline'])->toBe(TripFactory::POLYLINE)
        ->and($data['points'])->toEqual(PolylineDecoder::decode(TripFactory::POLYLINE))
        ->and($data['points'])->toHaveCount(3);
});

it('formatea las cuatro fechas en d-m-Y h:i:s A y deja en null las de ejecución sin ocurrir', function () {
    $admin = userWithRole(UserRole::Administrator);
    $trip = Trip::factory()->create([
        'recolection_date' => now()->addDays(3)->setTime(20, 45, 12),
        'ship_date' => now()->addDays(5)->setTime(8, 5, 40),
    ]);

    $data = asUser($admin)->getJson("/api/trips/{$trip->id}")->assertOk()->json('data');

    expect($data['recolectionDate'])->toBe($trip->recolection_date->format('d-m-Y h:i:s A'))
        ->and($data['recolectionDate'])->toMatch(tripDatePattern())
        ->and($data['shipDate'])->toMatch(tripDatePattern())
        ->and($data['createdAt'])->toMatch(tripDatePattern())
        ->and($data['updatedAt'])->toMatch(tripDatePattern())
        /** Nunca ISO 8601: parsearlas como tal falla. */
        ->and($data['recolectionDate'])->not->toContain('T')
        ->and($data['startDate'])->toBeNull()
        ->and($data['endDate'])->toBeNull();
});

it('devuelve el estado con el valor crudo del enum en inglés', function (string $estado) {
    $admin = userWithRole(UserRole::Administrator);
    $trip = Trip::factory()->create(['status' => $estado]);

    expect(asUser($admin)->getJson("/api/trips/{$trip->id}")->assertOk()->json('data.status'))->toBe($estado);
})->with(['pending', 'in_route', 'finished']);

it('devuelve deletedAt en null en los seis endpoints que lo pintan y no son el DELETE', function () {
    $admin = userWithRole(UserRole::Administrator);
    $team = tripTeam();

    $id = asUser($admin)->postJson('/api/trips', tripPayload())
        ->assertCreated()
        ->assertJsonPath('data.deletedAt', null)
        ->json('data.id');

    /** El listado ni siquiera trae la clave: los borrados no se listan. */
    expect(asUser($admin)->getJson('/api/trips')->assertOk()->json('data.0'))->not->toHaveKey('deletedAt')
        ->and(asUser($admin)->getJson("/api/trips/{$id}")->assertOk()->json('data.deletedAt'))->toBeNull()
        ->and(asUser($admin)->patchJson("/api/trips/{$id}", ['order' => 'ord-2026-9999'])->assertOk()->json('data.deletedAt'))->toBeNull()
        ->and(asUser($team['owner'])->patchJson("/api/trips/{$id}/assignment", [
            'pilotId' => $team['pilot']->id,
            'vehicleId' => $team['vehicle']->id,
        ])->assertOk()->json('data.deletedAt'))->toBeNull()
        ->and(asUser($team['pilot'])->patchJson("/api/trips/{$id}/start")->assertOk()->json('data.deletedAt'))->toBeNull()
        ->and(asUser($team['pilot'])->patchJson("/api/trips/{$id}/finish")->assertOk()->json('data.deletedAt'))->toBeNull();
});
