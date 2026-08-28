<?php

namespace App\Http\Resources\Trip;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * El viaje como lo pinta el listado: 15 claves, la vista de tabla del frontend.
 *
 * Es la versión recortada de {@see TripResource}, que sigue siendo la de los otros siete
 * endpoints. Aquí no salen los ids de las relaciones —solo sus nombres—, ni `polyline` ni
 * su `points` derivado, ni `destination`, `transport`, `clientName`, el par de `assignedBy`
 * ni las tres marcas de tiempo de la fila. Quien necesite todo eso pide el detalle.
 *
 * Que `points` no salga aquí es lo que más se nota: el listado dejó de decodificar una
 * polilínea por elemento, así que un `limit=100` ya no decodifica cien.
 *
 * Las fechas conservan el formato propio del dominio `d-m-Y h:i:s A`, no ISO 8601.
 */
#[OA\Schema(
    schema: 'TripListItem',
    title: 'Viaje en el listado',
    description: <<<'TEXT'
    El viaje tal como sale en GET /api/trips: 15 CLAVES en camelCase, no las 31 del detalle. Es una vista de tabla, no el recurso completo.

    ATENCIÓN — NO ES EL MISMO ESQUEMA QUE Trip. Aquí NO vienen: clientId ni clientName; los ids de las relaciones (shippingLineId, departurePointId, locationId, pilotId, vehicleId); assignedById ni assignedByName; destination ni transport; polyline ni points; createdAt, updatedAt ni deletedAt. De las relaciones solo sale el NOMBRE PLANO. Para cualquiera de esos campos —y para la ruta dibujable— hay que pedir GET /api/trips/{trip}.

    ATENCIÓN — points NO SE CALCULA EN EL LISTADO. La polilínea solo se decodifica en el detalle, así que el mapa se pinta desde ahí y nunca desde una fila de la tabla.

    Las cinco fechas siguen en el formato propio d-m-Y h:i:s A, NO ISO 8601, y status sale con el valor crudo del enum en inglés. Lo demás del contrato del listado —ámbito por rol, ocho filtros tolerantes, orden fijo recolection_date DESC e id DESC, paginación opt-in— no cambia.

    Las 15 claves salen siempre en este orden: id, order, status, shippingLineName, departurePointName, locationName, container, recolectionDate, shipDate, startDate, endDate, observations, pilotName, vehiclePlate y registeredByName.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico del viaje (trips.id). Es el valor que va en el parámetro {trip} del detalle y de las cinco rutas de escritura: desde una fila del listado se salta al detalle con este id.',
            type: 'integer',
            example: 1,
        ),
        new OA\Property(
            property: 'order',
            description: 'Referencia comercial del viaje, SIEMPRE EN MAYÚSCULAS y con los espacios interiores colapsados a uno. NO ES ÚNICA: dos viajes pueden compartirla. Es uno de los dos campos que barre el filtro search.',
            type: 'string',
            maxLength: 255,
            example: 'ORD-2026-0148',
        ),
        new OA\Property(
            property: 'status',
            description: 'Estado del viaje, con el VALOR CRUDO DEL ENUM EN INGLÉS y sin traducir. NO HAY MÁQUINA DE ESTADOS: puede contradecir a startDate y endDate, y cuando eso pase el relato lo cuentan las fechas.',
            type: 'string',
            enum: ['pending', 'in_route', 'finished'],
            example: 'pending',
        ),
        new OA\Property(
            property: 'shippingLineName',
            description: 'Nombre de la naviera, resuelto desde la relación y siempre en mayúsculas. Sale plano y SIN su id: para filtrar por naviera hay que traer el id desde GET /api/shipping-lines.',
            type: 'string',
            nullable: true,
            example: 'MAERSK LINE',
        ),
        new OA\Property(
            property: 'departurePointName',
            description: 'Nombre del punto de partida, resuelto desde la relación y siempre en mayúsculas. Sale plano y sin su id.',
            type: 'string',
            nullable: true,
            example: 'PLANTA SAN JUAN',
        ),
        new OA\Property(
            property: 'locationName',
            description: 'Nombre del puerto de destino, resuelto desde la relación y siempre en mayúsculas. Sale plano y sin su id, sin coordenadas y sin googlePlaceId. ATENCIÓN — no confundirlo con destination, el destino final en el extranjero, que NO sale en el listado.',
            type: 'string',
            nullable: true,
            example: 'PUERTO QUETZAL',
        ),
        new OA\Property(
            property: 'container',
            description: 'Identificación del contenedor, SIEMPRE EN MAYÚSCULAS y con los espacios interiores colapsados a uno. NO ES ÚNICO. Es el otro campo que barre el filtro search.',
            type: 'string',
            maxLength: 255,
            example: 'MSKU 483920 1',
        ),
        new OA\Property(
            property: 'recolectionDate',
            description: 'Fecha y hora PLANIFICADAS de recolección. Es la columna por la que ordena el listado (recolection_date DESC) y la que miran los filtros dateFrom y dateTo. Formato propio d-m-Y h:i:s A, NO ISO 8601.',
            type: 'string',
            example: '02-09-2026 06:00:00 AM',
        ),
        new OA\Property(
            property: 'shipDate',
            description: 'Fecha y hora PLANIFICADAS de embarque, nunca anterior a recolectionDate. Es una fecha prevista, no una marca de ejecución: no la mueven ni /start ni /finish. Mismo formato propio d-m-Y h:i:s A.',
            type: 'string',
            example: '04-09-2026 11:30:00 PM',
        ),
        new OA\Property(
            property: 'startDate',
            description: 'Marca de EJECUCIÓN REAL del arranque, puesta por el now() del servidor en PATCH /api/trips/{trip}/start. null mientras el piloto no lo haya iniciado. Mismo formato propio d-m-Y h:i:s A.',
            type: 'string',
            nullable: true,
            example: '02-09-2026 06:12:44 AM',
        ),
        new OA\Property(
            property: 'endDate',
            description: 'Marca de EJECUCIÓN REAL del cierre, puesta por el now() del servidor en PATCH /api/trips/{trip}/finish. null mientras el viaje no se haya cerrado, y nunca existe sin startDate. Mismo formato propio d-m-Y h:i:s A.',
            type: 'string',
            nullable: true,
            example: '05-09-2026 02:30:10 PM',
        ),
        new OA\Property(
            property: 'observations',
            description: 'Instrucciones del viaje, tal como se teclearon —solo trim, conservando mayúsculas, minúsculas y saltos de línea—. Es obligatorio en el alta: cuando el viaje lo publica el administrador y lo ejecuta otra empresa, es el ÚNICO CANAL DE INSTRUCCIONES del dominio.',
            type: 'string',
            example: 'Carga refrigerada a -2 °C.',
        ),
        new OA\Property(
            property: 'pilotName',
            description: 'Nombre del piloto asignado, resuelto desde la relación. ES null MIENTRAS EL VIAJE SIGA EN LA BOLSA, y en el listado de un carrier la bolsa siempre aparece: hay que contar con el null. Sale sin su id.',
            type: 'string',
            nullable: true,
            example: 'Carlos Ramírez',
        ),
        new OA\Property(
            property: 'vehiclePlate',
            description: 'PLACA del vehículo asignado, no su nombre, en mayúsculas tal como la guarda Vehicles. null mientras el viaje siga en la bolsa. Sale sin su id.',
            type: 'string',
            nullable: true,
            example: 'P-1234ABC',
        ),
        new OA\Property(
            property: 'registeredByName',
            description: 'Nombre del administrador que dio de alta el viaje. De este autor SOLO SALE EL NOMBRE, aquí y en el detalle: no hay registeredById. No lo reescribe ningún PATCH.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripListResponse',
    title: 'Listado de viajes sin paginar',
    description: 'Respuesta de GET /api/trips cuando no se envía limit o cuando el limit no es numérico: se devuelven TODOS los viajes que el ámbito del usuario deja ver y que pasen los ocho filtros, y el sobre NO incluye total, currentPage ni lastPage. ATENCIÓN — cada elemento es un TripListItem de 15 claves, NO el Trip de 31 del detalle. Los viajes borrados NUNCA aparecen y no hay ningún parámetro que los muestre. El orden es siempre recolection_date DESC y, a igualdad, id DESC. ATENCIÓN — dos usuarios de roles distintos reciben listados DISTINTOS sobre los mismos datos: el ámbito se aplica antes que los filtros.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Viajes obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TripListItem')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedTripListResponse',
    title: 'Listado de viajes paginado',
    description: 'Respuesta de GET /api/trips cuando se envía un limit numérico: los metadatos de paginación salen APLANADOS en la raíz del sobre, junto a statusCode, message y data, no anidados bajo meta. El total cuenta solo los viajes que el ámbito del usuario deja ver, y nunca los borrados. Cada elemento es un TripListItem de 15 claves.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/TripListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class TripListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Las relaciones salen como nombre plano y sin su id: el listado es una tabla, y el
     * único id que necesita el frontend para saltar al detalle es el del propio viaje.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order' => $this->order,
            /** El valor crudo del enum, en inglés: traducirlo es cosa del frontend. */
            'status' => $this->status?->value,
            'shippingLineName' => $this->shippingLine?->name,
            'departurePointName' => $this->departurePoint?->name,
            'locationName' => $this->location?->name,
            'container' => $this->container,
            'recolectionDate' => $this->recolection_date?->format('d-m-Y h:i:s A'),
            'shipDate' => $this->ship_date?->format('d-m-Y h:i:s A'),
            'startDate' => $this->start_date?->format('d-m-Y h:i:s A'),
            'endDate' => $this->end_date?->format('d-m-Y h:i:s A'),
            'observations' => $this->observations,
            'pilotName' => $this->pilot?->name,
            'vehiclePlate' => $this->vehicle?->plate,
            'registeredByName' => $this->registeredBy?->name,
        ];
    }
}
