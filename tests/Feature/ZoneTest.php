<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Every zones endpoint as method and URI, for the middleware datasets.
 *
 * @return array<string, array{string, string}>
 */
function zoneEndpoints(): array
{
    return [
        'index' => ['GET', '/api/zones'],
        'store' => ['POST', '/api/zones'],
        'show' => ['GET', '/api/zones/1'],
        'update' => ['PATCH', '/api/zones/1'],
        'toggle-status' => ['PATCH', '/api/zones/1/toggle-status'],
        'destroy' => ['DELETE', '/api/zones/1'],
    ];
}

/**
 * The four endpoints restricted to the administrator.
 *
 * @return array<string, array{string, string}>
 */
function zoneWriteEndpoints(): array
{
    return [
        'store' => ['POST', '/api/zones'],
        'update' => ['PATCH', '/api/zones/1'],
        'toggle-status' => ['PATCH', '/api/zones/1/toggle-status'],
        'destroy' => ['DELETE', '/api/zones/1'],
    ];
}

/**
 * The roles that may read the zones but never write them.
 *
 * @return array<string, UserRole>
 */
function zoneNonAdminRoles(): array
{
    return [
        'carrier' => UserRole::Carrier,
        'pilot' => UserRole::Pilot,
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
 * The nine keys ZoneResource promises, in the order the resource declares them.
 *
 * @return array<int, string>
 */
function zoneResourceKeys(): array
{
    return ['id', 'name', 'description', 'color', 'area', 'status', 'registeredByName', 'createdAt', 'updatedAt'];
}

/**
 * The reference triangle of the spec, around Guatemala City.
 *
 * @return array<int, array{0: float, 1: float}>
 */
function zoneTrianglePairs(): array
{
    return [[14.6349, -90.5069], [14.6402, -90.4998], [14.6281, -90.4931]];
}

/**
 * Un cuadrado de un grado con esquina inferior izquierda en el punto dado.
 *
 * @return array<int, array{0: float, 1: float}>
 */
function zoneSquarePairs(float $latitude, float $longitude): array
{
    return [
        [$latitude, $longitude],
        [$latitude + 1.0, $longitude],
        [$latitude + 1.0, $longitude + 1.0],
        [$latitude, $longitude + 1.0],
    ];
}

/**
 * La expresión del formato de fecha `d-m-Y h:i:s A` que promete el ZoneResource.
 */
function zoneDatePattern(): string
{
    return '/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} (AM|PM)$/';
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth y role
|--------------------------------------------------------------------------
*/

it('rechaza con 401 cualquier endpoint de zonas sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(zoneEndpoints());

it('rechaza con 403 a quien no es administrador en los endpoints de escritura', function (string $method, string $uri, UserRole $role) {
    asUser(userWithRole($role))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(zoneWriteEndpoints())->with(zoneNonAdminRoles());

it('deja leer a cualquier rol sin empresa', function (UserRole $role) {
    $zone = Zone::factory()->create();

    asUser(userWithRole($role))->getJson('/api/zones')->assertOk();
    asUser(userWithRole($role))->getJson("/api/zones/{$zone->id}")->assertOk();
})->with(zoneNonAdminRoles());

/*
|--------------------------------------------------------------------------
| Alta y lectura del polígono
|--------------------------------------------------------------------------
*/

it('guarda el polígono y lo devuelve tal cual, sin el punto de cierre', function () {
    $response = asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/zones', ['name' => 'zona norte', 'area' => zoneTrianglePairs()])
        ->assertCreated();

    expect($response->json('data.area'))->toBe(zoneTrianglePairs())
        ->and($response->json('data.name'))->toBe('ZONA NORTE')
        ->and($response->json('data.color'))->toBe('#3388FF')
        ->and(array_keys($response->json('data')))->toBe(zoneResourceKeys());

    $zoneId = $response->json('data.id');

    expect(asUser(userWithRole(UserRole::Administrator))->getJson("/api/zones/{$zoneId}")->json('data.area'))
        ->toBe(zoneTrianglePairs());
});

it('rechaza con 422 un polígono mal formado', function (array $area) {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/zones', ['name' => 'zona norte', 'area' => $area])
        ->assertStatus(422);
})->with([
    'dos puntos' => [[[14.6349, -90.5069], [14.6402, -90.4998]]],
    'latitud fuera de rango' => [[[98, -14], [14.6402, -90.4998], [14.6281, -90.4931]]],
    'par de tres elementos' => [[[14.6349, -90.5069, 3], [14.6402, -90.4998], [14.6281, -90.4931]]],
]);

it('explica qué punto falla cuando se invierte el par', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/zones', ['name' => 'zona norte', 'area' => [[14.6349, -90.5069], [98, -14], [14.6281, -90.4931]]])
        ->assertStatus(422)
        ->assertJsonFragment(['La latitud del punto 2 debe estar entre -90 y 90']);
});

it('rechaza el nombre repetido y el color inválido sin llegar al índice único', function () {
    $admin = userWithRole(UserRole::Administrator);
    Zone::factory()->create(['name' => 'ZONA NORTE']);

    asUser($admin)->postJson('/api/zones', ['name' => 'zona norte', 'area' => zoneTrianglePairs()])
        ->assertStatus(422);

    asUser($admin)->postJson('/api/zones', ['name' => 'zona sur', 'color' => 'rojo', 'area' => zoneTrianglePairs()])
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Punto en zona
|--------------------------------------------------------------------------
*/

it('filtra el listado por el punto que contiene la zona', function () {
    $square = [[14.0, -91.0], [15.0, -91.0], [15.0, -90.0], [14.0, -90.0]];
    $inside = Zone::factory()->withArea($square)->create();
    Zone::factory()->withArea([[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]])->create();

    $user = userWithRole(UserRole::Pilot);

    expect(asUser($user)->getJson('/api/zones?lat=14.5&lng=-90.5')->assertOk()->json('data'))
        ->toHaveCount(1)
        ->and(asUser($user)->getJson('/api/zones?lat=14.5&lng=-90.5')->json('data.0.id'))->toBe($inside->id)
        ->and(asUser($user)->getJson('/api/zones?lat=-33&lng=18')->assertOk()->json('data'))->toBe([])
        ->and(asUser($user)->getJson('/api/zones?lat=200&lng=-90.5')->assertOk()->json('data'))->toHaveCount(2)
        ->and(asUser($user)->getJson('/api/zones?lat=14.5')->assertOk()->json('data'))->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Baja lógica y toggle
|--------------------------------------------------------------------------
*/

it('da de baja de forma idempotente sin sacar la zona del listado', function () {
    $admin = userWithRole(UserRole::Administrator);
    $zone = Zone::factory()->active()->create();

    asUser($admin)->deleteJson("/api/zones/{$zone->id}")->assertOk()->assertJsonPath('data.status', false);
    asUser($admin)->deleteJson("/api/zones/{$zone->id}")->assertOk()->assertJsonPath('data.status', false);

    expect(asUser($admin)->getJson('/api/zones')->json('data'))->toHaveCount(1);
});

it('alterna el estado de la zona', function () {
    $admin = userWithRole(UserRole::Administrator);
    $zone = Zone::factory()->inactive()->create();

    asUser($admin)->patchJson("/api/zones/{$zone->id}/toggle-status")->assertOk()->assertJsonPath('data.status', true);
    asUser($admin)->patchJson("/api/zones/{$zone->id}/toggle-status")->assertOk()->assertJsonPath('data.status', false);
});

it('acepta un PATCH vacío como no-op y responde 404 sobre un id inexistente', function () {
    $admin = userWithRole(UserRole::Administrator);
    $zone = Zone::factory()->create();

    asUser($admin)->patchJson("/api/zones/{$zone->id}", [])->assertOk()->assertJsonPath('data.name', $zone->name);

    asUser($admin)->patchJson('/api/zones/9999', [])->assertNotFound()->assertJsonPath('message', 'La zona no existe');
});

/*
|--------------------------------------------------------------------------
| Paginación
|--------------------------------------------------------------------------
*/

it('devuelve los metadatos de paginación en la raíz del sobre', function () {
    Zone::factory()->count(3)->create();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/zones?limit=10')->assertOk();

    expect($response->json())->toHaveKeys(['statusCode', 'message', 'data', 'total', 'currentPage', 'lastPage'])
        ->and($response->json('total'))->toBe(3);
});

it('devuelve la colección completa y ninguna clave de paginación sin limit', function () {
    Zone::factory()->count(12)->create();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/zones')->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12)
        ->and($response->json('message'))->toBe('Zonas obtenidas correctamente');
});

it('acota el tamaño de página a [10, 100] también por HTTP', function () {
    $admin = userWithRole(UserRole::Administrator);

    /** Las 101 zonas comparten registrador: lo que se mide aquí es el tamaño de página. */
    Zone::factory()->count(101)->create(['registered_by' => $admin->id]);

    $porDebajo = asUser($admin)->getJson('/api/zones?limit=5')->assertOk();
    $porEncima = asUser($admin)->getJson('/api/zones?limit=500')->assertOk();

    expect($porDebajo->json('data'))->toHaveCount(10)
        ->and($porDebajo->json('lastPage'))->toBe(11)
        ->and($porEncima->json('data'))->toHaveCount(100)
        ->and($porEncima->json('lastPage'))->toBe(2)
        ->and($porEncima->json('total'))->toBe(101);
});

/*
|--------------------------------------------------------------------------
| Filtros del listado
|--------------------------------------------------------------------------
*/

it('busca por nombre sin distinguir mayúsculas', function (string $search) {
    Zone::factory()->create(['name' => 'ZONA NORTE']);
    Zone::factory()->create(['name' => 'ZONA SUR']);

    $data = asUser(userWithRole(UserRole::Pilot))->getJson("/api/zones?search={$search}")->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('ZONA NORTE');
})->with([
    'en mayúsculas' => 'NOR',
    'en minúsculas' => 'nor',
    'la palabra entera' => 'Norte',
]);

it('devuelve el listado completo cuando el término de búsqueda viene en blanco', function () {
    Zone::factory()->count(2)->create();

    expect(asUser(userWithRole(UserRole::Pilot))->getJson('/api/zones?search=%20%20')->assertOk()->json('data'))
        ->toHaveCount(2);
});

it('filtra por estado e ignora un valor que no es booleano', function () {
    Zone::factory()->active()->create();
    Zone::factory()->inactive()->create();

    $user = userWithRole(UserRole::Carrier);

    expect(asUser($user)->getJson('/api/zones?status=true')->assertOk()->json('data'))->toHaveCount(1)
        ->and(asUser($user)->getJson('/api/zones?status=false')->assertOk()->json('data'))->toHaveCount(1)
        ->and(asUser($user)->getJson('/api/zones?status=quiza')->assertOk()->json('data'))->toHaveCount(2);
});

it('devuelve las dos zonas cuando el punto cae en un solape', function () {
    Zone::factory()->withArea(zoneSquarePairs(14.0, -91.0))->create();
    Zone::factory()->withArea(zoneSquarePairs(14.2, -90.8))->create();

    expect(asUser(userWithRole(UserRole::Manager))->getJson('/api/zones?lat=14.5&lng=-90.5')->assertOk()->json('data'))
        ->toHaveCount(2);
});

it('combina el filtro de punto con el de estado', function () {
    $activa = Zone::factory()->withArea(zoneSquarePairs(14.0, -91.0))->active()->create();
    Zone::factory()->withArea(zoneSquarePairs(14.0, -91.0))->inactive()->create();

    $user = userWithRole(UserRole::Manager);

    /** Sin status, el punto devuelve también la zona dada de baja. */
    expect(asUser($user)->getJson('/api/zones?lat=14.5&lng=-90.5')->assertOk()->json('data'))->toHaveCount(2);

    $filtrado = asUser($user)->getJson('/api/zones?lat=14.5&lng=-90.5&status=true')->assertOk()->json('data');

    expect($filtrado)->toHaveCount(1)
        ->and($filtrado[0]['id'])->toBe($activa->id);
});

it('no dispara N+1 al listar zonas de muchos registradores', function () {
    Zone::factory()->count(20)->create();

    /** El token se emite antes de escuchar: sus claims consultan la empresa del usuario. */
    $token = JWTAuth::fromUser(userWithRole(UserRole::Administrator));

    resetAuthState();

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withToken($token)->getJson('/api/zones')->assertOk()->assertJsonCount(20, 'data');

    $sobreZonas = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "zones"'));
    $sobreUsuarios = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "users"'));

    /** Una consulta por el listado y otra por la relación: la del usuario autenticado es aparte. */
    expect($sobreZonas)->toHaveCount(1)
        ->and($sobreUsuarios->count())->toBeLessThanOrEqual(2);
});

/*
|--------------------------------------------------------------------------
| Forma de la respuesta
|--------------------------------------------------------------------------
*/

it('devuelve las nueve claves en camelCase y las fechas con el formato de la spec', function () {
    $zone = Zone::factory()->create();
    $admin = userWithRole(UserRole::Administrator);

    $detalle = asUser($admin)->getJson("/api/zones/{$zone->id}")->assertOk()->json('data');
    $delListado = asUser($admin)->getJson('/api/zones')->assertOk()->json('data.0');

    expect(array_keys($detalle))->toBe(zoneResourceKeys())
        ->and(array_keys($delListado))->toBe(zoneResourceKeys())
        ->and($detalle['createdAt'])->toMatch(zoneDatePattern())
        ->and($detalle['updatedAt'])->toMatch(zoneDatePattern())
        ->and($detalle['registeredByName'])->toBe($zone->registeredBy->name);
});

it('trae el area poblada en todos los endpoints que responden con una zona', function () {
    $admin = userWithRole(UserRole::Administrator);
    $triangulo = zoneTrianglePairs();

    $created = asUser($admin)->postJson('/api/zones', ['name' => 'zona norte', 'area' => $triangulo])->assertCreated();
    $id = $created->json('data.id');

    expect($created->json('data.area'))->toBe($triangulo)
        ->and(asUser($admin)->getJson('/api/zones')->assertOk()->json('data.0.area'))->toBe($triangulo)
        ->and(asUser($admin)->getJson("/api/zones/{$id}")->assertOk()->json('data.area'))->toBe($triangulo)
        ->and(asUser($admin)->patchJson("/api/zones/{$id}", ['description' => 'otra'])->assertOk()->json('data.area'))->toBe($triangulo)
        ->and(asUser($admin)->patchJson("/api/zones/{$id}/toggle-status")->assertOk()->json('data.area'))->toBe($triangulo)
        ->and(asUser($admin)->deleteJson("/api/zones/{$id}")->assertOk()->json('data.area'))->toBe($triangulo);
});

/*
|--------------------------------------------------------------------------
| Color
|--------------------------------------------------------------------------
*/

it('aplica el azul por defecto y normaliza el color a mayúsculas', function (?string $color, string $expected) {
    $payload = ['name' => 'zona norte', 'area' => zoneTrianglePairs()];

    if ($color !== null) {
        $payload['color'] = $color;
    }

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/zones', $payload)
        ->assertCreated()
        ->assertJsonPath('data.color', $expected)
        ->assertJsonPath('message', 'Zona registrada correctamente');
})->with([
    'sin color' => [null, '#3388FF'],
    'en minúsculas' => ['#ff0000', '#FF0000'],
    'ya en mayúsculas' => ['#FF0000', '#FF0000'],
]);

/*
|--------------------------------------------------------------------------
| Edición parcial
|--------------------------------------------------------------------------
*/

it('no altera area, color ni descripción cuando el PATCH solo manda el nombre', function () {
    $admin = userWithRole(UserRole::Administrator);
    $zone = Zone::factory()->withArea(zoneTrianglePairs())->create([
        'color' => '#123456',
        'description' => 'la de siempre',
        'registered_by' => $admin->id,
    ]);

    asUser($admin)->patchJson("/api/zones/{$zone->id}", ['name' => 'zona nueva'])
        ->assertOk()
        ->assertJsonPath('data.name', 'ZONA NUEVA')
        ->assertJsonPath('data.color', '#123456')
        ->assertJsonPath('data.description', 'la de siempre')
        ->assertJsonPath('data.area', zoneTrianglePairs());
});

it('acepta que una zona reenvíe su propio nombre y rechaza el de otra', function () {
    $admin = userWithRole(UserRole::Administrator);
    $zone = Zone::factory()->create(['name' => 'ZONA NORTE']);
    Zone::factory()->create(['name' => 'ZONA SUR']);

    asUser($admin)->patchJson("/api/zones/{$zone->id}", ['name' => 'zona norte'])
        ->assertOk()
        ->assertJsonPath('data.name', 'ZONA NORTE');

    asUser($admin)->patchJson("/api/zones/{$zone->id}", ['name' => 'zona sur'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('rechaza con 422 un polígono mal formado en el PATCH', function () {
    $admin = userWithRole(UserRole::Administrator);
    $zone = Zone::factory()->create();

    asUser($admin)->patchJson("/api/zones/{$zone->id}", ['area' => [[14.6349, -90.5069], [14.6402, -90.4998]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['area']);
});

/*
|--------------------------------------------------------------------------
| 404 sobre un id inexistente
|--------------------------------------------------------------------------
*/

it('responde 404 en las cuatro acciones que resuelven la zona por id', function (string $method, string $uri) {
    asUser(userWithRole(UserRole::Administrator))->json($method, $uri)
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'La zona no existe',
            'data' => null,
        ]);
})->with([
    'show' => ['GET', '/api/zones/9999'],
    'update' => ['PATCH', '/api/zones/9999'],
    'toggle-status' => ['PATCH', '/api/zones/9999/toggle-status'],
    'destroy' => ['DELETE', '/api/zones/9999'],
]);
