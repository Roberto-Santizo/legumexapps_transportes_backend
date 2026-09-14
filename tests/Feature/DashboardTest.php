<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Enums\VehicleCondition;
use App\Enums\VehicleExpenseCategory;
use App\Enums\VehicleExpenseNature;
use App\Enums\VehicleStatus;
use App\Models\Carrier;
use App\Models\Client;
use App\Models\Location;
use App\Models\ShippingLine;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
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
        'vehicle_id' => $attributes['vehicle_id'] ?? Vehicle::factory()->create(['carrier_id' => $carrier->id])->id,
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

/*
|--------------------------------------------------------------------------
| GET /api/dashboard/vehicle-expenses
|--------------------------------------------------------------------------
*/

/**
 * An expense on a vehicle of the given company.
 *
 * @param  array<string, mixed>  $attributes
 */
function expenseOf(Carrier $carrier, array $attributes = [], VehicleStatus $vehicleStatus = VehicleStatus::Active): VehicleExpense
{
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'status' => $vehicleStatus]);

    return VehicleExpense::factory()->create(array_merge(['vehicle_id' => $vehicle->id], $attributes));
}

it('devuelve el resumen de gastos vacío con todos los bloques fijos a cero', function () {
    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/vehicle-expenses')
        ->assertOk()
        ->assertExactJson([
            'statusCode' => 200,
            'message' => 'Resumen de gastos de vehículos obtenido correctamente',
            'data' => [
                'totalAmount' => '0.00',
                'count' => 0,
                'byCategory' => [],
                'byNature' => [
                    'preventive' => ['count' => 0, 'totalAmount' => '0.00'],
                    'corrective' => ['count' => 0, 'totalAmount' => '0.00'],
                ],
                'invoiced' => ['count' => 0, 'totalAmount' => '0.00'],
                'notInvoiced' => ['count' => 0, 'totalAmount' => '0.00'],
                'byCarrier' => [],
                'byMonth' => [],
            ],
        ]);
});

it('suma los gastos como string de dos decimales y reparte facturados y sin facturar', function () {
    $carrier = dashboardCarrier();
    expenseOf($carrier, ['amount' => 1000.5, 'is_invoiced' => true, 'nature' => VehicleExpenseNature::Preventive, 'category' => VehicleExpenseCategory::Tires, 'expense_date' => '2026-08-10']);
    expenseOf($carrier, ['amount' => 300, 'is_invoiced' => false, 'nature' => VehicleExpenseNature::Corrective, 'category' => VehicleExpenseCategory::Tires, 'expense_date' => '2026-08-20']);
    expenseOf($carrier, ['amount' => 200, 'is_invoiced' => false, 'nature' => VehicleExpenseNature::Corrective, 'category' => VehicleExpenseCategory::Other, 'expense_date' => '2026-09-01']);

    $response = asUser(userWithRole(UserRole::Manager))->getJson('/api/dashboard/vehicle-expenses')
        ->assertOk()
        ->assertJsonPath('data.totalAmount', '1500.50')
        ->assertJsonPath('data.count', 3)
        ->assertJsonPath('data.byCategory', [
            ['category' => 'tires', 'count' => 2, 'totalAmount' => '1300.50'],
            ['category' => 'other', 'count' => 1, 'totalAmount' => '200.00'],
        ])
        ->assertJsonPath('data.byNature', [
            'preventive' => ['count' => 1, 'totalAmount' => '1000.50'],
            'corrective' => ['count' => 2, 'totalAmount' => '500.00'],
        ])
        ->assertJsonPath('data.invoiced', ['count' => 1, 'totalAmount' => '1000.50'])
        ->assertJsonPath('data.notInvoiced', ['count' => 2, 'totalAmount' => '500.00'])
        ->assertJsonPath('data.byCarrier', [
            ['carrierId' => $carrier->id, 'carrierName' => 'TRANSPORTES X', 'count' => 3, 'totalAmount' => '1500.50'],
        ])
        ->assertJsonPath('data.byMonth', [
            ['month' => '2026-08', 'count' => 2, 'totalAmount' => '1300.50'],
            ['month' => '2026-09', 'count' => 1, 'totalAmount' => '200.00'],
        ]);

    $data = $response->json('data');
    expect($data['invoiced']['count'] + $data['notInvoiced']['count'])->toBe($data['count'])
        ->and(number_format((float) $data['invoiced']['totalAmount'] + (float) $data['notInvoiced']['totalAmount'], 2, '.', ''))->toBe($data['totalAmount']);
});

