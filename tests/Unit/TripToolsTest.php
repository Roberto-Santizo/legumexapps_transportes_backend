<?php

use App\Ai\Tools\AssistantTool;
use App\Ai\Tools\Trip\TripCostTool;
use App\Ai\Tools\Trip\TripExpensesTool;
use App\Ai\Tools\Trip\TripFuelsTool;
use App\Ai\Tools\Trip\TripsTool;
use App\Ai\Tools\Trip\TripTimeoutsTool;
use App\Ai\Tools\Trip\TripTool;
use App\Enums\FuelType;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Interfaces\Trip\TripServiceInterface;
use App\Interfaces\TripCost\TripCostServiceInterface;
use App\Interfaces\TripExpense\TripExpenseServiceInterface;
use App\Interfaces\TripFuel\TripFuelServiceInterface;
use App\Interfaces\TripTimeout\TripTimeoutServiceInterface;
use App\Models\Carrier;
use App\Models\CarrierPilot;
use App\Models\FuelPrice;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\TripFuel;
use App\Models\TripTimeout;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;

/**
 * Build a trip tool for the given user against the real services, like the agent does.
 */
function tripTool(string $tool, User $user): AssistantTool
{
    return match ($tool) {
        TripsTool::class, TripTool::class => new $tool($user, app(TripServiceInterface::class)),
        TripFuelsTool::class => new $tool($user, app(TripFuelServiceInterface::class)),
        TripExpensesTool::class => new $tool($user, app(TripExpenseServiceInterface::class)),
        TripTimeoutsTool::class => new $tool($user, app(TripTimeoutServiceInterface::class)),
        TripCostTool::class => new $tool($user, app(TripCostServiceInterface::class)),
    };
}

/**
 * Run a tool with the arguments the model would send and decode its JSON answer.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function callTripTool(AssistantTool $tool, array $arguments = []): array
{
    return json_decode($tool->handle(new Request($arguments)), true, flags: JSON_THROW_ON_ERROR);
}

function tripToolAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * A trip taken by the given company, with a pilot linked to it and a vehicle it owns.
 *
 * @param  array<string, mixed>  $attributes
 */
function tripToolTripOf(Carrier $carrier, array $attributes = []): Trip
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
| Schemas
|--------------------------------------------------------------------------
*/

it('exige tripId en las herramientas de un viaje concreto y nada más', function (string $tool) {
    $factory = new JsonSchemaTypeFactory;
    $schema = $factory->object(tripTool($tool, tripToolAdmin())->schema($factory))->toArray();

    expect($schema['required'])->toBe(['tripId']);
})->with([
    TripTool::class,
    TripFuelsTool::class,
    TripExpensesTool::class,
    TripTimeoutsTool::class,
    TripCostTool::class,
]);

it('no ofrece limit en el costo, que no es un listado', function () {
    $factory = new JsonSchemaTypeFactory;
    $schema = $factory->object(tripTool(TripCostTool::class, tripToolAdmin())->schema($factory))->toArray();

    expect($schema['properties'])->toBe(['tripId' => $schema['properties']['tripId']]);
});

it('no obliga a ningún argumento en la búsqueda de viajes', function () {
    $factory = new JsonSchemaTypeFactory;
    $schema = $factory->object(tripTool(TripsTool::class, tripToolAdmin())->schema($factory))->toArray();

    expect($schema)->not->toHaveKey('required')
        ->and($schema['properties'])->toHaveKeys(['status', 'search', 'dateFrom', 'dateTo', 'limit']);
});

it('relaya al modelo la falta de tripId como error de validación', function (string $tool) {
    expect(fn () => tripTool($tool, tripToolAdmin())->handle(new Request(['limit' => 10])))
        ->toThrow(ValidationException::class);
})->with([
    TripTool::class,
    TripFuelsTool::class,
    TripExpensesTool::class,
    TripTimeoutsTool::class,
    TripCostTool::class,
]);

/*
|--------------------------------------------------------------------------
| trips
|--------------------------------------------------------------------------
*/

it('pagina siempre los viajes y reporta el total frente a lo devuelto', function () {
    Trip::factory()->count(3)->create();

    $result = callTripTool(tripTool(TripsTool::class, tripToolAdmin()), ['limit' => 2]);

    expect($result['total'])->toBe(3)
        ->and($result['returned'])->toBe(2)
        ->and($result['trips'])->toHaveCount(2)
        ->and($result['trips'][0])->toHaveKeys(['id', 'order', 'status', 'container', 'recolectionDate', 'pilotName', 'vehiclePlate'])
        ->and($result['trips'][0])->not->toHaveKeys(['polyline', 'points']);
});

