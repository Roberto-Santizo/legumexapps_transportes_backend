<?php

namespace App\Ai\Tools\Dashboard;

use App\Enums\VehicleCondition;
use App\Enums\VehicleStatus;
use App\Http\Resources\Dashboard\DashboardVehicleResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * `GET /api/dashboard/vehicles` as a tool: the whole fleet with its current trip.
 *
 * Unlike the endpoint, pagination is not opt-in here: the model always gets the
 * first page, capped at `DEFAULT_LIMIT` unless it asks for more (up to the service
 * ceiling of 100), so a large fleet cannot flood the context window. `total` tells
 * it how many vehicles the cut left out.
 */
class FleetTool extends DashboardTool
{
    protected const array FILTERS = ['carrierId', 'status', 'condition', 'inRoute', 'limit'];

    /** Rows returned when the model does not ask for a size. */
    private const int DEFAULT_LIMIT = 25;

    public function name(): string
    {
        return 'fleet';
    }

    public function description(): string
    {
        return 'La flota de vehículos, incluidos los inactivos, en orden de id: placa, tipo, estado (active, inactive, under_repair), condición (new, used), kilometraje, kilómetros por galón, empresa dueña y el viaje en ruta que lleva ahora mismo (currentTrip, o null). Devuelve los primeros `limit` vehículos y el total que cumple los filtros; si total es mayor que los devueltos, dilo en vez de asumir que viste toda la flota. No acepta fechas.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'carrierId' => $this->carrierIdSchema($schema),
            'status' => $schema->string()
                ->enum(array_column(VehicleStatus::cases(), 'value'))
                ->description('Estado operativo del vehículo. Un valor fuera de la lista se ignora.'),
            'condition' => $schema->string()
                ->enum(array_column(VehicleCondition::cases(), 'value'))
                ->description('Cómo se adquirió el vehículo. Independiente del estado; un valor fuera de la lista se ignora.'),
            'inRoute' => $schema->boolean()
                ->description('true: solo vehículos con un viaje en ruta ahora; false: solo los que no lo tienen. Sin él, todos.'),
            'limit' => $schema->integer()
                ->min(10)
                ->max(100)
                ->description('Cuántos vehículos devolver, entre 10 y 100. Por defecto '.self::DEFAULT_LIMIT.'.'),
        ];
    }

    protected function query(array $filters): array
    {
        $filters['limit'] ??= (string) self::DEFAULT_LIMIT;

        /** @var LengthAwarePaginator $vehicles */
        $vehicles = $this->dashboard->getVehicles($this->user, $filters);

        return [
            'total' => $vehicles->total(),
            'returned' => $vehicles->count(),
            'vehicles' => DashboardVehicleResource::collection($vehicles->getCollection())->resolve(),
        ];
    }
}
