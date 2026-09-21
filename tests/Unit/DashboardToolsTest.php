<?php

use App\Ai\Agents\DashboardAssistant;
use App\Ai\Tools\Dashboard\DashboardTool;
use App\Ai\Tools\Dashboard\FleetTool;
use App\Ai\Tools\Dashboard\TripsInRouteTool;
use App\Ai\Tools\Dashboard\TripsSummaryTool;
use App\Ai\Tools\Dashboard\VehicleExpensesSummaryTool;
use App\Enums\UserRole;
use App\Enums\VehicleStatus;
use App\Interfaces\Dashboard\DashboardServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Build a tool for the given user against the real service, like the agent does.
 *
 * @template T of DashboardTool
 *
 * @param  class-string<T>  $tool
 * @return T
 */
function dashboardTool(string $tool, User $user): DashboardTool
{
    return new $tool($user, app(DashboardServiceInterface::class));
}

/**
 * Run a tool with the arguments the model would send and decode its JSON answer.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function callTool(DashboardTool $tool, array $arguments = []): array
{
    return json_decode($tool->handle(new Request($arguments)), true, flags: JSON_THROW_ON_ERROR);
}

function toolAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * A trip taken by the given company, with a pilot linked to it and a vehicle it owns.
 *
 * @param  array<string, mixed>  $attributes
 */
function toolTripOf(Carrier $carrier, array $attributes = []): Trip
{
    $pilot = User::factory()->create(['role' => UserRole::Pilot]);
    $carrier->pilots()->attach($pilot);

    return Trip::factory()->create(array_merge([
        'pilot_id' => $pilot->id,
        'vehicle_id' => Vehicle::factory()->create(['carrier_id' => $carrier->id])->id,
        'assigned_by' => $carrier->user_id,
    ], $attributes));
}

/*
|--------------------------------------------------------------------------
| Wiring
|--------------------------------------------------------------------------
*/

it('expone los trece tools con los nombres que usa el system prompt', function () {
    $tools = iterator_to_array(new DashboardAssistant(toolAdmin())->tools());

    expect(array_map(ToolNameResolver::resolve(...), $tools))
        ->toBe([
            'trips_summary', 'trips_in_route', 'vehicle_expenses_summary', 'fleet',
            'trips', 'trip', 'trip_fuels', 'trip_expenses', 'trip_timeouts',
            'vehicle', 'vehicle_expenses',
            'export_trips', 'export_vehicle_expenses',
        ]);
});

it('declara un schema de tablero donde ningún argumento es obligatorio', function (string $tool) {
    $factory = new JsonSchemaTypeFactory;
    $schema = $factory->object(dashboardTool($tool, toolAdmin())->schema($factory))->toArray();

    expect($schema)->not->toHaveKey('required')
        ->and($schema['properties'])->toHaveKey('carrierId');
})->with([
    TripsSummaryTool::class,
    TripsInRouteTool::class,
    VehicleExpensesSummaryTool::class,
    FleetTool::class,
]);

/*
|--------------------------------------------------------------------------
| trips_summary
|--------------------------------------------------------------------------
*/

it('devuelve el resumen de viajes con la misma forma que el endpoint', function () {
    Trip::factory()->create();
    toolTripOf(Carrier::factory()->create());

    $result = callTool(dashboardTool(TripsSummaryTool::class, toolAdmin()));

    expect($result)->toHaveKeys(['total', 'unassigned', 'byStatus', 'byCarrier', 'byClient', 'byShippingLine', 'byLocation', 'byMonth'])
        ->and($result['total'])->toBe(2)
        ->and($result['unassigned'])->toBe(1)
        ->and($result['byCarrier'])->toHaveCount(1);
});

it('acota al carrier a su empresa e ignora el carrierId que mande el modelo', function () {
    $mine = Carrier::factory()->create();
    $other = Carrier::factory()->create();
    toolTripOf($mine);
    toolTripOf($other);
    toolTripOf($other);

    $owner = User::query()->findOrFail($mine->user_id);
    $result = callTool(dashboardTool(TripsSummaryTool::class, $owner), ['carrierId' => $other->id]);

    expect($result['total'])->toBe(1)
        ->and($result['byCarrier'][0]['carrierId'])->toBe($mine->id);
});

