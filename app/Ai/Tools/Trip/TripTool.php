<?php

namespace App\Ai\Tools\Trip;

use App\Ai\Tools\AssistantTool;
use App\Http\Resources\Trip\TripResource;
use App\Interfaces\Trip\TripServiceInterface;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Ai\Tools\Request;

/**
 * `GET /api/trips/{trip}` as a tool: the full record of one trip.
 *
 * The 40 keys of `TripResource` minus the ones that mean nothing to a chat and
 * weigh the most: the two polylines with their decoded points —hundreds of
 * coordinates the model cannot reason about— and the three image URLs.
 */
class TripTool extends AssistantTool
{
    /** Keys of `TripResource` the model never receives. */
    private const array OMITTED_KEYS = ['polyline', 'points', 'traveledPolyline', 'traveledPoints', 'pilotDpiImage', 'pilotLicenseImage', 'vehicleImage'];

    public function __construct(
        User $user,
        private readonly TripServiceInterface $trips,
    ) {
        parent::__construct($user);
    }

    public function name(): string
    {
        return 'trip';
    }

    public function description(): string
    {
        return 'El detalle completo de un viaje por su id: orden, estado, cliente, naviera, punto de partida, puerto de destino, destino, contenedor, transporte, fechas de recolección, embarque, inicio y fin, kilómetros y horas estimados, observaciones, piloto y vehículo asignados, empresa que lo asignó, quién lo registró, galones de combustible confirmados (totalFuelGallons) y viáticos confirmados (totalExpensesAmount). No incluye la ruta ni el recorrido (se ven en el mapa) ni imágenes. Si no conoces el id, búscalo antes con trips.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tripId' => $schema->integer()->required()->description('Id del viaje.'),
        ];
    }

    protected function arguments(Request $request): array
    {
        return ['tripId' => $this->resourceId($request, 'tripId')];
    }

    protected function query(array $filters): array
    {
        $trip = $this->trips->getTripById($this->user, $filters['tripId']);

        return Arr::except(new TripResource($trip)->resolve(), self::OMITTED_KEYS);
    }
}
