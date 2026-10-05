<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\TripEmergencyExpense\StoreTripEmergencyExpenseRequest;
use App\Http\Requests\TripEmergencyExpense\UpdateTripEmergencyExpenseRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\TripEmergencyExpense\TripEmergencyExpenseResource;
use App\Interfaces\TripEmergencyExpense\TripEmergencyExpenseServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Trip Emergency Expenses',
    description: <<<'TEXT'
    Gastos emergentes del viaje: los imprevistos que surgen con el viaje EN RUTA —una llanta pinchada, una grúa—, registrados por la empresa transportista cuando el piloto le avisa por fuera del sistema, con un comprobante opcional. CUATRO ENDPOINTS, todos con token JWT (Authorization: Bearer {token}); sin él, 401 «El token de sesión no es válido o ha expirado».

    LAS CUATRO RUTAS: POST /api/trips/{trip}/emergency-expenses (role:carrier,administrator) registra; GET /api/trips/{trip}/emergency-expenses (todos los roles salvo shipment) lista los del viaje con el ámbito de SPEC 24; PATCH /api/trip-emergency-expenses/{tripEmergencyExpense} (role:carrier,administrator) corrige; DELETE /api/trip-emergency-expenses/{tripEmergencyExpense} (role:carrier,administrator) borra de verdad. Ninguna lleva carrier.required: el ámbito lo resuelve el service.

    ATENCIÓN — NO ES UN VIÁTICO. El viático (/expenses) es dinero que se ENTREGA y el piloto CONFIRMA; el gasto emergente es dinero que YA SE GASTÓ, sin confirmación: suma desde que se registra. Los dos totales y los dos bloques del costo van SEPARADOS.

    ATENCIÓN — SOLO SE REGISTRA CON EL VIAJE in_route, pero SE CORRIGE Y SE BORRA TAMBIÉN CON EL VIAJE finished, porque la factura suele llegar después de terminar. No es append-only.

    ATENCIÓN — EL COMPROBANTE SE GUARDA TAL CUAL (jpg, png o pdf, máximo 3 MB), sin recorte. Un DELETE, un reemplazo o removeReceipt borran el archivo anterior del almacenamiento de forma irreversible.

    NO EXISTE UN LISTADO GLOBAL (GET /api/trip-emergency-expenses) ni un detalle por id: el índice va siempre viaje → gastos.
    TEXT,
)]
class TripEmergencyExpenseController extends Controller
{
    #[OA\Get(
        path: '/api/trips/{trip}/emergency-expenses',
        operationId: 'listTripEmergencyExpenses',
        summary: 'Listar los gastos emergentes de un viaje',
        description: <<<'TEXT'
        Lista los gastos emergentes del viaje en orden FIJO id ASCENDENTE, sin filtros, con totalAmount (suma de TODOS) en la raíz del sobre, con y sin paginación.

        ATENCIÓN — ÁMBITO DE SPEC 24: administrator, manager, export y user ven cualquier viaje; carrier los que alcanza su empresa (fuera, 403); el PILOTO ASIGNADO SÍ LEE los de su viaje (otro piloto, 403). shipment recibe 403 del middleware: no ve dinero.

