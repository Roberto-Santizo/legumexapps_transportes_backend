<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Resources\Dashboard\DashboardVehicleResource;
use App\Http\Resources\Dashboard\TripInRouteResource;
use App\Http\Resources\Dashboard\TripsSummaryResource;
use App\Http\Resources\Dashboard\VehicleExpensesSummaryResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\Dashboard\DashboardServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Dashboard',
    description: <<<'TEXT'
    Tablero de administración: CUATRO endpoints de SOLO LECTURA bajo /api/dashboard que resumen viajes, gastos de vehículos, flota y viajes en curso a partir de lo que los demás dominios ya guardan. No crea tabla, columna ni migración: cada llamada consulta la base en vivo, sin caché.

    PERMISOS — LOS CUATRO ENDPOINTS ADMITEN administrator, manager Y carrier (middleware role:administrator,manager,carrier + carrier.required). pilot recibe 403 «No tienes permisos para acceder a este recurso» en los cuatro; un carrier SIN EMPRESA vinculada recibe 403 «Debes estar vinculado a un transportista para acceder a este recurso». administrator y manager ven EXACTAMENTE LO MISMO, todas las empresas, y para ellos carrierId es un filtro voluntario. EL carrier VE SOLO SU EMPRESA: el ámbito lo fija el servidor desde su vínculo en la base (no desde el token) y CUALQUIER carrierId QUE MANDE SE IGNORA EN SILENCIO — nunca puede leer los números de otra empresa.

    FILTROS — TODOS TOLERANTES Y SIN FormRequest: nunca hay 422. carrierId (solo administrator/manager) debe ser numérico Y existir, si no se ignora; dateFrom/dateTo aceptan solo Y-m-d estricto (01/09/2026 o 2026-9-1 se ignoran) y cortan por día completo; sin fechas es TODO EL HISTÓRICO, no el mes en curso. ATENCIÓN — /trips/in-route y /vehicles NO TIENEN FECHA DE NEGOCIO e ignoran dateFrom/dateTo en silencio.

    RANGO DE FECHAS: /trips corta sobre recolection_date; /vehicle-expenses sobre expense_date. En viajes, «empresa» es la de assigned_by (el dueño que tomó el viaje); en gastos y flota, vehicles.carrier_id.

    LAS TRES TRAMPAS: 1) en /trips, byCarrier NO SUMA total, porque los viajes sin asignar no tienen empresa; 2) en /trips/in-route, stoppedMinutes se mide contra now() y CAMBIA EN CADA LECTURA; 3) byMonth solo trae los meses con datos: el frontend rellena los huecos.

    Sin websocket propio (/trips/in-route se refresca por polling), sin export, sin comparativas entre periodos y sin top N: los desgloses salen completos.
    TEXT,
)]
class DashboardController extends Controller
{
    /**
     * Aggregate the trips for the dashboard.
     */
    #[OA\Get(
        path: '/api/dashboard/trips',
        operationId: 'dashboardTripsSummary',
        summary: 'Resumen de viajes',
        description: <<<'TEXT'
        Devuelve los agregados de viajes en un solo objeto de OCHO bloques: total, unassigned, byStatus, byCarrier, byClient, byShippingLine, byLocation y byMonth. Los viajes borrados quedan SIEMPRE fuera.

        ATENCIÓN — byCarrier NO SUMA total: agrupa por la empresa de assigned_by y los viajes sin asignar (la bolsa de SPEC 24) no tienen empresa. Suman en total y en unassigned, pero no aparecen en ese desglose. Con ?carrierId= (administrator/manager) o para un carrier (siempre su empresa) todos los bloques se acotan a esa empresa y unassigned es siempre 0.

        byStatus lleva SIEMPRE sus tres claves (pending, inRoute, finished) a 0 si no hay filas; los otros cinco desgloses solo traen filas con datos —[] con la base vacía— y van ordenados por total descendente e id ascendente, salvo byMonth, que va por month ascendente y NO rellena los meses sin viajes.

