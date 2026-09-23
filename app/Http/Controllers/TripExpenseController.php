<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\TripExpense\StoreTripExpenseRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\TripExpense\TripExpenseResource;
use App\Interfaces\TripExpense\TripExpenseServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Trip Expenses',
    description: <<<'TEXT'
    Viáticos del viaje: la empresa transportista registra el dinero que entrega al piloto para los gastos del camino y el PILOTO ASIGNADO confirma haberlo recibido. Es el CALCO de las cargas de combustible de SPEC 27 con dinero en vez de galones. TRES ENDPOINTS Y NINGUNO MÁS, los tres con token JWT (Authorization: Bearer {token}); sin él la respuesta es 401 con «El token de sesión no es válido o ha expirado».

    LAS TRES RUTAS, con sus roles: POST /api/trips/{trip}/expenses (role:carrier) registra un viático SIN CONFIRMAR; GET /api/trips/{trip}/expenses (jwt.auth a secas) lista los del viaje con el ámbito de SPEC 24; y PATCH /api/trip-expenses/{tripExpense}/confirm (role:pilot) escribe la fecha de recepción. Ninguna de las tres lleva carrier.required: el ámbito lo resuelve el service.

    ATENCIÓN — LAS DOS PRIMERAS ESTÁN ANIDADAS BAJO {trip} y la tercera NO. La confirmación vive en /api/trip-expenses/{tripExpense}/confirm porque el id del viático ya identifica el viaje. NO EXISTE UN LISTADO GLOBAL: no hay GET /api/trip-expenses, ni filtro tripId en query, ni endpoint de detalle GET /api/trip-expenses/{tripExpense}. El índice va siempre viaje → viáticos.

    ATENCIÓN — manager NO ALCANZA LAS DOS RUTAS DE ESCRITURA Y administrator SOLO LA DE REGISTRAR. Registrar es del carrier que tomó el viaje (o del administrator) y confirmar es del pilot asignado; los otros roles reciben 403 «No tienes permisos para acceder a este recurso». Un administrador NO puede registrar ni confirmar nada: el PATCH general de un viaje no toca trip_expenses. En la LECTURA entran los cuatro roles, y AQUÍ EL PILOTO ASIGNADO SÍ LEE, como con las cargas de combustible: el dato es sobre él.

    CUATRO GUARDAS DEL POST EN ORDEN FIJO, y ese orden es contrato: viaje inexistente → 404 «El viaje no existe»; viaje borrado → 400 «El viaje ya fue eliminado»; el viaje NO lo tomó la empresa de quien llama, INCLUIDO EL CASO DE UN VIAJE SIN ASIGNAR → 403 «No puedes registrar viáticos en un viaje que no tomó tu empresa transportista»; el viaje está finished → 400 «El viaje ya fue finalizado». CONSECUENCIA: un viaje BORRADO Y AJENO devuelve el 400 del borrado, no el 403. Se registra en pending Y en in_route —un extra en carretera es el caso real—, nunca después. Un carrier sin empresa registrada recibe 403 «No perteneces a ninguna empresa transportista» desde el service, no desde un middleware.

    ATENCIÓN — ESTE DOMINIO CAMBIA UN ENDPOINT DE SPEC 24 SIN ROMPERLO: PATCH /api/trips/{trip}/assignment gana DOS CAMPOS OPCIONALES, expenseAmount y expenseDescription. Si viaja expenseAmount, la asignación crea el primer viático dentro de su propia transacción; si no viaja, la asignación queda exactamente como hasta ahora. expenseDescription sin expenseAmount SE IGNORA EN SILENCIO. Y TripResource pasa de 39 a 40 claves con totalExpensesAmount. NI /start NI /finish comprueban nada de viáticos: un viaje arranca con o sin ellos.

    NADA SE EDITA, NADA SE BORRA Y NADA SE DESCONFIRMA. La tabla es APPEND-ONLY, como trip_fuels: no hay PATCH de monto, no hay DELETE, received_at no vuelve nunca a null y el DELETE (baja lógica) del viaje no toca ninguna fila. Un monto mal tecleado es permanente y no se puede compensar, porque el monto no admite negativos.

    NO ES CONTABILIDAD: no hay comprobante, ni categoría, ni moneda (GTQ por convención), ni cantidad realmente recibida distinta de la entregada. NO SE EMITE NADA: registrar o confirmar no manda correo y no emite por Reverb. TripListResource sigue en 17 claves, GET /api/trips/current no cambia de forma, GET /api/trips no gana ningún filtro y el Dashboard de SPEC 29 no agrega viáticos.
    TEXT,
)]
class TripExpenseController extends Controller
{
    #[OA\Get(
        path: '/api/trips/{trip}/expenses',
        operationId: 'indexTripExpenses',
        summary: 'Listar los viáticos de un viaje',
        description: <<<'TEXT'
        Devuelve los viáticos del viaje —confirmados y pendientes, mezclados— más el acumulado de los CONFIRMADOS en la raíz del sobre.