        Un viaje SIN GASTOS devuelve 200 con data vacío y totalAmount "0.00". Un viaje inexistente o BORRADO es 404 «El viaje no existe».
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trip Emergency Expenses'],
        parameters: [
            new OA\Parameter(name: 'trip', description: 'Id numérico del viaje.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia ACTIVA la paginación; sin él (o no numérico) se devuelven todos. Acotado a [10, 100]. No cambia totalAmount.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 10, example: 10),
            ),
            new OA\Parameter(name: 'page', description: 'Página solicitada, solo con limit.', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Gastos emergentes obtenidos correctamente. Sin limit, TripEmergencyExpenseListResponse; con limit numérico, PaginatedTripEmergencyExpenseListResponse. Las dos llevan totalAmount en la raíz.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/TripEmergencyExpenseListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedTripEmergencyExpenseListResponse'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'El token de sesión no es válido o ha expirado', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(
                response: 403,
                description: 'shipment recibe del middleware «No tienes permisos para acceder a este recurso». Un viaje fuera del ámbito de quien llama (un carrier de otra empresa, un piloto que no es el asignado) devuelve el 403 del dominio de viajes.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(response: 404, description: 'El viaje no existe (también si fue borrado).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function index(Request $request, int $trip, TripEmergencyExpenseServiceInterface $tripEmergencyExpenseService)
    {
        try {
            $result = $tripEmergencyExpenseService->getTripEmergencyExpenses(
                auth('api')->user(),
                $trip,
                ['limit' => $this->queryString($request, 'limit')],
            );

            $expenses = $result['emergencyExpenses'];

            $data = $expenses instanceof LengthAwarePaginator
                ? (new PaginatedResource($expenses, TripEmergencyExpenseResource::class))->resolve()
                : ['data' => TripEmergencyExpenseResource::collection($expenses)->resolve()];

            /** El acumulado viaja en la raíz del sobre, con y sin paginación: es dato de negocio, no del paginador. */
            $data['totalAmount'] = $result['totalAmount'];

            return ResponseHandler::success($data, 'Gastos emergentes obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/trips/{trip}/emergency-expenses',
        operationId: 'storeTripEmergencyExpense',
        summary: 'Registrar un gasto emergente en un viaje en ruta',
        description: <<<'TEXT'
        La empresa que tomó el viaje (o el administrador) registra un gasto imprevisto. Con comprobante, el cuerpo va en multipart/form-data; sin él, sirve JSON.

        CUATRO GUARDAS EN ORDEN FIJO, después de la validación del cuerpo: viaje inexistente → 404 «El viaje no existe»; borrado → 400 «El viaje ya fue eliminado»; el viaje no lo tomó la empresa de quien llama, INCLUIDO UN VIAJE SIN ASIGNAR → 403 «No puedes registrar gastos emergentes en un viaje que no tomó tu empresa transportista» (el administrador recibe en su lugar 400 «El viaje aún no fue asignado»); el viaje no está in_route → 400 «Solo se pueden registrar gastos emergentes en un viaje en ruta». Un carrier sin empresa recibe 403 «No perteneces a ninguna empresa transportista».

        manager, pilot, export, user y shipment reciben 403 del middleware.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/StoreTripEmergencyExpenseRequest')),
                new OA\JsonContent(ref: '#/components/schemas/StoreTripEmergencyExpenseRequest'),
            ],
        ),
        security: [['bearerAuth' => []]],
        tags: ['Trip Emergency Expenses'],
        parameters: [
            new OA\Parameter(name: 'trip', description: 'Id numérico del viaje.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Gasto emergente registrado correctamente.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Gasto emergente registrado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/TripEmergencyExpense'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 400, description: 'El viaje ya fue eliminado / El viaje aún no fue asignado (administrador) / Solo se pueden registrar gastos emergentes en un viaje en ruta', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'El token de sesión no es válido o ha expirado', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'Rol no permitido (middleware), carrier sin empresa o viaje que no tomó su empresa.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'El viaje no existe', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'Monto ausente, no numérico, menor o igual a 0 o mayor que 99999999.99; descripción ausente, en blanco o de más de 255 caracteres; comprobante de otro tipo o de más de 3 MB.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreTripEmergencyExpenseRequest $request, int $trip, TripEmergencyExpenseServiceInterface $tripEmergencyExpenseService)
    {
        try {
            $expense = $tripEmergencyExpenseService->create(auth('api')->user(), $trip, $request->validated());

            return ResponseHandler::success(
                new TripEmergencyExpenseResource($expense),
                'Gasto emergente registrado correctamente',
                201,
            );
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/trip-emergency-expenses/{tripEmergencyExpense}',
        operationId: 'updateTripEmergencyExpense',
        summary: 'Corregir un gasto emergente',
        description: <<<'TEXT'
        Corrige monto, descripción o comprobante. Todos los campos son opcionales y un cuerpo vacío responde 200 sin escribir nada.

        ATENCIÓN — CON ARCHIVO, ENVIAR POST CON _method=PATCH EN multipart/form-data: PHP no parsea archivos en un PATCH real.

        CUATRO GUARDAS EN ORDEN FIJO: gasto inexistente → 404 «El gasto emergente no existe»; viaje borrado → 400 «El viaje ya fue eliminado»; viaje de otra empresa → 403 «No puedes registrar gastos emergentes en un viaje que no tomó tu empresa transportista»; viaje pending → 400 «No se pueden modificar los gastos emergentes de un viaje pendiente» (solo alcanzable si el administrador devolvió el viaje a pending). Con el viaje in_route o finished se permite.

        receipt reemplaza el comprobante y removeReceipt=true lo quita; el archivo anterior se borra después de guardar. Los dos a la vez son 422. tripId, trip_id y registeredBy se ignoran en silencio.
        TEXT,
        requestBody: new OA\RequestBody(
            required: false,
            content: [
                new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/UpdateTripEmergencyExpenseRequest')),
                new OA\JsonContent(ref: '#/components/schemas/UpdateTripEmergencyExpenseRequest'),
            ],
        ),
        security: [['bearerAuth' => []]],
        tags: ['Trip Emergency Expenses'],
        parameters: [
            new OA\Parameter(name: 'tripEmergencyExpense', description: 'Id numérico DEL GASTO EMERGENTE, no del viaje. Se obtiene del listado del viaje.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 3)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Gasto emergente actualizado correctamente.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Gasto emergente actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/TripEmergencyExpense'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 400, description: 'El viaje ya fue eliminado / No se pueden modificar los gastos emergentes de un viaje pendiente', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'El token de sesión no es válido o ha expirado', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'Rol no permitido (middleware), carrier sin empresa o gasto de un viaje que no tomó su empresa.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'El gasto emergente no existe', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'Campo enviado vacío o inválido, comprobante de otro tipo o de más de 3 MB, o receipt junto con removeReceipt=true.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function update(UpdateTripEmergencyExpenseRequest $request, int $tripEmergencyExpense, TripEmergencyExpenseServiceInterface $tripEmergencyExpenseService)
    {
        try {
            $expense = $tripEmergencyExpenseService->update(auth('api')->user(), $tripEmergencyExpense, $request->validated());

            return ResponseHandler::success(
                new TripEmergencyExpenseResource($expense),
                'Gasto emergente actualizado correctamente',
                200,
            );
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/trip-emergency-expenses/{tripEmergencyExpense}',
        operationId: 'deleteTripEmergencyExpense',
        summary: 'Borrar un gasto emergente',
        description: <<<'TEXT'
        BORRADO FÍSICO: la fila desaparece y su comprobante se borra del almacenamiento, de forma irreversible. Responde 200 con el gasto borrado (nueve claves). Un segundo DELETE del mismo id es 404.

        Mismas cuatro guardas, en el mismo orden, que el PATCH: 404 «El gasto emergente no existe» → 400 «El viaje ya fue eliminado» → 403 empresa ajena → 400 «No se pueden modificar los gastos emergentes de un viaje pendiente». Con el viaje in_route o finished se permite.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trip Emergency Expenses'],
        parameters: [
            new OA\Parameter(name: 'tripEmergencyExpense', description: 'Id numérico DEL GASTO EMERGENTE.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 3)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Gasto emergente eliminado correctamente.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Gasto emergente eliminado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/TripEmergencyExpense'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 400, description: 'El viaje ya fue eliminado / No se pueden modificar los gastos emergentes de un viaje pendiente', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'El token de sesión no es válido o ha expirado', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'Rol no permitido (middleware), carrier sin empresa o gasto de un viaje que no tomó su empresa.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'El gasto emergente no existe', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function destroy(int $tripEmergencyExpense, TripEmergencyExpenseServiceInterface $tripEmergencyExpenseService)
    {
        try {
            $expense = $tripEmergencyExpenseService->delete(auth('api')->user(), $tripEmergencyExpense);

            return ResponseHandler::success(
                new TripEmergencyExpenseResource($expense),
                'Gasto emergente eliminado correctamente',
                200,
            );
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
