<?php

use App\Ai\Tools\AssistantTool;
use App\Ai\Tools\Vehicle\VehicleExpensesTool;
use App\Ai\Tools\Vehicle\VehicleTool;
use App\Enums\UserRole;
use App\Enums\VehicleExpenseNature;
use App\Interfaces\Dashboard\DashboardServiceInterface;
use App\Interfaces\VehicleExpense\VehicleExpenseServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;

/**
 * Build a vehicle tool for the given user against the real services, like the agent does.
 */
function vehicleTool(string $tool, User $user): AssistantTool
{
    return match ($tool) {
        VehicleTool::class => new VehicleTool($user, app(DashboardServiceInterface::class)),
        VehicleExpensesTool::class => new VehicleExpensesTool($user, app(VehicleExpenseServiceInterface::class)),
    };
}

/**
 * Run a tool with the arguments the model would send and decode its JSON answer.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function callVehicleTool(AssistantTool $tool, array $arguments = []): array
{
    return json_decode($tool->handle(new Request($arguments)), true, flags: JSON_THROW_ON_ERROR);
}

function vehicleToolAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/*
|--------------------------------------------------------------------------
| Schemas
|--------------------------------------------------------------------------
*/

it('no obliga a ningún argumento en la ficha del vehículo y exige vehicleId en sus gastos', function () {
    $factory = new JsonSchemaTypeFactory;
    $vehicle = $factory->object(vehicleTool(VehicleTool::class, vehicleToolAdmin())->schema($factory))->toArray();
    $expenses = $factory->object(vehicleTool(VehicleExpensesTool::class, vehicleToolAdmin())->schema($factory))->toArray();

    expect($vehicle)->not->toHaveKey('required')
        ->and($vehicle['properties'])->toHaveKeys(['vehicleId', 'plate'])
        ->and($expenses['required'])->toBe(['vehicleId'])
        ->and($expenses['properties'])->toHaveKeys(['category', 'nature', 'dateFrom', 'dateTo', 'isInvoiced', 'limit']);
});

it('relaya al modelo la falta de vehicleId en los gastos como error de validación', function () {
    expect(fn () => vehicleTool(VehicleExpensesTool::class, vehicleToolAdmin())->handle(new Request(['nature' => 'preventive'])))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| vehicle
|--------------------------------------------------------------------------
*/

it('devuelve la ficha del vehículo por id con las columnas financieras y su viaje en curso', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'purchase_price' => 250000, 'monthly_insurance_cost' => 1500]);
    $trip = Trip::factory()->inRoute()->create(['vehicle_id' => $vehicle->id]);

    $result = callVehicleTool(vehicleTool(VehicleTool::class, vehicleToolAdmin()), ['vehicleId' => $vehicle->id]);

    expect($result['id'])->toBe($vehicle->id)
        ->and($result)->toHaveKeys(['plate', 'type', 'status', 'condition', 'mileage', 'kilometersPerGallon', 'carrierName', 'inRoute', 'currentTrip', 'purchasePrice', 'monthlyInsuranceCost'])
        ->and($result['inRoute'])->toBeTrue()
        ->and($result['currentTrip']['tripId'])->toBe($trip->id)
        ->and((float) $result['purchasePrice'])->toBe(250000.0)
        ->and((float) $result['monthlyInsuranceCost'])->toBe(1500.0);
});

it('localiza el vehículo por placa sin distinguir mayúsculas', function () {
    $vehicle = Vehicle::factory()->create(['plate' => 'P123ABC']);
    Vehicle::factory()->create(['plate' => 'C456DEF']);

    $result = callVehicleTool(vehicleTool(VehicleTool::class, vehicleToolAdmin()), ['plate' => ' p123abc ']);

    expect($result['id'])->toBe($vehicle->id)
        ->and($result['plate'])->toBe('P123ABC');
});

it('devuelve como error la placa desconocida, el id inexistente y la falta de ambos', function () {
    Vehicle::factory()->create(['plate' => 'P123ABC']);
    $tool = vehicleTool(VehicleTool::class, vehicleToolAdmin());

    expect(callVehicleTool($tool, ['plate' => 'ZZZ999'])['error'])->toBe('No hay ningún vehículo con la placa ZZZ999')
        ->and(callVehicleTool($tool, ['vehicleId' => 999999])['error'])->toBe('El vehículo no existe')
        ->and(callVehicleTool($tool))->toHaveKey('error');
});

it('acota al carrier a su empresa por id y por placa, y deja ver todo al manager', function () {
    $mine = Carrier::factory()->create();
    $other = Carrier::factory()->create();
    $own = Vehicle::factory()->create(['carrier_id' => $mine->id, 'plate' => 'MINE001']);
    $foreign = Vehicle::factory()->create(['carrier_id' => $other->id, 'plate' => 'OTHER01']);

    $owner = User::query()->findOrFail($mine->user_id);
    $manager = User::factory()->create(['role' => UserRole::Manager]);

    expect(callVehicleTool(vehicleTool(VehicleTool::class, $owner), ['vehicleId' => $own->id])['id'])->toBe($own->id)
        ->and(callVehicleTool(vehicleTool(VehicleTool::class, $owner), ['vehicleId' => $foreign->id]))->toHaveKey('error')
        ->and(callVehicleTool(vehicleTool(VehicleTool::class, $owner), ['plate' => 'OTHER01']))->toHaveKey('error')
        ->and(callVehicleTool(vehicleTool(VehicleTool::class, $manager), ['plate' => 'OTHER01'])['id'])->toBe($foreign->id);
});

/*
|--------------------------------------------------------------------------
| vehicle_expenses
|--------------------------------------------------------------------------
*/

it('lista los gastos del vehículo con el total de todos los filtrados y sin la URL de la factura', function () {
    $vehicle = Vehicle::factory()->create();
    VehicleExpense::factory()->invoiced()->create(['vehicle_id' => $vehicle->id, 'amount' => 300, 'nature' => VehicleExpenseNature::Preventive]);
    VehicleExpense::factory()->create(['vehicle_id' => $vehicle->id, 'amount' => 200, 'nature' => VehicleExpenseNature::Corrective]);
    VehicleExpense::factory()->create(['amount' => 999]);

    $tool = vehicleTool(VehicleExpensesTool::class, vehicleToolAdmin());
    $all = callVehicleTool($tool, ['vehicleId' => $vehicle->id]);
    $preventive = callVehicleTool($tool, ['vehicleId' => $vehicle->id, 'nature' => 'preventive']);

    expect($all['totalAmount'])->toBe('500.00')
        ->and($all['total'])->toBe(2)
        ->and($all['returned'])->toBe(2)
        ->and($all['expenses'][0])->toHaveKeys(['category', 'nature', 'amount', 'expenseDate', 'isInvoiced', 'invoiceType'])
        ->and($all['expenses'][0])->not->toHaveKey('invoiceUrl')
        ->and($preventive['totalAmount'])->toBe('300.00')
        ->and($preventive['total'])->toBe(1);
});

it('devuelve como error los gastos de un vehículo ajeno o inexistente', function () {
    $foreign = Vehicle::factory()->create(['carrier_id' => Carrier::factory()->create()->id]);
    $owner = User::query()->findOrFail(Carrier::factory()->create()->user_id);

    expect(callVehicleTool(vehicleTool(VehicleExpensesTool::class, $owner), ['vehicleId' => $foreign->id]))->toHaveKey('error')
        ->and(callVehicleTool(vehicleTool(VehicleExpensesTool::class, vehicleToolAdmin()), ['vehicleId' => 999999]))->toHaveKey('error');
});
