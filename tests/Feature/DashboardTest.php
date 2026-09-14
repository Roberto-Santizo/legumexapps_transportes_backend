<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\Client;
use App\Models\Location;
use App\Models\ShippingLine;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
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
 * The four routes of the domain, all of them `GET` and all of them protected the same way.
 *
 * @return array<string, array{0: string}>
 */
function dashboardEndpoints(): array
{
    return [
        'trips' => ['/api/dashboard/trips'],
        'trips in route' => ['/api/dashboard/trips/in-route'],
        'vehicle expenses' => ['/api/dashboard/vehicle-expenses'],
        'vehicles' => ['/api/dashboard/vehicles'],
    ];
}

/**
 * The two roles `role:administrator,manager` keeps out of the whole domain.
 *
 * @return array<string, UserRole>
 */
function dashboardForbiddenRoles(): array
{
    return [
        'carrier' => UserRole::Carrier,
        'pilot' => UserRole::Pilot,
    ];
}

/**
 * A carrier company with an owner, ready to appear as `assigned_by` of a trip.
 */
function dashboardCarrier(string $name = 'TRANSPORTES X'): Carrier
{
    return Carrier::factory()->create(['name' => $name]);
}

/**
 * A trip taken by the given company, so it lands in its `byCarrier` row.
 *
 * @param  array<string, mixed>  $attributes
 */
function tripTakenBy(Carrier $carrier, array $attributes = []): Trip
{
    $pilot = userWithRole(UserRole::Pilot);
    $carrier->pilots()->attach($pilot);

    return Trip::factory()->create(array_merge([
        'pilot_id' => $pilot->id,
        'vehicle_id' => Vehicle::factory()->create(['carrier_id' => $carrier->id])->id,
        'assigned_by' => $carrier->user_id,
    ], $attributes));
}

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

it('rechaza las rutas del tablero sin token', function (string $uri) {
    $this->getJson($uri)
        ->assertStatus(401)
        ->assertJsonPath('message', 'El token de sesión no es válido o ha expirado');
})->with(dashboardEndpoints());