        El rango corta sobre recolection_date por día completo: dateTo=2026-09-30 incluye un viaje de ese día a las 23:59. Sin fechas, todo el histórico. Nunca 404: con la base vacía responde 200 con los bloques a cero.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(
                name: 'carrierId',
                description: 'Id de la empresa transportista (carriers.id) — SOLO administrator y manager; un carrier lo ve ignorado, siempre acotado a su empresa — a la que acotar los ocho bloques, por el assigned_by de sus viajes. TOLERANTE: un valor no numérico o un id inexistente se ignora y se devuelve el histórico completo, nunca 422 ni un resumen vacío.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 3),
            ),
            new OA\Parameter(
                name: 'dateFrom',
                description: 'Primer día del rango sobre recolection_date, inclusive, en Y-m-d ESTRICTO. Cualquier otro formato (01/09/2026, 2026-9-1) se ignora en silencio.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-01'),
            ),
            new OA\Parameter(
                name: 'dateTo',
                description: 'Último día del rango sobre recolection_date, inclusive y por día completo, en Y-m-d ESTRICTO. Un formato inválido se ignora en silencio.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-30'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resumen de viajes obtenido correctamente. data trae siempre los ocho bloques, aunque la base esté vacía.',
                content: new OA\JsonContent(ref: '#/components/schemas/TripsSummaryResponse'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El usuario es pilot (mensaje: No tienes permisos para acceder a este recurso) o es un carrier sin empresa vinculada (mensaje: Debes estar vinculado a un transportista para acceder a este recurso).',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function trips(Request $request, DashboardServiceInterface $dashboardService)
    {
        try {
            $summary = $dashboardService->getTripsSummary(auth('api')->user(), [
                'carrierId' => $this->queryString($request, 'carrierId'),
                'dateFrom' => $this->queryString($request, 'dateFrom'),
                'dateTo' => $this->queryString($request, 'dateTo'),
            ]);

            return ResponseHandler::success(new TripsSummaryResource($summary), 'Resumen de viajes obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * List every trip in route with its live snapshot.
     */
    #[OA\Get(
        path: '/api/dashboard/trips/in-route',
        operationId: 'dashboardTripsInRoute',
        summary: 'Viajes en curso',
        description: <<<'TEXT'
        Devuelve TODOS los viajes con status in_route —SIN PAGINAR, en orden start_date descendente— con lo necesario para pintarlos en un mapa en vivo: identificación, empresa, piloto, vehículo, cliente, puerto, startDate, la ÚLTIMA POSICIÓN registrada, las dos sumas de combustible y la PARADA ABIERTA, si la hay.

        Manda el status, no start_date: un viaje pending con start_date puesto NO sale. lastPosition es null si el viaje aún no tiene puntos; openTimeout es null si no hay parada con ended_at IS NULL.

        ATENCIÓN — stoppedMinutes SE MIDE CONTRA now() DEL SERVIDOR y cambia en cada lectura: es una foto del momento, al revés que durationMinutes de GET /api/trips/{trip}/timeouts. Este endpoint se refresca por POLLING del frontend; no hay websocket del tablero (el canal trips.{tripId} de SPEC 26 sigue siendo por viaje).

        Solo acepta carrierId (empresa de assigned_by). dateFrom y dateTo SE IGNORAN EN SILENCIO: un viaje en curso no tiene fecha de negocio que cortar. Sin viajes en curso responde 200 con data []. Las consultas ejecutadas no dependen del número de viajes.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(
                name: 'carrierId',
                description: 'Id de la empresa transportista (carriers.id) — SOLO administrator y manager; un carrier lo ve ignorado, siempre acotado a su empresa — a la que acotar los viajes en curso, por el assigned_by de cada viaje. TOLERANTE: un valor no numérico o inexistente se ignora y se devuelven todos.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 3),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Viajes en curso obtenidos correctamente. data es la lista completa, sin paginar; [] si no hay ninguno.',
                content: new OA\JsonContent(ref: '#/components/schemas/TripInRouteListResponse'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El usuario es pilot (mensaje: No tienes permisos para acceder a este recurso) o es un carrier sin empresa vinculada (mensaje: Debes estar vinculado a un transportista para acceder a este recurso).',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function tripsInRoute(Request $request, DashboardServiceInterface $dashboardService)
    {
        try {
            $trips = $dashboardService->getTripsInRoute(auth('api')->user(), [
                'carrierId' => $this->queryString($request, 'carrierId'),
            ]);

            return ResponseHandler::success(TripInRouteResource::collection($trips), 'Viajes en curso obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Aggregate the vehicle expenses for the dashboard.
     */
    #[OA\Get(
        path: '/api/dashboard/vehicle-expenses',
        operationId: 'dashboardVehicleExpensesSummary',
        summary: 'Resumen de gastos de vehículos',
        description: <<<'TEXT'
        Devuelve los agregados de vehicle_expenses en un solo objeto de OCHO bloques: totalAmount, count, byCategory, byNature, invoiced, notInvoiced, byCarrier y byMonth. Todo el dinero sale como CADENA de dos decimales en GTQ.

        byNature, invoiced y notInvoiced llevan SIEMPRE sus claves, a 0 y "0.00" si no hay filas; byCategory, byCarrier y byMonth solo traen filas con datos —[] con la base vacía—, ordenadas por totalAmount descendente salvo byMonth, que va por month ascendente y no rellena meses vacíos. Se cumple siempre invoiced.count + notInvoiced.count === count.

        La empresa de un gasto es la de SU VEHÍCULO (vehicles.carrier_id), y el status del vehículo NO importa: los gastos de un vehículo inactive cuentan igual. El rango corta sobre expense_date por día completo; sin fechas, todo el histórico. Nunca 404.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(
                name: 'carrierId',
                description: 'Id de la empresa transportista (carriers.id) — SOLO administrator y manager; un carrier lo ve ignorado, siempre acotado a su empresa — a la que acotar los ocho bloques, por vehicles.carrier_id. TOLERANTE: un valor no numérico o inexistente se ignora y se devuelve el histórico completo.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 3),
            ),
            new OA\Parameter(
                name: 'dateFrom',
                description: 'Primer día del rango sobre expense_date, inclusive, en Y-m-d ESTRICTO. Un formato inválido se ignora en silencio.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-01'),
            ),
            new OA\Parameter(
                name: 'dateTo',
                description: 'Último día del rango sobre expense_date, inclusive, en Y-m-d ESTRICTO. Un formato inválido se ignora en silencio.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-30'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resumen de gastos de vehículos obtenido correctamente. data trae siempre los ocho bloques, aunque la base esté vacía.',
                content: new OA\JsonContent(ref: '#/components/schemas/VehicleExpensesSummaryResponse'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El usuario es pilot (mensaje: No tienes permisos para acceder a este recurso) o es un carrier sin empresa vinculada (mensaje: Debes estar vinculado a un transportista para acceder a este recurso).',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function vehicleExpenses(Request $request, DashboardServiceInterface $dashboardService)
    {
        try {
            $summary = $dashboardService->getVehicleExpensesSummary(auth('api')->user(), [
                'carrierId' => $this->queryString($request, 'carrierId'),
                'dateFrom' => $this->queryString($request, 'dateFrom'),
                'dateTo' => $this->queryString($request, 'dateTo'),
            ]);

            return ResponseHandler::success(new VehicleExpensesSummaryResource($summary), 'Resumen de gastos de vehículos obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * List the whole fleet with its current trip.
     */
    #[OA\Get(
        path: '/api/dashboard/vehicles',
        operationId: 'dashboardVehicles',
        summary: 'Flota con su viaje en curso',
        description: <<<'TEXT'
        Lista TODA la flota —de todas las empresas para administrator/manager, solo la propia para carrier, e INCLUIDOS los vehículos inactive y under_repair— en orden id ascendente, con su ficha operativa y, por cada uno, si está en ruta y cuál es su viaje en curso (currentTrip, null si no tiene ninguno; con dos in_route, el de start_date más reciente).

        ATENCIÓN — NO TRAE purchasePrice NI monthlyInsuranceCost: quedaron fuera a propósito. Para la ficha completa está GET /api/vehicles/{vehicle}.

        Filtros tolerantes: carrierId (vehicles.carrier_id), status (active|inactive|under_repair), condition (new|used) e inRoute (true|false). Un valor inválido se ignora, nunca 422. inRoute se aplica ANTES de paginar, así que total refleja el recorte. dateFrom y dateTo SE IGNORAN: la flota no tiene fecha de negocio.

        Paginación opt-in con limit, acotado a [10, 100]; sin limit se devuelve toda la flota. Sin vehículos, 200 con data [].
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(
                name: 'carrierId',
                description: 'Id de la empresa transportista (carriers.id) — SOLO administrator y manager; un carrier lo ve ignorado, siempre acotado a su empresa — dueña de los vehículos. TOLERANTE: un valor no numérico o inexistente se ignora.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 3),
            ),
            new OA\Parameter(
                name: 'status',
                description: 'Estado operativo del vehículo, con el valor crudo de VehicleStatus. Un valor fuera del enum (STATUS=ACTIVE incluido) se ignora.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['active', 'inactive', 'under_repair'], example: 'active'),
            ),
            new OA\Parameter(
                name: 'condition',
                description: 'Condición de adquisición, con el valor crudo de VehicleCondition. Un valor fuera del enum se ignora.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['new', 'used'], example: 'used'),
            ),
            new OA\Parameter(
                name: 'inRoute',
                description: 'true devuelve solo los vehículos con algún viaje in_route; false, los demás. Se interpreta con FILTER_VALIDATE_BOOLEAN (acepta 1/0, yes/no, on/off) y cualquier otro valor (inRoute=basura) se ignora. Se aplica ANTES de paginar.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'boolean', example: true),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia ACTIVA la paginación: sin él, o si no es numérico, se devuelve toda la flota sin metadatos. Si es numérico se ACOTA a [10, 100]: limit=5 devuelve páginas de 10 y limit=500, de 100.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 10, example: 10),
            ),
            new OA\Parameter(
                name: 'page',
                description: 'Página solicitada. Solo tiene efecto con un limit numérico.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Flota obtenida correctamente. Sin limit, DashboardVehicleListResponse con toda la flota; con limit numérico, PaginatedDashboardVehicleListResponse con total, currentPage y lastPage aplanados en la raíz.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/DashboardVehicleListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedDashboardVehicleListResponse'),
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El usuario es pilot (mensaje: No tienes permisos para acceder a este recurso) o es un carrier sin empresa vinculada (mensaje: Debes estar vinculado a un transportista para acceder a este recurso).',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function vehicles(Request $request, DashboardServiceInterface $dashboardService)
    {
        try {
            $vehicles = $dashboardService->getVehicles(auth('api')->user(), [
                'carrierId' => $this->queryString($request, 'carrierId'),
                'status' => $this->queryString($request, 'status'),
                'condition' => $this->queryString($request, 'condition'),
                'inRoute' => $this->queryString($request, 'inRoute'),
                'limit' => $this->queryString($request, 'limit'),
            ]);

            $data = $vehicles instanceof LengthAwarePaginator
                ? new PaginatedResource($vehicles, DashboardVehicleResource::class)
                : DashboardVehicleResource::collection($vehicles);

            return ResponseHandler::success($data, 'Flota obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Read a query parameter as a string, ignoring anything that is not one.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