it('descarta argumentos vacíos y desconocidos antes de llamar al service', function () {
    Trip::factory()->create(['recolection_date' => '2026-03-10']);
    Trip::factory()->create(['recolection_date' => '2026-05-10']);

    $result = callTool(dashboardTool(TripsSummaryTool::class, toolAdmin()), [
        'dateFrom' => '2026-04-01',
        'dateTo' => '',
        'carrierId' => null,
        'invented' => 'x',
    ]);

    expect($result['total'])->toBe(1);
});

it('traduce los booleanos y enteros del modelo al formato de query string del service', function () {
    $vehicle = Vehicle::factory()->create();
    Trip::factory()->inRoute()->create(['vehicle_id' => $vehicle->id]);
    Vehicle::factory()->create();

    $result = callTool(dashboardTool(FleetTool::class, toolAdmin()), ['inRoute' => true, 'limit' => 10]);

    expect($result['total'])->toBe(1)
        ->and($result['vehicles'][0]['id'])->toBe($vehicle->id)
        ->and($result['vehicles'][0]['inRoute'])->toBeTrue();
});

it('devuelve el error de negocio como JSON en vez de lanzarlo', function () {
    $carrierWithoutCompany = User::factory()->create(['role' => UserRole::Carrier]);

    $result = callTool(dashboardTool(TripsSummaryTool::class, $carrierWithoutCompany));

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toBeString()->not->toBe('');
});

/*
|--------------------------------------------------------------------------
| trips_in_route
|--------------------------------------------------------------------------
*/

it('lista los viajes en ruta con su conteo', function () {
    Trip::factory()->inRoute()->create();
    Trip::factory()->create();

    $result = callTool(dashboardTool(TripsInRouteTool::class, toolAdmin()));

    expect($result['count'])->toBe(1)
        ->and($result['trips'])->toHaveCount(1)
        ->and($result['trips'][0])->toHaveKeys(['tripId', 'lastPosition', 'totalFuelGallons', 'unconfirmedFuelGallons', 'openTimeout']);
});

/*
|--------------------------------------------------------------------------
| vehicle_expenses_summary
|--------------------------------------------------------------------------
*/

it('devuelve el resumen de gastos con importes como texto de dos decimales', function () {
    VehicleExpense::factory()->create(['amount' => 150.5]);

    $result = callTool(dashboardTool(VehicleExpensesSummaryTool::class, toolAdmin()));

    expect($result['count'])->toBe(1)
        ->and($result['totalAmount'])->toBe('150.50')
        ->and($result)->toHaveKeys(['byCategory', 'byNature', 'invoiced', 'notInvoiced', 'byCarrier', 'byMonth']);
});

/*
|--------------------------------------------------------------------------
| fleet
|--------------------------------------------------------------------------
*/

it('pagina siempre la flota y reporta el total frente a lo devuelto', function () {
    Vehicle::factory()->count(12)->create();

    $result = callTool(dashboardTool(FleetTool::class, toolAdmin()), ['limit' => 10]);

    expect($result['total'])->toBe(12)
        ->and($result['returned'])->toBe(10)
        ->and($result['vehicles'])->toHaveCount(10)
        ->and($result['vehicles'][0])->toHaveKeys(['id', 'plate', 'status', 'inRoute', 'currentTrip']);
});

it('aplica los filtros tolerantes de la flota', function () {
    Vehicle::factory()->create(['status' => VehicleStatus::Active]);
    Vehicle::factory()->create(['status' => VehicleStatus::Inactive]);

    $filtered = callTool(dashboardTool(FleetTool::class, toolAdmin()), ['status' => 'inactive']);
    $ignored = callTool(dashboardTool(FleetTool::class, toolAdmin()), ['status' => 'basura']);

    expect($filtered['total'])->toBe(1)
        ->and($filtered['vehicles'][0]['status'])->toBe('inactive')
        ->and($ignored['total'])->toBe(2);
});