it('busca viajes por orden o contenedor y filtra por estado', function () {
    Trip::factory()->create(['order' => 'ORD-777', 'container' => 'MSKU0000001']);
    Trip::factory()->create(['order' => 'ORD-001', 'container' => 'TCLU7770000']);
    Trip::factory()->finished()->create(['order' => 'ORD-002']);

    $bySearch = callTripTool(tripTool(TripsTool::class, tripToolAdmin()), ['search' => '777']);
    $byStatus = callTripTool(tripTool(TripsTool::class, tripToolAdmin()), ['status' => TripStatus::Finished->value]);
    $ignored = callTripTool(tripTool(TripsTool::class, tripToolAdmin()), ['status' => 'cancelled']);

    expect($bySearch['total'])->toBe(2)
        ->and($byStatus['total'])->toBe(1)
        ->and($byStatus['trips'][0]['order'])->toBe('ORD-002')
        ->and($ignored['total'])->toBe(3);
});

it('acota al carrier a la bolsa y a los viajes que tomó su empresa', function () {
    $mine = Carrier::factory()->create();
    $other = Carrier::factory()->create();
    $pool = Trip::factory()->create();
    $ours = tripToolTripOf($mine);
    tripToolTripOf($other);

    $owner = User::query()->findOrFail($mine->user_id);
    $result = callTripTool(tripTool(TripsTool::class, $owner));

    expect($result['total'])->toBe(2)
        ->and(array_column($result['trips'], 'id'))->toEqualCanonicalizing([$pool->id, $ours->id]);
});

/*
|--------------------------------------------------------------------------
| trip
|--------------------------------------------------------------------------
*/

it('devuelve el detalle del viaje sin la ruta ni las imágenes', function () {
    $trip = tripToolTripOf(Carrier::factory()->create());
    TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id, 'gallons' => 30]);
    TripFuel::factory()->create(['trip_id' => $trip->id, 'gallons' => 5]);

    $result = callTripTool(tripTool(TripTool::class, tripToolAdmin()), ['tripId' => $trip->id]);

    expect($result['id'])->toBe($trip->id)
        ->and($result)->toHaveKeys(['order', 'clientName', 'pilotName', 'vehiclePlate', 'assignedByName', 'estimatedKilometers', 'totalFuelGallons', 'totalExpensesAmount'])
        ->and($result)->not->toHaveKeys(['polyline', 'points', 'traveledPolyline', 'traveledPoints', 'pilotDpiImage', 'pilotLicenseImage', 'vehicleImage'])
        ->and($result['totalFuelGallons'])->toBe('30.00');
});

it('devuelve como error el viaje inexistente y el ajeno', function () {
    $other = Carrier::factory()->create();
    $foreign = tripToolTripOf($other);
    $owner = User::query()->findOrFail(Carrier::factory()->create()->user_id);

    $missing = callTripTool(tripTool(TripTool::class, tripToolAdmin()), ['tripId' => 999999]);
    $forbidden = callTripTool(tripTool(TripTool::class, $owner), ['tripId' => $foreign->id]);

    expect($missing)->toHaveKey('error')
        ->and($forbidden)->toHaveKey('error')
        ->and($forbidden['error'])->not->toBe($missing['error']);
});

/*
|--------------------------------------------------------------------------
| trip_fuels · trip_expenses
|--------------------------------------------------------------------------
*/

it('lista las cargas del viaje sumando solo las confirmadas', function () {
    $trip = Trip::factory()->inRoute()->create();
    TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id, 'gallons' => 20]);
    TripFuel::factory()->create(['trip_id' => $trip->id, 'gallons' => 15]);

    $result = callTripTool(tripTool(TripFuelsTool::class, tripToolAdmin()), ['tripId' => $trip->id]);

    expect($result['totalGallons'])->toBe('20.00')
        ->and($result['total'])->toBe(2)
        ->and($result['returned'])->toBe(2)
        ->and(array_column($result['fuels'], 'isConfirmed'))->toBe([true, false])
        ->and($result['fuels'][0])->toHaveKeys(['gallons', 'fuelType', 'loadedAt', 'confirmedByName']);
});

it('lista los viáticos del viaje sumando solo los confirmados', function () {
    $trip = Trip::factory()->inRoute()->create();
    TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 500]);
    TripExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 250]);

    $result = callTripTool(tripTool(TripExpensesTool::class, tripToolAdmin()), ['tripId' => $trip->id]);

    expect($result['totalAmount'])->toBe('500.00')
        ->and($result['total'])->toBe(2)
        ->and(array_column($result['expenses'], 'isConfirmed'))->toBe([true, false])
        ->and($result['expenses'][0])->toHaveKeys(['amount', 'description', 'receivedAt']);
});

