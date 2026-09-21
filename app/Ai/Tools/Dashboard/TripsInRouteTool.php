<?php

namespace App\Ai\Tools\Dashboard;

use App\Http\Resources\Dashboard\TripInRouteResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * `GET /api/dashboard/trips/in-route` as a tool: the live picture of the trips on the road.
 */
class TripsInRouteTool extends DashboardTool
{
    protected const array FILTERS = ['carrierId'];

    public function name(): string
    {
        return 'trips_in_route';
    }

    public function description(): string
    {
        return 'Lista de los viajes que están en ruta en este momento, del más reciente en arrancar al más antiguo, sin paginar. Por viaje: orden, contenedor, empresa, piloto, placa del vehículo, cliente, puerto, fecha de inicio, última posición registrada (lastPosition, o null si no hay puntos), galones confirmados (totalFuelGallons), galones pendientes de confirmar (unconfirmedFuelGallons) y la parada abierta (openTimeout, o null si no está detenido) con stoppedMinutes medido contra el momento actual. No acepta fechas. Sin viajes en ruta devuelve una lista vacía.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'carrierId' => $this->carrierIdSchema($schema),
        ];
    }

    protected function query(array $filters): array
    {
        $trips = $this->dashboard->getTripsInRoute($this->user, $filters);

        return [
            'count' => $trips->count(),
            'trips' => TripInRouteResource::collection($trips)->resolve(),
        ];
    }
}
