<?php

use App\Ai\Tools\AssistantTool;
use App\Ai\Tools\Report\ExportTripEmergencyExpensesTool;
use App\Ai\Tools\Report\ExportTripsTool;
use App\Ai\Tools\Report\ExportVehicleExpensesTool;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Interfaces\Report\ReportServiceInterface;
use App\Interfaces\Storage\FileStorageServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripEmergencyExpense;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Tests\Doubles\InMemoryFileStorageService;

/**
 * Build a report tool for the given user against the real services, like the agent does.
 */
function reportTool(string $tool, User $user): AssistantTool
{
    return new $tool($user, app(ReportServiceInterface::class));
}

/**
 * Run a tool with the arguments the model would send and decode its JSON answer.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function callReportTool(AssistantTool $tool, array $arguments = []): array
{
    return json_decode($tool->handle(new Request($arguments)), true, flags: JSON_THROW_ON_ERROR);
}

function reportToolAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/*
|--------------------------------------------------------------------------
| Schemas
|--------------------------------------------------------------------------
*/

it('no obliga a ningún argumento en la exportación de viajes y no admite limit', function () {
    $factory = new JsonSchemaTypeFactory;
    $schema = $factory->object(reportTool(ExportTripsTool::class, reportToolAdmin())->schema($factory))->toArray();

    expect($schema)->not->toHaveKey('required')
        ->and($schema['properties'])->toHaveKeys(['status', 'clientId', 'shippingLineId', 'locationId', 'pilotId', 'vehicleId', 'dateFrom', 'dateTo', 'search'])
        ->and($schema['properties'])->not->toHaveKey('limit');
});

it('exige vehicleId en la exportación de gastos y no admite limit', function () {
    $factory = new JsonSchemaTypeFactory;
    $schema = $factory->object(reportTool(ExportVehicleExpensesTool::class, reportToolAdmin())->schema($factory))->toArray();

    expect($schema['required'])->toBe(['vehicleId'])
        ->and($schema['properties'])->toHaveKeys(['category', 'nature', 'dateFrom', 'dateTo', 'isInvoiced'])
        ->and($schema['properties'])->not->toHaveKey('limit');
});

it('exige tripId en la exportación de gastos emergentes y no admite nada más', function () {
    $factory = new JsonSchemaTypeFactory;
    $schema = $factory->object(reportTool(ExportTripEmergencyExpensesTool::class, reportToolAdmin())->schema($factory))->toArray();

    expect($schema['required'])->toBe(['tripId'])
        ->and(array_keys($schema['properties']))->toBe(['tripId']);
});

it('relaya al modelo la falta de tripId en la exportación de gastos emergentes', function () {
    expect(fn () => reportTool(ExportTripEmergencyExpensesTool::class, reportToolAdmin())->handle(new Request([])))
        ->toThrow(ValidationException::class);
});