/*
|--------------------------------------------------------------------------
| trip_timeouts
|--------------------------------------------------------------------------
*/

it('lista las paradas del viaje con su duración y null en la abierta', function () {
    $trip = Trip::factory()->inRoute()->create();
    TripTimeout::factory()->create(['trip_id' => $trip->id]);
    TripTimeout::factory()->open()->create(['trip_id' => $trip->id]);

    $result = callTripTool(tripTool(TripTimeoutsTool::class, tripToolAdmin()), ['tripId' => $trip->id]);

    expect($result['total'])->toBe(2)
        ->and($result['timeouts'][0]['durationMinutes'])->toEqual(30)
        ->and($result['timeouts'][1]['durationMinutes'])->toBeNull()
        ->and($result['timeouts'][1]['endedAt'])->toBeNull();
});

it('devuelve como error las paradas de un viaje ajeno', function () {
    $foreign = tripToolTripOf(Carrier::factory()->create());
    $owner = User::query()->findOrFail(Carrier::factory()->create()->user_id);

    $result = callTripTool(tripTool(TripTimeoutsTool::class, $owner), ['tripId' => $foreign->id]);

    expect($result)->toHaveKey('error');
});

/*
|--------------------------------------------------------------------------
| trip_cost
|--------------------------------------------------------------------------
*/

it('devuelve el mismo desglose que el endpoint para un viaje finalizado', function () {
    $carrier = Carrier::factory()->create();
    $trip = tripToolTripOf($carrier, [
        'status' => TripStatus::Finished,
        'start_date' => now()->subHours(3),
        'end_date' => now(),
        'traveled_hours' => 2.50,
    ]);

    FuelPrice::factory()->create([
        'fuel_type' => FuelType::Diesel,
        'price' => 38.50,
        'created_at' => '2026-01-01 08:00:00',
    ]);
    TripFuel::factory()->create([
        'trip_id' => $trip->id,
        'gallons' => 35,
        'fuel_type' => FuelType::Diesel,
        'loaded_at' => '2026-02-10 09:00:00',
        'confirmed_by' => $trip->pilot_id,
    ]);
    TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 450]);
    CarrierPilot::query()->where('user_id', '=', $trip->pilot_id)->update(['salary' => 4500.00]);
    $trip->vehicle->update(['monthly_insurance_cost' => 350.00]);

    $result = callTripTool(tripTool(TripCostTool::class, tripToolAdmin()), ['tripId' => $trip->id]);

    expect(array_keys($result))
        ->toBe(['tripId', 'order', 'traveledHours', 'fuel', 'expenses', 'pilot', 'vehicle', 'totalCost'])
        ->and($result['fuel']['subtotal'])->toBe('1347.50')
        ->and($result['expenses']['subtotal'])->toBe('450.00')
        ->and($result['pilot']['subtotal'])->toBe('15.63')
        ->and($result['vehicle']['subtotal'])->toBe('1.22')
        ->and($result['totalCost'])->toBe('1814.35');
});

it('devuelve el 400 del viaje en curso como error y no como excepción', function () {
    $trip = Trip::factory()->inRoute()->create();

    $result = callTripTool(tripTool(TripCostTool::class, tripToolAdmin()), ['tripId' => $trip->id]);

    expect($result)->toBe(['error' => 'El costo solo está disponible para viajes finalizados']);
});

it('devuelve como error el costo de un viaje inexistente y el del viaje ajeno', function () {
    $foreign = tripToolTripOf(Carrier::factory()->create(), [
        'status' => TripStatus::Finished,
        'traveled_hours' => 1.00,
    ]);
    $owner = User::query()->findOrFail(Carrier::factory()->create()->user_id);

    $missing = callTripTool(tripTool(TripCostTool::class, tripToolAdmin()), ['tripId' => 999999]);
    $forbidden = callTripTool(tripTool(TripCostTool::class, $owner), ['tripId' => $foreign->id]);

    expect($missing['error'])->toBe('El viaje no existe')
        ->and($forbidden['error'])->toBe('No puedes acceder a un viaje que no pertenece a tu empresa transportista');
});

it('veta al piloto también desde la herramienta, no solo en el middleware del chat', function () {
    $trip = tripToolTripOf(Carrier::factory()->create(), [
        'status' => TripStatus::Finished,
        'traveled_hours' => 1.00,
    ]);
    $pilot = User::query()->findOrFail($trip->pilot_id);

    $result = callTripTool(tripTool(TripCostTool::class, $pilot), ['tripId' => $trip->id]);

    expect($result)->toBe(['error' => 'No tienes permisos para consultar el costo de un viaje']);
});