        LA RUTA LLEVA role: CON TODOS LOS ROLES SALVO shipment —que no ve dinero y recibe 403—, acotados por el ámbito de SPEC 24, que este dominio no reescribe: administrator y manager alcanzan cualquier viaje; un carrier, los que asignó su empresa MÁS la bolsa libre (los pending sin tripulación); y un pilot, SOLO aquellos donde él es el pilotId. AQUÍ EL PILOTO ASIGNADO SÍ LEE, como en GET /api/trips/{trip}/fuels y al contrario que en /positions. Fuera de ámbito es 403 y no 404, con dos mensajes distintos según el rol.

        Un viaje SIN NINGÚN VIÁTICO devuelve 200 con data vacío y totalAmount "0.00", NUNCA 404. Un viaje BORRADO o inexistente es 404 «El viaje no existe», indistinguibles entre sí.

        ATENCIÓN — totalAmount NO ES total. totalAmount es la SUMA de los montos de los viáticos CONFIRMADOS, como cadena de dos decimales, calculada sobre la consulta clonada y ANTES de paginar; total es el CONTEO que aporta el paginador y solo aparece al paginar. SOLO CUENTAN LOS CONFIRMADOS: un viaje recién asignado con viático devuelve "0.00" teniendo ya una fila en data con isConfirmed en false. Es el mismo número que el totalExpensesAmount del detalle del viaje.

