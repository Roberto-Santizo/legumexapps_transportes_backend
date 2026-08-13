<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Zone;
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