it('cuenta igual los gastos de un vehículo inactivo', function () {
    $carrier = dashboardCarrier();
    expenseOf($carrier, ['amount' => 50], VehicleStatus::Inactive);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/vehicle-expenses')
        ->assertOk()
        ->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.totalAmount', '50.00')
        ->assertJsonPath('data.byCarrier.0.carrierId', $carrier->id);
});

it('ordena byCarrier de gastos por monto descendente', function () {
    $small = dashboardCarrier('CHICA');
    $big = dashboardCarrier('GRANDE');
    expenseOf($small, ['amount' => 10]);
    expenseOf($big, ['amount' => 900]);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/vehicle-expenses')
        ->assertOk()
        ->assertJsonPath('data.byCarrier.0.carrierId', $big->id)
        ->assertJsonPath('data.byCarrier.1.carrierId', $small->id);
});

it('acota el resumen de gastos por vehicles.carrier_id y por expense_date por día completo', function () {
    $mine = dashboardCarrier('MIA');
    $other = dashboardCarrier('OTRA');
    expenseOf($mine, ['amount' => 100, 'expense_date' => '2026-09-01']);
    expenseOf($mine, ['amount' => 200, 'expense_date' => '2026-09-30']);
    expenseOf($mine, ['amount' => 400, 'expense_date' => '2026-10-01']);
    expenseOf($other, ['amount' => 800, 'expense_date' => '2026-09-15']);

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/dashboard/vehicle-expenses?carrierId={$mine->id}")
        ->assertOk()
        ->assertJsonPath('data.count', 3)
        ->assertJsonPath('data.totalAmount', '700.00')
        ->assertJsonCount(1, 'data.byCarrier');

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/dashboard/vehicle-expenses?carrierId={$mine->id}&dateFrom=2026-09-01&dateTo=2026-09-30")
        ->assertOk()
        ->assertJsonPath('data.count', 2)
        ->assertJsonPath('data.totalAmount', '300.00')
        ->assertJsonPath('data.byMonth', [['month' => '2026-09', 'count' => 2, 'totalAmount' => '300.00']]);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/vehicle-expenses?carrierId=abc&dateFrom=30/09/2026')
        ->assertOk()
        ->assertJsonPath('data.count', 4)
        ->assertJsonPath('data.totalAmount', '1500.00');
});

/*
|--------------------------------------------------------------------------
| GET /api/dashboard/vehicles
|--------------------------------------------------------------------------
*/

/**
 * A trip in route on the given vehicle, driven by a pilot of its company.
 *
 * @param  array<string, mixed>  $attributes
 */
function tripInRouteOn(Vehicle $vehicle, array $attributes = []): Trip
{
    $carrier = Carrier::query()->findOrFail($vehicle->carrier_id);

    return tripTakenBy($carrier, array_merge([
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::InRoute,
        'start_date' => now()->subHour(),
    ], $attributes));
}

it('lista toda la flota incluidos los inactivos en orden id ascendente y sin datos financieros', function () {
    $carrier = dashboardCarrier();
    $active = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'plate' => 'P111AAA']);
    $inactive = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'plate' => 'P222BBB', 'status' => VehicleStatus::Inactive]);

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/vehicles')
        ->assertOk()
        ->assertJsonPath('message', 'Flota obtenida correctamente')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $active->id)
        ->assertJsonPath('data.1.id', $inactive->id)
        ->assertJsonPath('data.1.status', 'inactive')
        ->assertJsonPath('data.0.carrierName', 'TRANSPORTES X')
        ->assertJsonPath('data.0.inRoute', false)
        ->assertJsonPath('data.0.currentTrip', null)
        ->assertJsonMissingPath('total');

    expect(array_keys($response->json('data.0')))->toBe([
        'id', 'plate', 'type', 'status', 'condition', 'mileage', 'kilometersPerGallon',
        'carrierId', 'carrierName', 'inRoute', 'currentTrip',
    ]);
});

