<?php

use App\Enums\FuelType;
use App\Enums\UserRole;
use App\Models\FreightRate;
use App\Models\FuelPrice;
use App\Models\Product;
use App\Models\User;
use App\Models\Zone;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Every freight rates endpoint as method and URI, for the middleware datasets.
 *
 * @return array<string, array{string, string}>
 */
function freightRateEndpoints(): array
{
    return [
        'index' => ['GET', '/api/freight-rates'],
        'quote' => ['GET', '/api/freight-rates/quote'],
        'store' => ['POST', '/api/freight-rates'],
        'show' => ['GET', '/api/freight-rates/1'],
        'update' => ['PATCH', '/api/freight-rates/1'],
        'destroy' => ['DELETE', '/api/freight-rates/1'],
    ];
}

/**
 * The four endpoints restricted to the administrator, the detail included.
 *
 * @return array<string, array{string, string}>
 */
function freightRateAdminEndpoints(): array
{
    return [
        'store' => ['POST', '/api/freight-rates'],
        'show' => ['GET', '/api/freight-rates/1'],
        'update' => ['PATCH', '/api/freight-rates/1'],
        'destroy' => ['DELETE', '/api/freight-rates/1'],
    ];
}

/**
 * The roles that may read the price table but never write it.
 *
 * @return array<string, UserRole>
 */
function freightRateNonAdminRoles(): array
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
 * Un cuadrado de un grado con esquina inferior izquierda en el punto dado.
 *
 * @return array<int, array{0: float, 1: float}>
 */
function freightRateSquarePairs(float $latitude, float $longitude): array
{
    return [
        [$latitude, $longitude],
        [$latitude + 1.0, $longitude],
        [$latitude + 1.0, $longitude + 1.0],
        [$latitude, $longitude + 1.0],
    ];
}

/*
|--------------------------------------------------------------------------
| Ruteo y autorización
|--------------------------------------------------------------------------
*/

it('responde 401 sin token en las seis rutas', function (string $method, string $uri) {
    test()->withHeader('Accept', 'application/json')->json($method, $uri)
        ->assertStatus(401)
        ->assertJsonStructure(['statusCode', 'message']);
})->with(freightRateEndpoints());

it('responde 403 en las rutas de administrador con los otros tres roles', function (string $method, string $uri) {
    foreach (freightRateNonAdminRoles() as $role) {
        asUser(userWithRole($role))->json($method, $uri)->assertStatus(403);
    }
})->with(freightRateAdminEndpoints());

it('abre el listado a cualquier autenticado, sin exigir empresa', function (UserRole $role) {
    asUser(userWithRole($role))->getJson('/api/freight-rates')->assertStatus(200);
})->with(freightRateNonAdminRoles());

it('resuelve /quote como ruta fija y no como un id de tarifa', function () {
    $zone = Zone::factory()->active()->withArea(freightRateSquarePairs(14.0, -91.0))->create();
    $product = Product::factory()->active()->create();

    FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel, 'price' => 40.00]);

    FreightRate::factory()->create([
        'zone_id' => $zone->id,
        'product_id' => $product->id,
        'fuel_type' => FuelType::Diesel,
        'fuel_min' => 35.00,
        'price_per_pound' => 0.454120,
    ]);

    asUser(userWithRole(UserRole::Pilot))
        ->getJson('/api/freight-rates/quote?lat=14.5&lng=-90.5&productId='.$product->id.'&fuelType=diesel&pounds=45000')
        ->assertStatus(200)
        ->assertJsonPath('data.pricePerPound', '0.454120')
        ->assertJsonPath('data.currentFuelPrice', '40.00')
        ->assertJsonPath('data.appliedFuelMin', '35.00')
        ->assertJsonPath('data.total', '20435.40');
});

it('registra una tarifa como administrador', function () {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();

    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/freight-rates', [
            'zoneId' => $zone->id,
            'productId' => $product->id,
            'fuelType' => 'diesel',
            'fuelMin' => 30.00,
            'pricePerPound' => 0.454120,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.fuelMin', '30.00')
        ->assertJsonPath('data.pricePerPound', '0.454120')
        ->assertJsonPath('data.zoneName', $zone->name);
});