        NO HAY NI UN SOLO FILTRO. Cualquier query param que no sea limit o page se ignora. EL ORDEN ES FIJO id ASCENDENTE, sin sortBy ni order. EL limit SE ACOTA A [10, 100].
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trip Expenses'],
        parameters: [
            new OA\Parameter(
                name: 'trip',
                description: 'Id numérico del viaje cuyos viáticos se consultan (trips.id), EN LA URL: no hay query param tripId que lo sustituya ni listado global. Un id inexistente o de un viaje borrado devuelve 404 «El viaje no existe»; uno fuera del ámbito del usuario, 403.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que ACTIVA la paginación: si se omite, o si no es numérico, se devuelven TODOS los viáticos del viaje sin metadatos de paginación. Si es numérico se ACOTA a [10, 100]. Paginar NO cambia totalAmount, que se calcula antes de paginar.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 10, example: 10),
            ),
            new OA\Parameter(
                name: 'page',
                description: 'Página solicitada. Solo tiene efecto cuando se envía un limit numérico. Como el orden es id ascendente, page=1 son los viáticos más antiguos del viaje.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Viáticos obtenidos correctamente. Sin limit se devuelve TripExpenseListResponse con todos los viáticos del viaje; con limit numérico, PaginatedTripExpenseListResponse, con total, currentPage y lastPage APLANADOS EN LA RAÍZ del sobre. LAS DOS FORMAS INCLUYEN totalAmount en la raíz. Un viaje sin viáticos devuelve data vacío y totalAmount "0.00", nunca 404.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/TripExpenseListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedTripExpenseListResponse'),
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
                description: 'El viaje existe pero queda fuera del ámbito de quien pregunta, con DOS mensajes según el rol: un pilot que no es el asignado recibe «No puedes acceder a un viaje que no tienes asignado»; y un carrier que pide un viaje tomado por otra empresa recibe «No puedes acceder a un viaje que no pertenece a tu empresa transportista». La bolsa libre NO cae aquí: es 200 con data vacío, aunque el POST sobre ese mismo viaje sí sea 403.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El viaje no existe o fue borrado —indistinguibles a propósito—. El mensaje devuelto es: El viaje no existe. Las filas del viaje borrado siguen en la tabla, simplemente ya no hay forma de leerlas por API.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function index(Request $request, int $trip, TripExpenseServiceInterface $tripExpenseService)
    {
        try {
            $result = $tripExpenseService->getTripExpenses(
                auth('api')->user(),
                $trip,
                ['limit' => $this->queryString($request, 'limit')],
            );

            $expenses = $result['expenses'];

            $data = $expenses instanceof LengthAwarePaginator
                ? (new PaginatedResource($expenses, TripExpenseResource::class))->resolve()
                : ['data' => TripExpenseResource::collection($expenses)->resolve()];

            /** El acumulado viaja en la raíz del sobre junto a la metadata de paginación, y también sin ella: es dato de negocio, no del paginador. */
            $data['totalAmount'] = $result['totalAmount'];

            return ResponseHandler::success($data, 'Viáticos obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/trips/{trip}/expenses',
        operationId: 'storeTripExpense',
        summary: 'Registrar un viático en un viaje',
        description: <<<'TEXT'
        Registra UN viático sobre un viaje que la empresa transportista ya tomó. Es la segunda vía de alta del dominio: el primer viático puede crearlo PATCH /api/trips/{trip}/assignment si viaja expenseAmount, y este endpoint sirve para los siguientes —un extra en carretera es el caso real— o para el primero cuando la asignación no lo llevó.

        ATENCIÓN — ES DEL ROL carrier Y DEL administrator (middleware role:carrier,administrator): manager, pilot, export, user y shipment reciben 403. El administrator no pertenece a ninguna empresa: se salta la comprobación de empresa pero exige viaje asignado (400 «El viaje aún no fue asignado») «No tienes permisos para acceder a este recurso». La ruta NO lleva carrier.required: un carrier sin empresa registrada lo para el SERVICE con 403 «No perteneces a ninguna empresa transportista».

        ATENCIÓN — SOLO LA EMPRESA QUE TOMÓ EL VIAJE. La comparación cae sobre la EMPRESA de assignedBy, no sobre la persona. Y un viaje SIN ASIGNAR NO ES LIBRE AQUÍ: la bolsa se lee con 200 pero registrarle viáticos es 403, porque un viático sin piloto que lo confirme nacería atascado.

        CUATRO GUARDAS EN ORDEN FIJO, Y ESE ORDEN ES CONTRATO: 1) viaje inexistente → 404 «El viaje no existe»; 2) viaje borrado → 400 «El viaje ya fue eliminado»; 3) el viaje no lo tomó tu empresa, o no está asignado → 403 «No puedes registrar viáticos en un viaje que no tomó tu empresa transportista»; 4) el viaje está finished → 400 «El viaje ya fue finalizado». Un viaje BORRADO Y AJENO devuelve el 400 del borrado, no el 403.

        SE REGISTRA EN pending Y EN in_route, NUNCA DESPUÉS. No hace falta que el piloto haya confirmado el viático anterior para registrar otro.

