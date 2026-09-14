<?php

namespace App\Http\Resources\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TripsSummaryByStatus',
    title: 'Viajes por estado',
    description: 'Conteo de viajes por cada uno de los TRES estados de TripStatus. LAS TRES CLAVES SALEN SIEMPRE, con 0 cuando no hay filas: el frontend las conoce de antemano y no tiene que comprobar si existen. Las claves van en camelCase (inRoute), no con el valor crudo del enum (in_route). Su suma es igual a total.',
    properties: [
        new OA\Property(property: 'pending', type: 'integer', example: 30),
        new OA\Property(property: 'inRoute', type: 'integer', example: 12),
        new OA\Property(property: 'finished', type: 'integer', example: 78),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripsSummaryByCarrier',
    title: 'Viajes por empresa transportista',
    description: 'Una fila por empresa con al menos un viaje. La empresa se resuelve en SQL con carriers.user_id = trips.assigned_by: /assignment exige role:carrier, así que assigned_by es siempre el dueño de una empresa. ATENCIÓN — LOS VIAJES SIN ASIGNAR NO APARECEN AQUÍ: no tienen empresa, así que la suma de total de este desglose puede ser MENOR que el total de la raíz. La diferencia es exactamente unassigned más los viajes asignados por un usuario que ya no es dueño de ninguna empresa.',
    properties: [
        new OA\Property(property: 'carrierId', type: 'integer', example: 3),
        new OA\Property(property: 'carrierName', type: 'string', example: 'TRANSPORTES X'),
        new OA\Property(property: 'total', type: 'integer', example: 40),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripsSummaryByClient',
    title: 'Viajes por cliente',
    description: 'Una fila por cliente con al menos un viaje, ordenadas por total descendente y, a igual total, por clientId ascendente. Los clientes borrados (SoftDeletes) siguen apareciendo si tienen viajes: el desglose lee la tabla en crudo con un JOIN.',
    properties: [
        new OA\Property(property: 'clientId', type: 'integer', example: 1),
        new OA\Property(property: 'clientName', type: 'string', example: 'CLIENTE A'),
        new OA\Property(property: 'total', type: 'integer', example: 55),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripsSummaryByShippingLine',
    title: 'Viajes por naviera',
    description: 'Una fila por naviera con al menos un viaje, ordenadas por total descendente y, a igual total, por shippingLineId ascendente.',
    properties: [
        new OA\Property(property: 'shippingLineId', type: 'integer', example: 2),
        new OA\Property(property: 'shippingLineName', type: 'string', example: 'MAERSK'),
        new OA\Property(property: 'total', type: 'integer', example: 60),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripsSummaryByLocation',
    title: 'Viajes por puerto de destino',
    description: 'Una fila por destino (location) con al menos un viaje, ordenadas por total descendente y, a igual total, por locationId ascendente. En la práctica son siempre puertos: el alta de un viaje exige un destino de tipo port.',
    properties: [
        new OA\Property(property: 'locationId', type: 'integer', example: 5),
        new OA\Property(property: 'locationName', type: 'string', example: 'PUERTO QUETZAL'),
        new OA\Property(property: 'total', type: 'integer', example: 90),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripsSummaryByMonth',
    title: 'Viajes por mes de recolección',
    description: 'Una fila por mes con al menos un viaje, agrupando recolection_date como YYYY-MM y ordenadas por month ASCENDENTE. ATENCIÓN — LOS MESES SIN VIAJES NO APARECEN: la API no rellena huecos con ceros, porque sin filtro de fechas no sabría desde cuándo hasta cuándo. El frontend conoce el rango que pidió y rellena.',
    properties: [
        new OA\Property(property: 'month', type: 'string', example: '2026-08'),
        new OA\Property(property: 'total', type: 'integer', example: 41),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripsSummary',
    title: 'Resumen de viajes del tablero',
    description: <<<'TEXT'
    Respuesta de GET /api/dashboard/trips: agregados de viajes calculados en vivo sobre la tabla trips, sin tabla ni caché propias. OCHO CLAVES en camelCase y ninguna más, en este orden: total, unassigned, byStatus, byCarrier, byClient, byShippingLine, byLocation, byMonth.

    LOS VIAJES BORRADOS QUEDAN SIEMPRE FUERA de los ocho bloques (SoftDeletes). unassigned es la bolsa libre de SPEC 24: los pending con pilotId y vehicleId en null.

    ATENCIÓN — byStatus LLEVA SIEMPRE SUS TRES CLAVES, a 0 si no hay filas; los otros cinco desgloses SOLO traen filas con al menos un viaje y salen como [] con la base vacía. byCarrier, byClient, byShippingLine y byLocation van ordenados por total descendente e id ascendente; byMonth por month ascendente.

    ATENCIÓN — byCarrier NO SUMA total. Los viajes sin asignar no tienen empresa y no aparecen en ese desglose. Con ?carrierId= todos los bloques se acotan a esa empresa y unassigned es siempre 0.
    TEXT,
    properties: [
        new OA\Property(property: 'total', description: 'Viajes no borrados dentro del filtro. Es la suma de las tres claves de byStatus.', type: 'integer', example: 120),
        new OA\Property(property: 'unassigned', description: 'Viajes pending con pilotId y vehicleId en null: la bolsa que ninguna empresa ha tomado. Con ?carrierId= es siempre 0.', type: 'integer', example: 7),
        new OA\Property(property: 'byStatus', ref: '#/components/schemas/TripsSummaryByStatus'),
        new OA\Property(property: 'byCarrier', type: 'array', items: new OA\Items(ref: '#/components/schemas/TripsSummaryByCarrier')),
        new OA\Property(property: 'byClient', type: 'array', items: new OA\Items(ref: '#/components/schemas/TripsSummaryByClient')),
        new OA\Property(property: 'byShippingLine', type: 'array', items: new OA\Items(ref: '#/components/schemas/TripsSummaryByShippingLine')),
        new OA\Property(property: 'byLocation', type: 'array', items: new OA\Items(ref: '#/components/schemas/TripsSummaryByLocation')),
        new OA\Property(property: 'byMonth', type: 'array', items: new OA\Items(ref: '#/components/schemas/TripsSummaryByMonth')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripsSummaryResponse',
    title: 'Sobre del resumen de viajes',
    description: 'Sobre habitual del proyecto con el resumen en data. Nunca pagina y nunca devuelve 404: con la base vacía data trae los ocho bloques a cero o vacíos.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Resumen de viajes obtenido correctamente'),
        new OA\Property(property: 'data', ref: '#/components/schemas/TripsSummary'),
    ],
    type: 'object',
)]
class TripsSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The resource wraps the array shaped by `DashboardService::getTripsSummary()`,
     * not a model: the keys are listed here so the output order is a contract.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'total' => $this->resource['total'],
            'unassigned' => $this->resource['unassigned'],
            'byStatus' => $this->resource['byStatus'],
            'byCarrier' => $this->resource['byCarrier'],
            'byClient' => $this->resource['byClient'],
            'byShippingLine' => $this->resource['byShippingLine'],
            'byLocation' => $this->resource['byLocation'],
            'byMonth' => $this->resource['byMonth'],
        ];
    }
}
