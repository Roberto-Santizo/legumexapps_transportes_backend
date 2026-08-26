<?php

use App\Enums\UserRole;
use App\Models\DeparturePoint;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Every departure points endpoint as method and URI, for the middleware datasets.
 *
 * @return array<string, array{string, string}>
 */
function departurePointEndpoints(): array
{
    return [
        'index' => ['GET', '/api/departure-points'],
        'store' => ['POST', '/api/departure-points'],
        'show' => ['GET', '/api/departure-points/1'],
        'update' => ['PATCH', '/api/departure-points/1'],
        'toggle-status' => ['PATCH', '/api/departure-points/1/toggle-status'],
        'destroy' => ['DELETE', '/api/departure-points/1'],
    ];
}

/**
 * The four endpoints restricted to the administrator.
 *
 * @return array<string, array{string, string}>
 */
function departurePointWriteEndpoints(): array
{
    return [
        'store' => ['POST', '/api/departure-points'],
        'update' => ['PATCH', '/api/departure-points/1'],
        'toggle-status' => ['PATCH', '/api/departure-points/1/toggle-status'],
        'destroy' => ['DELETE', '/api/departure-points/1'],
    ];
}

/**
 * The roles that may read the departure points but never write them.
 *
 * @return array<string, UserRole>
 */
function departurePointNonAdminRoles(): array
{
    return [
        'carrier' => UserRole::Carrier,
        'pilot' => UserRole::Pilot,
        'manager' => UserRole::Manager,
    ];
}

/**
 * Every role of the project: all four read the departure points.
 *
 * @return array<string, UserRole>
 */