it('relaya al modelo la falta de vehicleId como error de validación', function () {
    expect(fn () => reportTool(ExportVehicleExpensesTool::class, reportToolAdmin())->handle(new Request(['nature' => 'preventive'])))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| export_trips
|--------------------------------------------------------------------------
*/

it('genera el xlsx de los viajes filtrados y devuelve su URL con el conteo', function () {
    Trip::factory()->finished()->count(2)->create();
    Trip::factory()->create();

    $result = callReportTool(reportTool(ExportTripsTool::class, reportToolAdmin()), ['status' => TripStatus::Finished->value]);

    expect($result)->toHaveKeys(['fileName', 'url', 'rows', 'total', 'truncated'])
        ->and($result['rows'])->toBe(2)
        ->and($result['total'])->toBe(2)
        ->and($result['truncated'])->toBeFalse()
        ->and($result['fileName'])->toEndWith('.xlsx');

    expect(Storage::disk(config('filesystems.default'))->allFiles('reports'))->toHaveCount(1);
});

it('ignora un limit que el modelo invente en la exportación de viajes', function () {
    Trip::factory()->count(3)->create();

    $result = callReportTool(reportTool(ExportTripsTool::class, reportToolAdmin()), ['limit' => 1]);

    expect($result['rows'])->toBe(3);
});

it('devuelve como error un fallo del almacenamiento en vez de romper el turno', function () {
    app()->instance(FileStorageServiceInterface::class, new InMemoryFileStorageService(failing: true));
    Trip::factory()->create();

    expect(callReportTool(reportTool(ExportTripsTool::class, reportToolAdmin())))->toHaveKey('error');
});

/*
|--------------------------------------------------------------------------
| export_vehicle_expenses
|--------------------------------------------------------------------------
*/

it('genera el xlsx de los gastos del vehículo con el total de todos los filtrados', function () {
    $vehicle = Vehicle::factory()->create();
    VehicleExpense::factory()->count(2)->create(['vehicle_id' => $vehicle->id, 'amount' => 150]);

    $result = callReportTool(reportTool(ExportVehicleExpensesTool::class, reportToolAdmin()), ['vehicleId' => $vehicle->id]);

    expect($result)->toHaveKeys(['fileName', 'url', 'rows', 'total', 'truncated', 'totalAmount'])
        ->and($result['rows'])->toBe(2)
        ->and($result['totalAmount'])->toBe('300.00')
        ->and($result['fileName'])->toStartWith("gastos-vehiculo-{$vehicle->id}-");
});

it('devuelve como error los gastos de un vehículo ajeno o inexistente', function () {
    $foreign = Vehicle::factory()->create(['carrier_id' => Carrier::factory()->create()->id]);
    $owner = User::query()->findOrFail(Carrier::factory()->create()->user_id);

    expect(callReportTool(reportTool(ExportVehicleExpensesTool::class, $owner), ['vehicleId' => $foreign->id]))->toHaveKey('error')
        ->and(callReportTool(reportTool(ExportVehicleExpensesTool::class, reportToolAdmin()), ['vehicleId' => 999999]))->toHaveKey('error');
});

/*
|--------------------------------------------------------------------------
| export_trip_emergency_expenses (SPEC 39)
|--------------------------------------------------------------------------
*/

it('genera el xlsx de los gastos emergentes del viaje con su total', function () {
    $trip = Trip::factory()->inRoute()->create();
    TripEmergencyExpense::factory()->count(2)->create(['trip_id' => $trip->id, 'amount' => 175]);

    $result = callReportTool(reportTool(ExportTripEmergencyExpensesTool::class, reportToolAdmin()), ['tripId' => $trip->id]);

    expect($result)->toHaveKeys(['fileName', 'url', 'rows', 'total', 'truncated', 'totalAmount'])
        ->and($result['rows'])->toBe(2)
        ->and($result['total'])->toBe(2)
        ->and($result['totalAmount'])->toBe('350.00')
        ->and($result['truncated'])->toBeFalse()
        ->and($result['fileName'])->toStartWith("gastos-emergentes-viaje-{$trip->id}-");

    expect(Storage::disk(config('filesystems.default'))->allFiles('reports'))->toHaveCount(1);
});

it('devuelve como error los gastos emergentes de un viaje ajeno, inexistente o para shipment', function () {
    $trip = Trip::factory()->inRoute()->create();
    $owner = User::query()->findOrFail(Carrier::factory()->create()->user_id);
    $shipment = User::factory()->create(['role' => UserRole::Shipment]);

    expect(callReportTool(reportTool(ExportTripEmergencyExpensesTool::class, $owner), ['tripId' => $trip->id]))->toHaveKey('error')
        ->and(callReportTool(reportTool(ExportTripEmergencyExpensesTool::class, reportToolAdmin()), ['tripId' => 999999]))->toHaveKey('error')
        ->and(callReportTool(reportTool(ExportTripEmergencyExpensesTool::class, $shipment), ['tripId' => $trip->id]))->toHaveKey('error');

    expect(Storage::disk(config('filesystems.default'))->allFiles('reports'))->toBe([]);
});