        ATENCIÓN — EL VIÁTICO NACE SIN CONFIRMAR Y NO SUMA TODAVÍA. La respuesta trae isConfirmed en false, receivedAt en null y confirmedByName en null, y este monto NO entra ni en el totalAmount del listado ni en el totalExpensesAmount del viaje hasta que el piloto asignado pase por PATCH /api/trip-expenses/{tripExpense}/confirm.

        ATENCIÓN — SIN VALIDACIÓN CRUZADA Y SIN VUELTA ATRÁS. amount solo se valida numérico, mayor que 0 y hasta 99999999.99. La tabla es APPEND-ONLY —no hay PATCH ni DELETE—, así que un 3500 tecleado en vez de un 350 se queda para siempre. EL FRONTEND DEBE CONFIRMAR LA CANTIDAD ANTES DE MANDAR ESTA PETICIÓN.

        NO SE EMITE NADA: registrar un viático no manda correo y no emite por Reverb. El viaje no cambia: registrar no toca su status ni sus fechas.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreTripExpenseRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Trip Expenses'],
        parameters: [
            new OA\Parameter(
                name: 'trip',
                description: 'Id numérico del viaje sobre el que se registra el viático (trips.id), EN LA URL: no viaja en el cuerpo. Debe ser un viaje EXISTENTE, NO BORRADO, TOMADO POR LA EMPRESA DE QUIEN LLAMA y NO FINALIZADO; cada incumplimiento tiene su propio código en ese orden (404, 400, 403, 400).',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Viático registrado correctamente. La fila quedó escrita en trip_expenses y nace SIN CONFIRMAR: isConfirmed en false, receivedAt en null y confirmedByName en null. registeredByName es el usuario autenticado, nunca lo que venga en el cuerpo, y amount vuelve como STRING de dos decimales.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Viático registrado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/TripExpense'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio incumplida, con DOS mensajes posibles: «El viaje ya fue eliminado» —se comprueba ANTES que la empresa— y «El viaje ya fue finalizado», que es la ÚLTIMA de las cuatro guardas.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'Sin permiso para registrar. TRES CAUSAS con mensajes distintos: el middleware role:carrier,administrator rechaza a manager, pilot, export, user y shipment con «No tienes permisos para acceder a este recurso»; un carrier sin empresa recibe del service «No perteneces a ninguna empresa transportista»; y un carrier cuya empresa no tomó el viaje —INCLUIDO UN VIAJE SIN ASIGNAR— recibe «No puedes registrar viáticos en un viaje que no tomó tu empresa transportista».',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El viaje no existe. El mensaje devuelto es: El viaje no existe. Solo se llega con un cuerpo VÁLIDO: un id inventado con un cuerpo inválido devuelve 422. Un viaje BORRADO no cae aquí: es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Monto ausente o inválido, o descripción inválida, con el formato propio de Laravel {message, errors}. Mensajes literales: El monto es obligatorio / El monto debe ser un número / El monto debe ser mayor a 0 / El monto no puede superar 99999999.99 / La descripción debe ser texto / La descripción no puede superar los 255 caracteres. Es lo PRIMERO en fallar después del middleware de rol.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreTripExpenseRequest $request, int $trip, TripExpenseServiceInterface $tripExpenseService)
    {
        try {
            $expense = $tripExpenseService->create(auth('api')->user(), $trip, $request->validated());

            return ResponseHandler::success(
                new TripExpenseResource($expense),
                'Viático registrado correctamente',
                201,
            );
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Confirm one travel allowance on behalf of the assigned pilot.
     *
     * Always **200**, whether the date was just written or it was already there: the
     * confirmation is a fact that happened once, and a retry from a phone on a bad
     * network must not see an error.
     */
    #[OA\Patch(
        path: '/api/trip-expenses/{tripExpense}/confirm',
        operationId: 'confirmTripExpense',
        summary: 'Confirmar la recepción de un viático',
        description: <<<'TEXT'
        El piloto asignado da fe de haber recibido el dinero: escribe receivedAt con el now() del SERVIDOR y confirmedBy con su propio id. HASTA QUE SE CONFIRMA, el viático NO suma en el totalAmount del listado ni en el totalExpensesAmount del viaje. A diferencia del combustible, NO bloquea nada: /start no exige viáticos confirmados.

        ATENCIÓN — NO TIENE CUERPO. No lleva FormRequest y no acepta ningún campo: mandar receivedAt, amount o cualquier otra cosa NO CAMBIA NADA y no da 422, igual que en /start y /finish de SPEC 24.

        ATENCIÓN — LA RUTA NO ESTÁ ANIDADA: es /api/trip-expenses/{tripExpense}/confirm y el parámetro es el id DEL VIÁTICO, no del viaje. No existe ningún GET /api/trip-expenses/{tripExpense} de detalle: el id se obtiene del listado del viaje.

        ATENCIÓN — ES EXCLUSIVA DEL ROL pilot (middleware role:pilot) Y ADEMÁS SOLO DEL PILOTO ASIGNADO: administrator, carrier y manager reciben 403 del middleware —ni siquiera la empresa que registró el viático puede confirmarlo—, y cualquier otro piloto recibe 403 «No puedes confirmar el viático de un viaje que no tienes asignado».

        ATENCIÓN — RECONFIRMAR ES 200 SIN ESCRIBIR NADA. Si receivedAt ya tiene valor se devuelve el viático TAL CUAL, con su fecha ORIGINAL, sin tocar la fila. El mensaje es el mismo en los dos casos.

        ATENCIÓN — NO SE MIRA EL status DEL VIAJE: se puede confirmar el viático de un viaje ya finished —papeleo atrasado—. Y EL VIÁTICO DE UN VIAJE BORRADO NO SE PUEDE CONFIRMAR, CON 403 Y NO 400: el viaje se resuelve por la relación, que no ve las filas con borrado lógico.

        NO SE PUEDE DESCONFIRMAR: receivedAt no vuelve nunca a null por ninguna vía. Confirmar no toca el viaje, no manda correo y no emite por Reverb.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trip Expenses'],
        parameters: [
            new OA\Parameter(
                name: 'tripExpense',
                description: 'Id numérico DEL VIÁTICO (trip_expenses.id), no del viaje. Se obtiene del listado GET /api/trips/{trip}/expenses, porque no hay endpoint de detalle. Un id inexistente devuelve 404 «El viático no existe»; uno de un viaje que no es del piloto autenticado, 403.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Viático confirmado correctamente. MISMO STATUS Y MISMO MENSAJE en los dos casos: si estaba sin confirmar, receivedAt trae el now() del servidor y confirmedByName al piloto autenticado; si YA ESTABA CONFIRMADO, se devuelve tal cual con su receivedAt ORIGINAL y no se escribió nada. A partir de aquí este monto SÍ suma en totalAmount y en totalExpensesAmount.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Viático confirmado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/TripExpense'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'Sin permiso para confirmar. DOS ORÍGENES: el middleware role:pilot rechaza a administrator, carrier y manager con «No tienes permisos para acceder a este recurso»; y un pilot que no es el pilot_id del viaje recibe del service «No puedes confirmar el viático de un viaje que no tienes asignado». ESE MISMO 403 CUBRE un viático cuyo viaje no tiene piloto y uno cuyo viaje FUE BORRADO.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El id no corresponde a ningún viático. El mensaje devuelto es: El viático no existe. Nada se borra nunca de trip_expenses, así que un id que existió sigue existiendo.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function confirm(int $tripExpense, TripExpenseServiceInterface $tripExpenseService)
    {
        try {
            $expense = $tripExpenseService->confirm(auth('api')->user(), $tripExpense);

            return ResponseHandler::success(
                new TripExpenseResource($expense),
                'Viático confirmado correctamente',
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