it('resuelve currentTrip con el viaje en curso de start_date más reciente', function () {
    $vehicle = Vehicle::factory()->create(['carrier_id' => dashboardCarrier()->id]);
    tripInRouteOn($vehicle, ['start_date' => '2026-09-14 06:00:00', 'order' => 'ORD-OLD']);
    $latest = tripInRouteOn($vehicle, ['start_date' => '2026-09-14 08:15:00', 'order' => 'ORD-NEW', 'container' => 'MSKU1234567']);

    asUser(userWithRole(UserRole::Manager))->getJson('/api/dashboard/vehicles')
        ->assertOk()
        ->assertJsonPath('data.0.inRoute', true)
        ->assertJsonPath('data.0.currentTrip', [
            'tripId' => $latest->id,
            'order' => 'ORD-NEW',
            'container' => 'MSKU1234567',
            'pilotName' => User::query()->findOrFail($latest->pilot_id)->name,
            'startDate' => '14-09-2026 08:15:00 AM',
        ]);
});

it('deja currentTrip en null cuando el único viaje del vehículo ya terminó', function () {
    $vehicle = Vehicle::factory()->create(['carrier_id' => dashboardCarrier()->id]);
    tripInRouteOn($vehicle, ['status' => TripStatus::Finished, 'end_date' => now()]);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/vehicles')
        ->assertOk()
        ->assertJsonPath('data.0.inRoute', false)
        ->assertJsonPath('data.0.currentTrip', null);
});

it('filtra la flota por inRoute antes de paginar y ignora un valor inválido', function () {
    $carrier = dashboardCarrier();
    $busy = Vehicle::factory()->create(['carrier_id' => $carrier->id]);
    $idle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);
    tripInRouteOn($busy);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/vehicles?inRoute=true&limit=10')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $busy->id);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/vehicles?inRoute=false')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $idle->id);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/vehicles?inRoute=basura')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filtra la flota por status, condition y carrierId, ignorando los valores inválidos', function () {
    $mine = dashboardCarrier('MIA');
    $other = dashboardCarrier('OTRA');
    $target = Vehicle::factory()->create(['carrier_id' => $mine->id, 'status' => VehicleStatus::UnderRepair, 'condition' => VehicleCondition::New]);
    Vehicle::factory()->create(['carrier_id' => $mine->id, 'status' => VehicleStatus::Active, 'condition' => VehicleCondition::Used]);
    Vehicle::factory()->create(['carrier_id' => $other->id, 'status' => VehicleStatus::UnderRepair, 'condition' => VehicleCondition::New]);

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/dashboard/vehicles?status=under_repair&condition=new&carrierId={$mine->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/vehicles?status=basura&condition=basura&carrierId=999999')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('pagina la flota solo con limit numérico, acotado a [10, 100]', function () {
    Vehicle::factory()->count(12)->create(['carrier_id' => dashboardCarrier()->id]);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/vehicles?limit=5')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('total', 12)
        ->assertJsonPath('currentPage', 1)
        ->assertJsonPath('lastPage', 2);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/vehicles?limit=abc')
        ->assertOk()
        ->assertJsonCount(12, 'data')
        ->assertJsonMissingPath('total');
});

it('devuelve el mismo resumen de viajes al administrador y al manager', function () {
    tripTakenBy(dashboardCarrier());
    Trip::factory()->create();

    $admin = asUser(userWithRole(UserRole::Administrator))->getJson('/api/dashboard/trips')->assertOk()->json('data');
    $manager = asUser(userWithRole(UserRole::Manager))->getJson('/api/dashboard/trips')->assertOk()->json('data');

    expect($manager)->toBe($admin);
});
