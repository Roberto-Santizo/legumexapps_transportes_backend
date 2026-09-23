<?php

use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\Client;
use App\Models\Location;
use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * The role matrix across domains: what export, user and shipment reach outside the
 * trips, which each domain's own test file already covers.
 *
 * user and shipment only read trips, so every other endpoint answers 403 from the
 * `role:` middleware before the controller runs — no fixture is needed behind them.
 */
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
 * Every read outside the trips, the ones user and shipment are kept out of.
 *
 * @return array<string, array{string, string}>
 */
function roleAccessNonTripEndpoints(): array
{
    return [
        'clients' => ['GET', '/api/clients'],
        'shipping-lines' => ['GET', '/api/shipping-lines'],
        'locations' => ['GET', '/api/locations'],
        'departure-points' => ['GET', '/api/departure-points'],
        'fuel-prices' => ['GET', '/api/fuel-prices'],
        'fuel-prices/current' => ['GET', '/api/fuel-prices/current?fuelType=diesel'],
        'products' => ['GET', '/api/products'],
        'zones' => ['GET', '/api/zones'],
        'accessories' => ['GET', '/api/accessories'],
        'accessory-characteristics' => ['GET', '/api/accessory-characteristics?accessoryId=1'],
        'freight-rates' => ['GET', '/api/freight-rates'],
        'places' => ['GET', '/api/places?search=guatemala'],
        'places/directions' => ['GET', '/api/places/directions?locationId=1&lat=14.6&lng=-90.5'],
        'pilots' => ['GET', '/api/pilots'],
        'carriers' => ['GET', '/api/carriers'],
        'vehicles' => ['GET', '/api/vehicles'],
        'vehicle-expenses' => ['GET', '/api/vehicle-expenses?vehicleId=1'],
        'dashboard' => ['GET', '/api/dashboard/trips'],
        'assistant' => ['POST', '/api/assistant/chat'],
    ];
}

/*
|--------------------------------------------------------------------------
| user y shipment: solo viajes
|--------------------------------------------------------------------------
*/

it('rechaza con 403 a user y shipment fuera de los viajes', function (UserRole $role, string $method, string $uri) {
    asUser(userWithRole($role))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with([UserRole::User, UserRole::Shipment])->with(roleAccessNonTripEndpoints());

/*
|--------------------------------------------------------------------------
| export: catálogos del viaje, lectura de flota y pilotos
|--------------------------------------------------------------------------
*/

it('deja a export dar de alta los catálogos que arma un viaje', function (string $uri, array $payload) {
    asUser(userWithRole(UserRole::Export))->postJson($uri, $payload)->assertCreated();
})->with([
    'cliente' => ['/api/clients', ['code' => 'cli-001', 'name' => 'agroexportadora del sur']],
    'naviera' => ['/api/shipping-lines', ['name' => 'maersk line']],
    'destino' => ['/api/locations', [
        'name' => 'puerto quetzal',
        'type' => 'port',
        'googlePlaceId' => 'ChIJd8BlQ2BZwokRAFUEcm_qrcA',
        'latitude' => 13.9245,
        'longitude' => -90.7867,
    ]],
    'punto de partida' => ['/api/departure-points', [
        'name' => 'bodega central',
        'googlePlaceId' => 'ChIJd8BlQ2BZwokRAFUEcm_qrcA',
        'latitude' => 14.6349,
        'longitude' => -90.5069,
    ]],
]);

it('deja a export editar y dar de baja un cliente y pausar un destino', function () {
    $export = userWithRole(UserRole::Export);
    $client = Client::factory()->create();
    $location = Location::factory()->active()->create();

    asUser($export)->patchJson("/api/clients/{$client->id}", ['name' => 'otro nombre'])->assertOk();
    asUser($export)->deleteJson("/api/clients/{$client->id}")->assertOk();
    asUser($export)->patchJson("/api/locations/{$location->id}/toggle-status")->assertOk();

    expect($location->refresh()->status)->toBeFalse();
});

it('rechaza con 403 a export al escribir catálogos ajenos al viaje', function (string $uri) {
    asUser(userWithRole(UserRole::Export))->postJson($uri, [])->assertForbidden();
})->with([
    '/api/products',
    '/api/zones',
    '/api/fuel-prices',
    '/api/freight-rates',
    '/api/accessories',
    '/api/accessory-characteristics',
]);

it('deja a export listar los pilotos de todas las empresas sin poder tocar su salario', function () {
    $pilots = Carrier::factory()->count(2)->create()->map(function (Carrier $carrier): User {
        $pilot = userWithRole(UserRole::Pilot);
        $carrier->pilots()->attach($pilot);

        return $pilot;
    });
    $export = userWithRole(UserRole::Export);

    asUser($export)->getJson('/api/pilots')->assertOk()->assertJsonCount(2, 'data');

    asUser($export)->patchJson("/api/pilots/{$pilots[0]->id}/salary", ['salary' => 4500])->assertForbidden();
});

it('rechaza con 403 a export en la administración de empresas', function (string $method, string $uri) {
    Carrier::factory()->create();

    asUser(userWithRole(UserRole::Export))->json($method, $uri)->assertForbidden();
})->with([
    'index' => ['GET', '/api/carriers'],
    'show' => ['GET', '/api/carriers/1'],
    'vehicle-expenses' => ['GET', '/api/vehicle-expenses?vehicleId=1'],
]);

/*
|--------------------------------------------------------------------------
| manager: consulta todo, no escribe nada
|--------------------------------------------------------------------------
*/

it('rechaza con 403 al manager en la escritura de los catálogos del viaje', function (string $uri) {
    asUser(userWithRole(UserRole::Manager))->postJson($uri, [])->assertForbidden();
})->with([
    '/api/clients',
    '/api/shipping-lines',
    '/api/locations',
    '/api/departure-points',
    '/api/trips',
    '/api/vehicles',
]);
