<?php

namespace App\Ai\Tools\Vehicle;

use App\Ai\Tools\AssistantTool;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Http\Resources\Dashboard\DashboardVehicleResource;
use App\Interfaces\Dashboard\DashboardServiceInterface;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;

/**
 * One vehicle of the fleet, by id or by plate, with the scope of the dashboard.
 *
 * Wraps `DashboardServiceInterface` and not `VehicleService` on purpose: the latter
 * leaves the `manager` out (403 without a company) and the chat admits it. By plate
 * the fleet the caller can see is fetched whole and matched in PHP —fleets are small
 * and the database stays behind the service—; the plate is stored upper cased, so the
 * comparison normalizes the argument the same way.
 *
 * The row is the dashboard one plus the two financial columns it leaves out on
 * purpose, because here the question is about this one vehicle.
 */
class VehicleTool extends AssistantTool
{
    protected const array FILTERS = ['vehicleId', 'plate'];

    public function __construct(
        User $user,
        private readonly DashboardServiceInterface $dashboard,
    ) {
        parent::__construct($user);
    }

    public function name(): string
    {
        return 'vehicle';
    }

    public function description(): string
    {
        return 'La ficha de un vehículo, por su id o por su placa: placa, tipo, estado (active, inactive, under_repair), condición (new, used), kilometraje, kilómetros por galón, precio de compra y costo mensual del seguro en quetzales, empresa dueña, y el viaje en ruta que lleva ahora mismo (currentTrip, o null). Envía vehicleId o plate, no hace falta los dos. Si no conoces ninguno, lista la flota con fleet.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicleId' => $schema->integer()->description('Id del vehículo.'),
            'plate' => $schema->string()->description('Placa del vehículo tal como la escriba el usuario; no distingue mayúsculas.'),
        ];
    }

    protected function query(array $filters): array
    {
        $vehicle = match (true) {
            isset($filters['vehicleId']) && is_numeric($filters['vehicleId']) => $this->dashboard->getVehicle($this->user, (int) $filters['vehicleId']),
            isset($filters['plate']) => $this->findByPlate($filters['plate']),
            default => throw new BadRequestError('Indica el id o la placa del vehículo'),
        };

        return [
            ...new DashboardVehicleResource($vehicle)->resolve(),
            'purchasePrice' => $vehicle->purchase_price,
            'monthlyInsuranceCost' => $vehicle->monthly_insurance_cost,
        ];
    }

    /**
     * Locate the vehicle by plate among the ones the caller can see.
     */
    private function findByPlate(string $plate): Vehicle
    {
        $plate = strtoupper(trim($plate));

        /** @var Collection<int, Vehicle> $fleet */
        $fleet = $this->dashboard->getVehicles($this->user, []);

        $vehicle = $fleet->first(fn (Vehicle $vehicle): bool => strtoupper($vehicle->plate) === $plate);

        if ($vehicle === null) {
            throw new NotFoundError("No hay ningún vehículo con la placa {$plate}");
        }

        return $vehicle;
    }
}