it('rechaza con 403 a transportistas y pilotos en todas las rutas del tablero', function (string $uri, UserRole $role) {
    asUser(userWithRole($role))->getJson($uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(dashboardEndpoints())->with(dashboardForbiddenRoles());

/*
|--------------------------------------------------------------------------
| GET /api/dashboard/trips
|--------------------------------------------------------------------------
*/

it('devuelve el resumen de viajes vacío con las tres claves de estado a cero', function () {
    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/trips')
        ->assertOk()
        ->assertExactJson([
            'statusCode' => 200,
            'message' => 'Resumen de viajes obtenido correctamente',
            'data' => [
                'total' => 0,
                'unassigned' => 0,
                'byStatus' => ['pending' => 0, 'inRoute' => 0, 'finished' => 0],
                'byCarrier' => [],
                'byClient' => [],
                'byShippingLine' => [],
                'byLocation' => [],
                'byMonth' => [],
            ],
        ]);
});

it('cuenta los viajes por estado y deja fuera de byCarrier a los que nadie ha tomado', function () {
    $carrier = dashboardCarrier();
    Trip::factory()->count(2)->create();
    tripTakenBy($carrier, ['status' => TripStatus::InRoute, 'start_date' => now()]);
    tripTakenBy($carrier, ['status' => TripStatus::Finished, 'start_date' => now(), 'end_date' => now()]);

    $response = asUser(userWithRole(UserRole::Manager))->getJson('/api/dashboard/trips')
        ->assertOk()
        ->assertJsonPath('data.total', 4)
        ->assertJsonPath('data.unassigned', 2)
        ->assertJsonPath('data.byStatus', ['pending' => 2, 'inRoute' => 1, 'finished' => 1])
        ->assertJsonPath('data.byCarrier', [
            ['carrierId' => $carrier->id, 'carrierName' => 'TRANSPORTES X', 'total' => 2],
        ]);

    expect(collect($response->json('data.byCarrier'))->sum('total'))->toBeLessThan($response->json('data.total'));
});

it('no cuenta un viaje borrado en ningún bloque del resumen', function () {
    tripTakenBy(dashboardCarrier(), ['deleted_at' => now()]);
    Trip::factory()->trashed()->create();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/trips')
        ->assertOk()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('data.unassigned', 0)
        ->assertJsonPath('data.byStatus', ['pending' => 0, 'inRoute' => 0, 'finished' => 0])
        ->assertJsonPath('data.byCarrier', [])
        ->assertJsonPath('data.byClient', [])
        ->assertJsonPath('data.byMonth', []);
});

it('desglosa por cliente, naviera y puerto ordenando por total y luego por id', function () {
    $clientA = Client::factory()->create(['name' => 'CLIENTE A']);
    $clientB = Client::factory()->create(['name' => 'CLIENTE B']);
    $line = ShippingLine::factory()->create(['name' => 'MAERSK']);
    $port = Location::factory()->port()->active()->create(['name' => 'PUERTO QUETZAL']);

    Trip::factory()->create(['client_id' => $clientA->id, 'shipping_line_id' => $line->id, 'location_id' => $port->id]);
    Trip::factory()->count(2)->create(['client_id' => $clientB->id, 'shipping_line_id' => $line->id, 'location_id' => $port->id]);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/trips')
        ->assertOk()
        ->assertJsonPath('data.byClient', [
            ['clientId' => $clientB->id, 'clientName' => 'CLIENTE B', 'total' => 2],
            ['clientId' => $clientA->id, 'clientName' => 'CLIENTE A', 'total' => 1],
        ])
        ->assertJsonPath('data.byShippingLine', [
            ['shippingLineId' => $line->id, 'shippingLineName' => 'MAERSK', 'total' => 3],
        ])
        ->assertJsonPath('data.byLocation', [
            ['locationId' => $port->id, 'locationName' => 'PUERTO QUETZAL', 'total' => 3],
        ]);
});

it('agrupa byMonth como YYYY-MM solo con los meses con viajes y en orden ascendente', function () {
    Trip::factory()->create(['recolection_date' => '2026-10-05 10:00:00']);
    Trip::factory()->count(2)->create(['recolection_date' => '2026-08-20 10:00:00']);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/trips')
        ->assertOk()
        ->assertJsonPath('data.byMonth', [
            ['month' => '2026-08', 'total' => 2],
            ['month' => '2026-10', 'total' => 1],
        ]);
});

it('acota los ocho bloques del resumen a la empresa del filtro carrierId', function () {
    $mine = dashboardCarrier('MIA');
    $other = dashboardCarrier('OTRA');
    tripTakenBy($mine, ['recolection_date' => '2026-09-10 10:00:00']);
    tripTakenBy($other, ['recolection_date' => '2026-09-11 10:00:00']);
    Trip::factory()->create();

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/dashboard/trips?carrierId={$mine->id}")
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.unassigned', 0)
        ->assertJsonPath('data.byStatus', ['pending' => 1, 'inRoute' => 0, 'finished' => 0])
        ->assertJsonPath('data.byCarrier', [
            ['carrierId' => $mine->id, 'carrierName' => 'MIA', 'total' => 1],
        ])
        ->assertJsonCount(1, 'data.byClient')
        ->assertJsonCount(1, 'data.byShippingLine')
        ->assertJsonCount(1, 'data.byLocation')
        ->assertJsonPath('data.byMonth', [['month' => '2026-09', 'total' => 1]]);
});

it('ignora un carrierId inexistente o no numérico y devuelve el histórico completo', function (string $carrierId) {
    tripTakenBy(dashboardCarrier());
    Trip::factory()->create();

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/dashboard/trips?carrierId={$carrierId}")
        ->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.unassigned', 1)
        ->assertJsonCount(1, 'data.byCarrier');
})->with(['inexistente' => '999999', 'no numérico' => 'abc']);

it('corta el resumen por recolection_date por día completo', function () {
    Trip::factory()->create(['recolection_date' => '2026-09-30 23:59:00']);
    Trip::factory()->create(['recolection_date' => '2026-10-01 00:00:00']);
    Trip::factory()->create(['recolection_date' => '2026-08-31 23:59:00']);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/trips?dateFrom=2026-09-01&dateTo=2026-09-30')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.byMonth', [['month' => '2026-09', 'total' => 1]]);
});

it('ignora una fecha con formato inválido y devuelve el histórico completo', function () {
    Trip::factory()->create(['recolection_date' => '2026-09-30 23:59:00']);
    Trip::factory()->create(['recolection_date' => '2025-01-01 00:00:00']);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/trips?dateFrom=01/09/2026&dateTo=2026-13-45')
        ->assertOk()
        ->assertJsonPath('data.total', 2);
});

it('devuelve el mismo resumen de viajes al administrador y al manager', function () {
    tripTakenBy(dashboardCarrier());
    Trip::factory()->create();

    $admin = asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/trips')->assertOk()->json('data');
    $manager = asUser(userWithRole(UserRole::Manager))->getJson('/api/dashboard/trips')->assertOk()->json('data');

    expect($manager)->toBe($admin);
});