function departurePointReaderRoles(): array
{
    return departurePointNonAdminRoles() + ['administrator' => UserRole::Administrator];
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
 * The ten keys DeparturePointResource promises, in the order the resource declares them.
 *
 * @return array<int, string>
 */
function departurePointResourceKeys(): array
{
    return ['id', 'name', 'description', 'googlePlaceId', 'latitude', 'longitude', 'status', 'registeredByName', 'createdAt', 'updatedAt'];
}

/**
 * A valid store payload, with the four mandatory fields already filled in.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function departurePointPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'bodega central',
        'googlePlaceId' => 'ChIJd8BlQ2BZwokRAFUEcm_qrcA',
        'latitude' => 14.6349,
        'longitude' => -90.5069,
    ], $overrides);
}

/**
 * La expresión del formato de fecha `d-m-Y h:i:s A` que promete el DeparturePointResource.
 */
function departurePointDatePattern(): string
{
    return '/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} (AM|PM)$/';
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth y role
|--------------------------------------------------------------------------
*/

it('rechaza con 401 cualquier endpoint de puntos de partida sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(departurePointEndpoints());

it('rechaza con 403 a quien no es administrador en los endpoints de escritura', function (string $method, string $uri, UserRole $role) {
    asUser(userWithRole($role))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(departurePointWriteEndpoints())->with(departurePointNonAdminRoles());

it('no crea, modifica ni da de baja nada cuando un no administrador intenta escribir', function (UserRole $role) {
    $departurePoint = DeparturePoint::factory()->active()->create(['name' => 'BODEGA CENTRAL']);
    $user = userWithRole($role);

    asUser($user)->postJson('/api/departure-points', departurePointPayload(['name' => 'bodega sur']))->assertForbidden();
    asUser($user)->patchJson("/api/departure-points/{$departurePoint->id}", ['name' => 'otro nombre'])->assertForbidden();
    asUser($user)->patchJson("/api/departure-points/{$departurePoint->id}/toggle-status")->assertForbidden();
    asUser($user)->deleteJson("/api/departure-points/{$departurePoint->id}")->assertForbidden();

    expect(DeparturePoint::query()->count())->toBe(1)
        ->and($departurePoint->fresh()->name)->toBe('BODEGA CENTRAL')
        ->and($departurePoint->fresh()->status)->toBeTrue();
})->with(departurePointNonAdminRoles());

it('deja leer los puntos de partida a cualquier rol, incluido uno sin empresa', function (UserRole $role) {
    $departurePoint = DeparturePoint::factory()->create();
    $user = userWithRole($role);

    /** Ninguna ruta lleva carrier.required: los puntos de partida son nacionales. */
    expect($user->currentCarrier())->toBeNull();

    asUser($user)->getJson('/api/departure-points')
        ->assertOk()
        ->assertJsonPath('message', 'Puntos de partida obtenidos correctamente');

    asUser($user)->getJson("/api/departure-points/{$departurePoint->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Punto de partida obtenido correctamente');
})->with(departurePointReaderRoles());

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('registra un punto de partida con los cuatro campos obligatorios y lo hace nacer activo', function () {
    $admin = userWithRole(UserRole::Administrator);

    $response = asUser($admin)->postJson('/api/departure-points', departurePointPayload())
        ->assertCreated()
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Punto de partida registrado correctamente')
        ->assertJsonPath('data.status', true)
        ->assertJsonPath('data.registeredByName', $admin->name);

    expect(array_keys($response->json('data')))->toBe(departurePointResourceKeys());

    $this->assertDatabaseHas('departure_points', [
        'id' => $response->json('data.id'),
        'name' => 'BODEGA CENTRAL',
        'google_place_id' => 'ChIJd8BlQ2BZwokRAFUEcm_qrcA',
        'status' => true,
        'registered_by' => $admin->id,
    ]);
});

it('deja la descripción en null cuando el alta no la manda', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/departure-points', departurePointPayload())
        ->assertCreated()
        ->assertJsonPath('data.description', null);

    $this->assertDatabaseHas('departure_points', ['name' => 'BODEGA CENTRAL', 'description' => null]);
});

it('guarda la descripción cuando el alta la manda', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/departure-points', departurePointPayload(['description' => 'Salida de camiones de la zona 12']))
        ->assertCreated()
        ->assertJsonPath('data.description', 'Salida de camiones de la zona 12');
});

it('guarda el nombre en mayúsculas y con los espacios internos colapsados', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/departure-points', departurePointPayload(['name' => '  bodega   central ']))
        ->assertCreated()
        ->assertJsonPath('data.name', 'BODEGA CENTRAL');

    $this->assertDatabaseHas('departure_points', ['name' => 'BODEGA CENTRAL']);
});

it('rechaza con 422 el nombre repetido en otra caja sin llegar al índice único', function () {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/departure-points', departurePointPayload())->assertCreated();

    /**
     * El nombre se normaliza en prepareForValidation, así que la regla unique lo caza como
     * 422 antes de que el service llegue a lanzar su 400. Lo que se defiende aquí es que
     * el duplicado nunca es el 500 del índice único.
     */
    asUser($admin)->postJson('/api/departure-points', departurePointPayload([
        'name' => 'Bodega Central',
        'googlePlaceId' => 'ChIJotroLugarDeGoogle0001',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name'])
        ->assertJsonFragment(['Ya existe un punto de partida con ese nombre']);

    expect(DeparturePoint::query()->count())->toBe(1);
});

it('rechaza con 400 un googlePlaceId que ya ocupa otro punto de partida, nombrándolo', function () {
    $admin = userWithRole(UserRole::Administrator);

    $ocupante = asUser($admin)->postJson('/api/departure-points', departurePointPayload())
        ->assertCreated()
        ->json('data.name');

    /**
     * Sin regla unique en el FormRequest, la unicidad del lugar la decide el service: por eso
     * es 400 y no 422, y por eso el mensaje puede decir cuál es el punto que ya lo ocupa,
     * que es lo único que le sirve a quien está capturando el duplicado.
     */
    asUser($admin)->postJson('/api/departure-points', departurePointPayload(['name' => 'bodega sur']))
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El lugar seleccionado ya está registrado en el punto de partida '.$ocupante,
            'data' => null,
        ]);

    expect(DeparturePoint::query()->count())->toBe(1);
});

it('acepta un googlePlaceId que ya existe como destino: no hay validación cruzada con locations', function () {
    $googlePlaceId = 'ChIJd8BlQ2BZwokRAFUEcm_qrcA';

    Location::factory()->create(['google_place_id' => $googlePlaceId]);

    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/departure-points', departurePointPayload(['googlePlaceId' => $googlePlaceId]))
        ->assertCreated()
        ->assertJsonPath('data.googlePlaceId', $googlePlaceId);

    expect(DeparturePoint::query()->count())->toBe(1)
        ->and(Location::query()->count())->toBe(1);
});

it('acepta un nombre que ya existe como destino: las dos tablas son independientes', function () {
    Location::factory()->create(['name' => 'BODEGA CENTRAL']);

    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/departure-points', departurePointPayload(['name' => 'bodega central']))
        ->assertCreated()
        ->assertJsonPath('data.name', 'BODEGA CENTRAL');
});

it('guarda el googlePlaceId tal cual llega, sin pasarlo a mayúsculas', function () {
    $googlePlaceId = 'ChIJd8BlQ2BZwokRAFUEcm_qrcA';

    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/departure-points', departurePointPayload(['googlePlaceId' => $googlePlaceId]))
        ->assertCreated()
        ->assertJsonPath('data.googlePlaceId', $googlePlaceId);

    $this->assertDatabaseHas('departure_points', ['google_place_id' => $googlePlaceId]);
});

it('trata como distintos dos googlePlaceId que solo difieren en la caja', function () {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/departure-points', departurePointPayload(['googlePlaceId' => 'ChIJabc0001']))->assertCreated();

    asUser($admin)->postJson('/api/departure-points', departurePointPayload([
        'name' => 'bodega sur',
        'googlePlaceId' => 'CHIJABC0001',
    ]))->assertCreated();

    expect(DeparturePoint::query()->count())->toBe(2);
});

it('rechaza con 422 unas coordenadas fuera de rango o no numéricas', function (array $payload, string $campo) {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/departure-points', departurePointPayload($payload))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo]);
})->with([
    'latitud 91' => [['latitude' => 91], 'latitude'],
    'latitud -91' => [['latitude' => -91], 'latitude'],
    'latitud no numérica' => [['latitude' => 'norte'], 'latitude'],
    'longitud 181' => [['longitude' => 181], 'longitude'],
    'longitud -181' => [['longitude' => -181], 'longitude'],
    'longitud no numérica' => [['longitude' => 'oeste'], 'longitude'],
]);

it('rechaza con 422 el alta sin alguno de los campos obligatorios', function (string $campo) {
    $payload = departurePointPayload();

    unset($payload[$campo]);

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/departure-points', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo]);
})->with(['name', 'googlePlaceId', 'latitude', 'longitude']);

it('devuelve las coordenadas como texto con ocho decimales', function () {
    $response = asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/departure-points', departurePointPayload(['latitude' => 14.6349, 'longitude' => -90.5069]))
        ->assertCreated();

    expect($response->json('data.latitude'))->toBeString()->toBe('14.63490000')
        ->and($response->json('data.longitude'))->toBeString()->toBe('-90.50690000');
});

it('ignora el status que llegue en el alta: el punto de partida nace activo', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/departure-points', departurePointPayload(['status' => false]))
        ->assertCreated()
        ->assertJsonPath('data.status', true);

    $this->assertDatabaseHas('departure_points', ['name' => 'BODEGA CENTRAL', 'status' => true]);
});

it('registra al usuario autenticado aunque el body mande otro responsable', function () {
    $admin = userWithRole(UserRole::Administrator);
    $otro = userWithRole(UserRole::Administrator);

    $response = asUser($admin)->postJson('/api/departure-points', departurePointPayload([
        'registeredBy' => $otro->id,
        'registered_by' => $otro->id,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.registeredByName', $admin->name);

    $this->assertDatabaseHas('departure_points', [
        'id' => $response->json('data.id'),
        'registered_by' => $admin->id,
    ]);
});

/*
|--------------------------------------------------------------------------
| Detalle
|--------------------------------------------------------------------------
*/

it('consulta con 200 un punto de partida inactivo', function () {
    $admin = userWithRole(UserRole::Administrator);
    $departurePoint = DeparturePoint::factory()->inactive()->create(['registered_by' => $admin->id]);

    asUser($admin)->getJson("/api/departure-points/{$departurePoint->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $departurePoint->id)
        ->assertJsonPath('data.status', false);
});

/*
|--------------------------------------------------------------------------
| Edición
|--------------------------------------------------------------------------
*/

it('no toca las coordenadas ni el googlePlaceId cuando el PATCH solo manda el nombre', function () {
    $admin = userWithRole(UserRole::Administrator);
    $departurePoint = DeparturePoint::factory()->create([
        'name' => 'BODEGA CENTRAL',
        'google_place_id' => 'ChIJabc0001',
        'latitude' => 14.6349,
        'longitude' => -90.5069,
        'registered_by' => $admin->id,
    ]);

    asUser($admin)->patchJson("/api/departure-points/{$departurePoint->id}", ['name' => '  bodega   norte '])
        ->assertOk()
        ->assertJsonPath('message', 'Punto de partida actualizado correctamente')
        ->assertJsonPath('data.name', 'BODEGA NORTE')
        ->assertJsonPath('data.googlePlaceId', 'ChIJabc0001')
        ->assertJsonPath('data.latitude', '14.63490000')
        ->assertJsonPath('data.longitude', '-90.50690000');
});

it('acepta los seis campos editables en una sola llamada', function () {
    $admin = userWithRole(UserRole::Administrator);
    $departurePoint = DeparturePoint::factory()->active()->create(['registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/departure-points/{$departurePoint->id}", [
        'name' => 'bodega norte',
        'description' => 'la nueva',
        'googlePlaceId' => 'ChIJabc9999',
        'latitude' => 15.5,
        'longitude' => -91.25,
        'status' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'BODEGA NORTE')
        ->assertJsonPath('data.description', 'la nueva')
        ->assertJsonPath('data.googlePlaceId', 'ChIJabc9999')
        ->assertJsonPath('data.latitude', '15.50000000')
        ->assertJsonPath('data.longitude', '-91.25000000')
        ->assertJsonPath('data.status', false);
});

it('mueve las coordenadas conservando el id del punto de partida', function () {
    $admin = userWithRole(UserRole::Administrator);
    $departurePoint = DeparturePoint::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/departure-points/{$departurePoint->id}", ['latitude' => 15.5, 'longitude' => -91.25])
        ->assertOk()
        ->assertJsonPath('data.id', $departurePoint->id)
        ->assertJsonPath('data.latitude', '15.50000000')
        ->assertJsonPath('data.longitude', '-91.25000000');

    $this->assertDatabaseHas('departure_points', ['id' => $departurePoint->id, 'latitude' => '15.50000000']);
});

it('reapunta el punto a un lugar libre sin tocar el pin y rechaza el lugar de otro punto', function () {
    $admin = userWithRole(UserRole::Administrator);
    $departurePoint = DeparturePoint::factory()->create([
        'google_place_id' => 'ChIJabc0001',
        'latitude' => 14.6349,
        'longitude' => -90.5069,
        'registered_by' => $admin->id,
    ]);
    DeparturePoint::factory()->create(['google_place_id' => 'ChIJabc0002', 'registered_by' => $admin->id]);

    /** Sin validación cruzada: cambiar solo el lugar es válido y deja el pin anterior. */
    asUser($admin)->patchJson("/api/departure-points/{$departurePoint->id}", ['googlePlaceId' => 'ChIJabc0003'])
        ->assertOk()
        ->assertJsonPath('data.id', $departurePoint->id)
        ->assertJsonPath('data.googlePlaceId', 'ChIJabc0003')
        ->assertJsonPath('data.latitude', '14.63490000')
        ->assertJsonPath('data.longitude', '-90.50690000');

    asUser($admin)->patchJson("/api/departure-points/{$departurePoint->id}", ['googlePlaceId' => 'ChIJabc0002'])
        ->assertStatus(400)
        ->assertJsonPath('statusCode', 400)
        ->assertJsonPath('data', null);
});

it('acepta que un punto de partida reenvíe su propio googlePlaceId y su propio nombre', function () {
    $admin = userWithRole(UserRole::Administrator);
    $departurePoint = DeparturePoint::factory()->create([
        'name' => 'BODEGA CENTRAL',
        'google_place_id' => 'ChIJabc0001',
        'registered_by' => $admin->id,
    ]);

    asUser($admin)->patchJson("/api/departure-points/{$departurePoint->id}", [
        'name' => 'bodega central',
        'googlePlaceId' => 'ChIJabc0001',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'BODEGA CENTRAL')
        ->assertJsonPath('data.googlePlaceId', 'ChIJabc0001');
});

it('borra la descripción cuando el PATCH manda null y la deja intacta cuando la omite', function () {
    $admin = userWithRole(UserRole::Administrator);
    $departurePoint = DeparturePoint::factory()->create(['description' => 'la de siempre', 'registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/departure-points/{$departurePoint->id}", ['name' => 'bodega norte'])
        ->assertOk()
        ->assertJsonPath('data.description', 'la de siempre');

    asUser($admin)->patchJson("/api/departure-points/{$departurePoint->id}", ['description' => null])
        ->assertOk()
        ->assertJsonPath('data.description', null);

    $this->assertDatabaseHas('departure_points', ['id' => $departurePoint->id, 'description' => null]);
});

it('acepta un PATCH con el cuerpo vacío como no-op', function () {
    $admin = userWithRole(UserRole::Administrator);
    $departurePoint = DeparturePoint::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/departure-points/{$departurePoint->id}", [])
        ->assertOk()
        ->assertJsonPath('data.name', $departurePoint->name)
        ->assertJsonPath('data.googlePlaceId', $departurePoint->google_place_id)
        ->assertJsonPath('data.status', $departurePoint->status);
});

it('no reescribe al responsable del alta aunque el PATCH lo mande', function () {
    $admin = userWithRole(UserRole::Administrator);
    $otro = userWithRole(UserRole::Administrator);
    $departurePoint = DeparturePoint::factory()->create(['registered_by' => $admin->id]);

    asUser($otro)->patchJson("/api/departure-points/{$departurePoint->id}", [
        'name' => 'bodega norte',
        'registeredBy' => $otro->id,
        'registered_by' => $otro->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.registeredByName', $admin->name);

    $this->assertDatabaseHas('departure_points', ['id' => $departurePoint->id, 'registered_by' => $admin->id]);
});

/*
|--------------------------------------------------------------------------
| Baja lógica y toggle
|--------------------------------------------------------------------------
*/

it('da de baja de forma idempotente sin borrar la fila', function () {
    $admin = userWithRole(UserRole::Administrator);
    $departurePoint = DeparturePoint::factory()->active()->create(['registered_by' => $admin->id]);

    asUser($admin)->deleteJson("/api/departure-points/{$departurePoint->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Punto de partida dado de baja correctamente')
        ->assertJsonPath('data.status', false);

    asUser($admin)->deleteJson("/api/departure-points/{$departurePoint->id}")
        ->assertOk()
        ->assertJsonPath('data.status', false);

    $this->assertDatabaseHas('departure_points', ['id' => $departurePoint->id, 'status' => false]);
});

it('sigue listando los puntos de partida dados de baja cuando no hay filtro de estado', function () {
    $admin = userWithRole(UserRole::Administrator);
    $departurePoint = DeparturePoint::factory()->active()->create(['registered_by' => $admin->id]);

    asUser($admin)->deleteJson("/api/departure-points/{$departurePoint->id}")->assertOk();

    expect(asUser($admin)->getJson('/api/departure-points')->assertOk()->json('data'))->toHaveCount(1);
});

it('alterna el estado del punto de partida en los dos sentidos', function () {
    $admin = userWithRole(UserRole::Administrator);
    $departurePoint = DeparturePoint::factory()->inactive()->create(['registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/departure-points/{$departurePoint->id}/toggle-status")
        ->assertOk()
        ->assertJsonPath('message', 'Estado del punto de partida actualizado correctamente')
        ->assertJsonPath('data.status', true);

    asUser($admin)->patchJson("/api/departure-points/{$departurePoint->id}/toggle-status")
        ->assertOk()
        ->assertJsonPath('data.status', false);
});

it('responde 404 en las cuatro acciones que resuelven el punto de partida por id', function (string $method, string $uri) {
    asUser(userWithRole(UserRole::Administrator))->json($method, $uri)
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El punto de partida no existe',
            'data' => null,
        ]);
})->with([
    'show' => ['GET', '/api/departure-points/9999'],
    'update' => ['PATCH', '/api/departure-points/9999'],
    'toggle-status' => ['PATCH', '/api/departure-points/9999/toggle-status'],
    'destroy' => ['DELETE', '/api/departure-points/9999'],
]);

/*
|--------------------------------------------------------------------------
| Listado y paginación
|--------------------------------------------------------------------------
*/

it('devuelve la colección completa y ninguna clave de paginación sin limit', function () {
    $admin = userWithRole(UserRole::Administrator);
    DeparturePoint::factory()->count(12)->create(['registered_by' => $admin->id]);

    $response = asUser($admin)->getJson('/api/departure-points')->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12)
        ->and($response->json('message'))->toBe('Puntos de partida obtenidos correctamente');
});

it('devuelve 200 con el listado vacío cuando el catálogo está vacío', function () {
    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/departure-points')->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('devuelve los metadatos de paginación en la raíz del sobre', function () {
    $admin = userWithRole(UserRole::Administrator);
    DeparturePoint::factory()->count(3)->create(['registered_by' => $admin->id]);

    $response = asUser($admin)->getJson('/api/departure-points?limit=10')->assertOk();

    expect($response->json())->toHaveKeys(['statusCode', 'message', 'data', 'total', 'currentPage', 'lastPage'])
        ->and($response->json('total'))->toBe(3)
        ->and($response->json('currentPage'))->toBe(1)
        ->and($response->json('lastPage'))->toBe(1)
        ->and($response->json('meta'))->toBeNull();
});

it('acota el tamaño de página a [10, 100] también por HTTP', function () {
    $admin = userWithRole(UserRole::Administrator);

    /** Los 101 puntos comparten registrador: lo que se mide aquí es el tamaño de página. */
    DeparturePoint::factory()->count(101)->create(['registered_by' => $admin->id]);

    $porDebajo = asUser($admin)->getJson('/api/departure-points?limit=1')->assertOk();
    $porEncima = asUser($admin)->getJson('/api/departure-points?limit=500')->assertOk();

    expect($porDebajo->json('data'))->toHaveCount(10)
        ->and($porDebajo->json('lastPage'))->toBe(11)
        ->and($porEncima->json('data'))->toHaveCount(100)
        ->and($porEncima->json('lastPage'))->toBe(2)
        ->and($porEncima->json('total'))->toBe(101);
});

it('pagina de cinco en cinco subiendo el limit al mínimo de diez', function () {
    $admin = userWithRole(UserRole::Administrator);
    DeparturePoint::factory()->count(12)->create(['registered_by' => $admin->id]);

    $response = asUser($admin)->getJson('/api/departure-points?limit=5')->assertOk();

    expect($response->json('data'))->toHaveCount(10)
        ->and($response->json('total'))->toBe(12)
        ->and($response->json('lastPage'))->toBe(2);
});

it('no pagina cuando el limit no es numérico', function () {
    $admin = userWithRole(UserRole::Administrator);
    DeparturePoint::factory()->count(12)->create(['registered_by' => $admin->id]);

    $response = asUser($admin)->getJson('/api/departure-points?limit=abc')->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12);
});

it('filtra por estado e ignora un valor que no es booleano', function () {
    $admin = userWithRole(UserRole::Administrator);
    DeparturePoint::factory()->active()->create(['registered_by' => $admin->id]);
    DeparturePoint::factory()->inactive()->create(['registered_by' => $admin->id]);

    $user = userWithRole(UserRole::Carrier);

    expect(asUser($user)->getJson('/api/departure-points?status=true')->assertOk()->json('data'))->toHaveCount(1)
        ->and(asUser($user)->getJson('/api/departure-points?status=false')->assertOk()->json('data'))->toHaveCount(1)
        ->and(asUser($user)->getJson('/api/departure-points?status=quizas')->assertOk()->json('data'))->toHaveCount(2);
});

it('busca por nombre sin distinguir mayúsculas', function (string $search) {
    $admin = userWithRole(UserRole::Administrator);
    DeparturePoint::factory()->create(['name' => 'BODEGA CENTRAL', 'registered_by' => $admin->id]);
    DeparturePoint::factory()->create(['name' => 'PLANTA DE EMPAQUE', 'registered_by' => $admin->id]);

    $data = asUser(userWithRole(UserRole::Pilot))->getJson('/api/departure-points?search='.urlencode($search))
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('BODEGA CENTRAL');
})->with([
    'en minúsculas' => 'bodega',
    'en mayúsculas' => 'BODEGA',
    'capitalizado' => 'Bodega',
    'con espacios internos de sobra' => 'bodega   central',
]);

it('devuelve el listado completo cuando el término de búsqueda viene vacío o en blanco', function (string $query) {
    $admin = userWithRole(UserRole::Administrator);
    DeparturePoint::factory()->count(2)->create(['registered_by' => $admin->id]);

    expect(asUser($admin)->getJson('/api/departure-points?search='.$query)->assertOk()->json('data'))->toHaveCount(2);
})->with([
    'vacío' => '',
    'solo espacios' => '%20%20',
]);

it('devuelve los puntos de partida ordenados por id ascendente', function () {
    $admin = userWithRole(UserRole::Administrator);
    $departurePoints = DeparturePoint::factory()->count(5)->create(['registered_by' => $admin->id]);

    $ids = $departurePoints->pluck('id')->sort()->values()->all();

    expect(collect(asUser($admin)->getJson('/api/departure-points')->assertOk()->json('data'))->pluck('id')->all())
        ->toBe($ids);
});

it('no dispara N+1 al listar puntos de partida de muchos registradores', function () {
    DeparturePoint::factory()->count(20)->create();

    /** El token se emite antes de escuchar: sus claims consultan la empresa del usuario. */
    $token = JWTAuth::fromUser(userWithRole(UserRole::Administrator));

    resetAuthState();

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withToken($token)->getJson('/api/departure-points')->assertOk()->assertJsonCount(20, 'data');

    $sobrePuntos = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "departure_points"'));
    $sobreUsuarios = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "users"'));

    /** Una consulta por el listado y otra por la relación: la del usuario autenticado es aparte. */
    expect($sobrePuntos)->toHaveCount(1)
        ->and($sobreUsuarios->count())->toBeLessThanOrEqual(2);
});

/*
|--------------------------------------------------------------------------
| Forma de la respuesta
|--------------------------------------------------------------------------
*/

it('devuelve las diez claves en camelCase y las fechas con el formato de la spec', function () {
    $admin = userWithRole(UserRole::Administrator);
    $departurePoint = DeparturePoint::factory()->create(['registered_by' => $admin->id]);

    $detalle = asUser($admin)->getJson("/api/departure-points/{$departurePoint->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Punto de partida obtenido correctamente')
        ->json('data');

    $delListado = asUser($admin)->getJson('/api/departure-points')->assertOk()->json('data.0');

    expect(array_keys($detalle))->toBe(departurePointResourceKeys())
        ->and(array_keys($delListado))->toBe(departurePointResourceKeys())
        ->and($detalle['createdAt'])->toMatch(departurePointDatePattern())
        ->and($detalle['updatedAt'])->toMatch(departurePointDatePattern())
        ->and($detalle['registeredByName'])->toBe($admin->name);
});

it('formatea las fechas como d-m-Y h:i:s A', function () {
    $admin = userWithRole(UserRole::Administrator);
    $departurePoint = DeparturePoint::factory()->create([
        'registered_by' => $admin->id,
        'created_at' => Carbon::parse('2026-08-26 20:45:12'),
        'updated_at' => Carbon::parse('2026-08-26 20:51:40'),
    ]);

    asUser($admin)->getJson("/api/departure-points/{$departurePoint->id}")
        ->assertOk()
        ->assertJsonPath('data.createdAt', '26-08-2026 08:45:12 PM')
        ->assertJsonPath('data.updatedAt', '26-08-2026 08:51:40 PM');
});

it('devuelve las coordenadas con ocho decimales en todos los endpoints que responden con un punto', function () {
    $admin = userWithRole(UserRole::Administrator);
    $id = asUser($admin)->postJson('/api/departure-points', departurePointPayload())->assertCreated()->json('data.id');

    expect(asUser($admin)->getJson('/api/departure-points')->assertOk()->json('data.0.latitude'))->toBe('14.63490000')
        ->and(asUser($admin)->getJson("/api/departure-points/{$id}")->assertOk()->json('data.latitude'))->toBe('14.63490000')
        ->and(asUser($admin)->patchJson("/api/departure-points/{$id}", ['description' => 'otra'])->assertOk()->json('data.longitude'))->toBe('-90.50690000')
        ->and(asUser($admin)->patchJson("/api/departure-points/{$id}/toggle-status")->assertOk()->json('data.longitude'))->toBe('-90.50690000')
        ->and(asUser($admin)->deleteJson("/api/departure-points/{$id}")->assertOk()->json('data.longitude'))->toBe('-90.50690000');
});
