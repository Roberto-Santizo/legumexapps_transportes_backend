<?php

namespace App\Http\Resources\Dashboard;

use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin Vehicle
 */
#[OA\Schema(
    schema: 'DashboardVehicleCurrentTrip',
    title: 'Viaje en curso de un vehículo de la flota',
    description: 'El viaje in_route del vehículo, reducido a CINCO claves para el tablero. Con más de un viaje in_route sobre el mismo vehículo —el dominio no valida solape— se devuelve el de start_date más reciente (desempate por id descendente). Para el detalle completo está GET /api/trips/{tripId}.',
    properties: [
        new OA\Property(property: 'tripId', type: 'integer', example: 18),
        new OA\Property(property: 'order', type: 'string', example: 'ORD-001'),
        new OA\Property(property: 'container', type: 'string', example: 'MSKU1234567'),
        new OA\Property(property: 'pilotName', type: 'string', example: 'Juan Pérez', nullable: true),
        new OA\Property(property: 'startDate', description: 'Fecha de inicio del viaje en formato d-m-Y h:i:s A, nunca ISO 8601.', type: 'string', example: '14-09-2026 08:15:00 AM', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DashboardVehicle',
    title: 'Vehículo de la flota en el tablero',
    description: <<<'TEXT'
    Un vehículo tal como lo ve el tablero: su ficha operativa y si está en ruta ahora mismo. ONCE CLAVES en camelCase y ninguna más, en este orden: id, plate, type, status, condition, mileage, kilometersPerGallon, carrierId, carrierName, inRoute, currentTrip.

    ATENCIÓN — NO ES EL Vehicle DE GET /api/vehicles: no trae brand, model, year, capacity, engineNumber ni image, y A PROPÓSITO no trae purchasePrice ni monthlyInsuranceCost. Los ids coinciden, así que el detalle completo se pide a su propio dominio.

    inRoute y currentTrip son redundantes por diseño: inRoute es true exactamente cuando currentTrip no es null. Un vehículo cuyo único viaje ya terminó sale con inRoute false y currentTrip null; uno inactive o under_repair puede salir con inRoute true si su viaje sigue in_route, porque el tablero no valida coherencia.

    type, status y condition salen con el valor crudo del enum en inglés (truck, active, used). kilometersPerGallon sale como cadena de dos decimales; mileage como entero en kilómetros.
    TEXT,
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 4),
        new OA\Property(property: 'plate', type: 'string', example: 'P123ABC'),
        new OA\Property(property: 'type', type: 'string', enum: ['truck', 'van', 'trailer', 'pickup'], example: 'truck'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive', 'under_repair'], example: 'active'),
        new OA\Property(property: 'condition', type: 'string', enum: ['new', 'used'], example: 'used'),
        new OA\Property(property: 'mileage', type: 'integer', example: 90000),
        new OA\Property(property: 'kilometersPerGallon', type: 'string', example: '12.50'),
        new OA\Property(property: 'carrierId', type: 'integer', example: 3),
        new OA\Property(property: 'carrierName', type: 'string', example: 'TRANSPORTES X', nullable: true),
        new OA\Property(property: 'inRoute', description: 'true exactamente cuando currentTrip no es null.', type: 'boolean', example: true),
        new OA\Property(property: 'currentTrip', ref: '#/components/schemas/DashboardVehicleCurrentTrip', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DashboardVehicleListResponse',
    title: 'Flota sin paginar',
    description: 'Respuesta de GET /api/dashboard/vehicles cuando NO se envía limit, o cuando no es numérico: TODA la flota —inactive incluidos— en orden id ascendente y sin total, currentPage ni lastPage. Sin vehículos, data es [] con 200.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Flota obtenida correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardVehicle')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedDashboardVehicleListResponse',
    title: 'Flota paginada',
    description: 'Respuesta de GET /api/dashboard/vehicles con un limit numérico: los metadatos de paginación salen APLANADOS EN LA RAÍZ del sobre, no bajo meta. El limit se acota a [10, 100]. total cuenta los vehículos que pasan los filtros —inRoute incluido, que se aplica ANTES de paginar—, no los de la página.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/DashboardVehicleListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class DashboardVehicleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Eleven keys and no financial columns. `currentTrip` is a transient attribute the
     * service attached after resolving the page's trips in one query: the Resource
     * never queries anything.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentTrip = $this->getAttribute('currentTrip');

        return [
            'id' => $this->id,
            'plate' => $this->plate,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'condition' => $this->condition->value,
            'mileage' => $this->mileage,
            'kilometersPerGallon' => $this->kilometers_per_gallon,
            'carrierId' => $this->carrier_id,
            'carrierName' => $this->carrier?->name,
            'inRoute' => $currentTrip instanceof Trip,
            'currentTrip' => $currentTrip instanceof Trip ? [
                'tripId' => $currentTrip->id,
                'order' => $currentTrip->order,
                'container' => $currentTrip->container,
                'pilotName' => $currentTrip->pilot?->name,
                'startDate' => $currentTrip->start_date?->format('d-m-Y h:i:s A'),
            ] : null,
        ];
    }
}
