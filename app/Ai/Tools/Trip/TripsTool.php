<?php

namespace App\Ai\Tools\Trip;

use App\Ai\Tools\AssistantTool;
use App\Enums\TripStatus;
use App\Http\Resources\Trip\TripListResource;
use App\Interfaces\Trip\TripServiceInterface;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * `GET /api/trips` as a tool: search and list the trips within the caller's scope.
 *
 * Unlike the endpoint, pagination is not opt-in: the model always gets the first
 * page, capped at `DEFAULT_LIMIT` unless it asks for more (up to the service ceiling
 * of 100), so a long history cannot flood the context window. `total` tells it how
 * many trips the cut left out. The rows are the 17 keys of the listing —no ids of
 * relations, no route, no images—: the detail is one `trip` call away.
 */
class TripsTool extends AssistantTool
{
    protected const array FILTERS = ['status', 'clientId', 'shippingLineId', 'locationId', 'pilotId', 'vehicleId', 'dateFrom', 'dateTo', 'search', 'limit'];

    /** Rows returned when the model does not ask for a size. */
    private const int DEFAULT_LIMIT = 25;

    public function __construct(
        User $user,
        private readonly TripServiceInterface $trips,
    ) {
        parent::__construct($user);
    }

    public function name(): string
    {
        return 'trips';
    }

    public function description(): string
    {
        return 'Busca y lista viajes, del más reciente al más antiguo por fecha de recolección. Por viaje: id, orden, estado (pending, in_route, finished), naviera, punto de partida, puerto, contenedor, fechas de recolección, embarque, inicio y fin, kilómetros y horas estimados, observaciones, piloto, placa del vehículo y quién lo registró. Úsala para encontrar un viaje por su orden o contenedor (search) antes de pedir su detalle con trip, o para listar los de un estado, cliente, piloto, vehículo o periodo. Devuelve los primeros `limit` viajes y el total que cumple los filtros; si total es mayor que los devueltos, dilo. Un carrier ve la bolsa de viajes sin asignar más los que tomó su empresa.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(array_column(TripStatus::cases(), 'value'))
                ->description('Estado del viaje. Un valor fuera de la lista se ignora.'),
            'clientId' => $schema->integer()->description('Id del cliente del viaje.'),
            'shippingLineId' => $schema->integer()->description('Id de la naviera del viaje.'),
            'locationId' => $schema->integer()->description('Id del puerto de destino del viaje.'),
            'pilotId' => $schema->integer()->description('Id del piloto asignado al viaje.'),
            'vehicleId' => $schema->integer()->description('Id del vehículo asignado al viaje.'),
            ...$this->dateRangeSchema($schema, 'la fecha de recolección del viaje'),
            'search' => $schema->string()
                ->description('Texto a buscar dentro del número de orden o del contenedor, sin distinguir mayúsculas. Basta con una parte.'),
            'limit' => $this->limitSchema($schema, 1, self::DEFAULT_LIMIT, 'viajes'),
        ];
    }

    protected function query(array $filters): array
    {
        $filters['limit'] ??= (string) self::DEFAULT_LIMIT;

        /** @var LengthAwarePaginator $trips */
        $trips = $this->trips->getTrips($this->user, $filters);

        return [
            'total' => $trips->total(),
            'returned' => $trips->count(),
            'trips' => TripListResource::collection($trips->getCollection())->resolve(),
        ];
    }
}
