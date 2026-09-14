<?php

namespace App\Http\Resources\Dashboard;

use App\Models\Trip;
use App\Models\TripPosition;
use App\Models\TripTimeout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin Trip
 */
#[OA\Schema(
    schema: 'TripInRouteLastPosition',
    title: 'Última posición de un viaje en curso',
    description: 'El punto de trip_positions con mayor recorded_at (desempate por id) del viaje, resuelto en una sola consulta para todos los viajes. TRES CLAVES: las coordenadas como cadena de ocho decimales, como en TripPosition, y recordedAt en d-m-Y h:i:s A. No trae id ni pilotId: para el rastro completo está GET /api/trips/{trip}/positions.',
    properties: [
        new OA\Property(property: 'latitude', type: 'string', example: '14.60000000'),
        new OA\Property(property: 'longitude', type: 'string', example: '-90.50000000'),
        new OA\Property(property: 'recordedAt', type: 'string', example: '14-09-2026 09:00:15 AM'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripInRouteOpenTimeout',
    title: 'Parada abierta de un viaje en curso',
    description: 'La parada de trip_timeouts con ended_at IS NULL del viaje, si la hay. CUATRO CLAVES: startedAt y las coordenadas del ancla, como en TripTimeout, más stoppedMinutes. ATENCIÓN — stoppedMinutes SE MIDE CONTRA now() DEL SERVIDOR, al revés que durationMinutes de GET /api/trips/{trip}/timeouts, que es null mientras la parada siga abierta: este endpoint es una foto del momento y el valor CAMBIA EN CADA LECTURA. round((now() − startedAt) / 60, 2), como número con dos decimales.',
    properties: [
        new OA\Property(property: 'startedAt', type: 'string', example: '14-09-2026 08:50:00 AM'),
        new OA\Property(property: 'latitude', type: 'string', example: '14.60000000'),
        new OA\Property(property: 'longitude', type: 'string', example: '-90.50000000'),
        new OA\Property(property: 'stoppedMinutes', type: 'number', format: 'float', example: 10.25),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripInRoute',
    title: 'Viaje en curso en el tablero',
    description: <<<'TEXT'
    Un viaje in_route con lo que hace falta para pintarlo en un mapa en vivo: identificación, quién lo lleva, dónde está, cuánto combustible tiene y si está parado. DIECISÉIS CLAVES en camelCase y ninguna más, en este orden: tripId, order, container, carrierId, carrierName, pilotId, pilotName, vehicleId, vehiclePlate, clientName, locationName, startDate, lastPosition, totalFuelGallons, unconfirmedFuelGallons, openTimeout.

    ATENCIÓN — NO ES EL Trip DE GET /api/trips/{trip} NI EL TripListItem del listado: no trae status (siempre es in_route), ni polyline, ni points, ni fechas de recolección o embarque. tripId coincide con trips.id, así que el detalle y el rastro se piden a su dominio.

    carrierId y carrierName son los de la empresa de assignedBy (su HasOne carrier): un viaje in_route siempre fue asignado por el dueño de una empresa, así que en la práctica nunca son null.

    lastPosition es null si el viaje aún no tiene ningún punto; openTimeout es null si no hay parada con ended_at IS NULL. Las dos sumas de combustible salen como cadena de dos decimales: totalFuelGallons es el MISMO número que en TripResource (cargas confirmadas); unconfirmedFuelGallons suma las que el piloto todavía no confirmó. Para verlas una a una está GET /api/trips/{trip}/fuels.
    TEXT,
    properties: [
        new OA\Property(property: 'tripId', type: 'integer', example: 18),
        new OA\Property(property: 'order', type: 'string', example: 'ORD-001'),
        new OA\Property(property: 'container', type: 'string', example: 'MSKU1234567'),
        new OA\Property(property: 'carrierId', type: 'integer', example: 3, nullable: true),
        new OA\Property(property: 'carrierName', type: 'string', example: 'TRANSPORTES X', nullable: true),
        new OA\Property(property: 'pilotId', type: 'integer', example: 9),
        new OA\Property(property: 'pilotName', type: 'string', example: 'Juan Pérez', nullable: true),
        new OA\Property(property: 'vehicleId', type: 'integer', example: 4),
        new OA\Property(property: 'vehiclePlate', type: 'string', example: 'P123ABC', nullable: true),
        new OA\Property(property: 'clientName', type: 'string', example: 'CLIENTE A', nullable: true),
        new OA\Property(property: 'locationName', type: 'string', example: 'PUERTO QUETZAL', nullable: true),
        new OA\Property(property: 'startDate', description: 'Fecha de inicio en d-m-Y h:i:s A, nunca ISO 8601.', type: 'string', example: '14-09-2026 08:15:00 AM', nullable: true),
        new OA\Property(property: 'lastPosition', ref: '#/components/schemas/TripInRouteLastPosition', nullable: true),
        new OA\Property(property: 'totalFuelGallons', type: 'string', example: '45.00'),
        new OA\Property(property: 'unconfirmedFuelGallons', type: 'string', example: '10.00'),
        new OA\Property(property: 'openTimeout', ref: '#/components/schemas/TripInRouteOpenTimeout', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripInRouteListResponse',
    title: 'Viajes en curso',
    description: 'Respuesta de GET /api/dashboard/trips/in-route: TODOS los viajes in_route, SIN PAGINAR y en orden start_date descendente (desempate por id descendente). Sin viajes en curso, data es [] con 200, nunca 404.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Viajes en curso obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TripInRoute')),
    ],
    type: 'object',
)]
class TripInRouteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Sixteen keys. `lastPosition` and `openTimeout` are transient attributes the
     * service attached after two set-wide queries; the fuel sums come from the double
     * `withSum`. The Resource never queries anything.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lastPosition = $this->getAttribute('lastPosition');
        $openTimeout = $this->getAttribute('openTimeout');
        $carrier = $this->assignedBy?->carrier;

        return [
            'tripId' => $this->id,
            'order' => $this->order,
            'container' => $this->container,
            'carrierId' => $carrier?->id,
            'carrierName' => $carrier?->name,
            'pilotId' => $this->pilot_id,
            'pilotName' => $this->pilot?->name,
            'vehicleId' => $this->vehicle_id,
            'vehiclePlate' => $this->vehicle?->plate,
            'clientName' => $this->client?->name,
            'locationName' => $this->location?->name,
            'startDate' => $this->start_date?->format('d-m-Y h:i:s A'),
            'lastPosition' => $lastPosition instanceof TripPosition ? [
                'latitude' => $lastPosition->latitude,
                'longitude' => $lastPosition->longitude,
                'recordedAt' => $lastPosition->recorded_at?->format('d-m-Y h:i:s A'),
            ] : null,
            'totalFuelGallons' => number_format((float) ($this->total_fuel_gallons ?? 0), 2, '.', ''),
            'unconfirmedFuelGallons' => number_format((float) ($this->unconfirmed_fuel_gallons ?? 0), 2, '.', ''),
            /**
             * Medido contra now() a propósito, al revés que durationMinutes de la parada
             * histórica: este endpoint es una foto del momento y su único valor es cambiar.
             */
            'openTimeout' => $openTimeout instanceof TripTimeout ? [
                'startedAt' => $openTimeout->started_at?->format('d-m-Y h:i:s A'),
                'latitude' => $openTimeout->latitude,
                'longitude' => $openTimeout->longitude,
                'stoppedMinutes' => round($openTimeout->started_at->diffInSeconds(now()) / 60, 2),
            ] : null,
        ];
    }
}
