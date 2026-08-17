<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Pilot\UpdatePilotSalaryRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\Pilot\PilotResource;
use App\Http\Resources\Pilot\PilotSalaryHistoryResource;
use App\Interfaces\Pilot\PilotServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Pilots',
    description: 'Administración del salario base de los pilotos vinculados a una empresa transportista, y bitácora de cada cambio. El dominio tiene EXACTAMENTE TRES ENDPOINTS y ninguno más: el listado GET /api/pilots, la asignación PATCH /api/pilots/{pilot}/salary y el historial GET /api/pilots/{pilot}/salary-history. NO EXISTEN show, store, update ni destroy: vincular un piloto a una empresa sigue siendo POST /api/carriers/join, y desvincularlo no existe en ninguna parte de la API. EL SALARIO ES MENSUAL Y EN QUETZALES (GTQ): la columna decimal(10,2) no lo declara, es convención del dominio y solo lo dice esta documentación; viaja como CADENA con dos decimales, y un salary null significa "todavía no se le ha asignado salario", NUNCA "gana cero" —un piloto recién unido nace con null—. EL PARÁMETRO {pilot} ES EL user_id DEL PILOTO, no el id de la fila de carrier_pilots, que no sale nunca de la API. PERMISOS ASIMÉTRICOS ENTRE LECTURA Y ESCRITURA: leen administrator, manager y carrier; escribe el salario solo administrator y carrier, de modo que el manager recibe 403 en el PATCH aunque pueda ver todos los salarios y todos los historiales. EL ROL pilot RECIBE 403 EN LOS TRES ENDPOINTS, incluso consultando su propio user_id: hoy el dominio entero es de administración y no hay "mi salario". Las tres rutas llevan jwt.auth, role: y carrier.required, aunque administrator y manager están exentos de este último por definición del middleware. ÁMBITO: administrator y manager ven los pilotos de TODAS las empresas y pueden filtrar por carrierId; a un carrier el carrierId se le IGNORA EN SILENCIO —ni 403 ni 422— y queda acotado a su propia empresa, y tocar un piloto ajeno es 403. OJO CON EL OTRO LISTADO: GET /api/carriers/me/pilots (SPEC 03) también lista pilotos, pero SIN salary y con joinedAt en ISO 8601 — aquel es el listado del transportista, este es el de administración; conviven a propósito y no se han unificado.',
)]
class PilotController extends Controller
{
    #[OA\Get(
        path: '/api/pilots',
        operationId: 'indexPilots',
        summary: 'Listar pilotos con su salario',
        description: <<<'TEXT'
        Devuelve los pilotos vinculados a una empresa transportista, cada uno con su empresa resuelta por nombre y con su SALARIO BASE MENSUAL EN QUETZALES. Es el listado de administración del dominio; el listado del transportista es otro, GET /api/carriers/me/pilots, que no trae salary y formatea la fecha en ISO 8601.

        Solo aparecen usuarios que SON pilotos de alguna empresa: la lista sale de la pivote carrier_pilots, no de la tabla de usuarios, así que un usuario con rol pilot que todavía no se ha unido a ninguna empresa NO figura aquí.

        ATENCIÓN — EL ÁMBITO DEPENDE DEL ROL. Un administrator o un manager ven los pilotos de TODAS las empresas del sistema, y para ellos el filtro carrierId sí acota. Un carrier queda acotado a su propia empresa y el carrierId SE LE IGNORA EN SILENCIO: mandar el id de otra empresa NO devuelve 403 ni 422, devuelve 200 con SUS pilotos, exactamente igual que si no lo hubiera mandado. Un pilot recibe 403, incluso queriendo verse a sí mismo.

        Conviene decirlo en voz alta: un manager no está acotado a ninguna empresa, así que esta llamada le devuelve el sueldo de cada piloto de cada transportista registrado. El control es por rol, no por campo — quien alcanza este endpoint ve el salario de todos los pilotos que su ámbito le permita listar.

        Los filtros son TOLERANTES y ninguno provoca 422: un carrierId no numérico (carrierId=abc) se ignora y devuelve el listado completo del ámbito; un carrierId numérico de una empresa que no existe devuelve 200 con data VACÍO, nunca 404; un limit no numérico simplemente no pagina. No hay filtro por nombre, por email, por rango de salario ni por "pilotos sin salario asignado".

        El orden es fijo y no configurable: id ASC de la fila pivote, es decir el orden en que los pilotos se fueron uniendo. No hay sortBy ni sortDir.

        La forma de la respuesta depende de limit: sin limit se devuelve la colección completa y el sobre NO trae total, currentPage ni lastPage; con un limit numérico esos tres campos salen APLANADOS en la raíz del sobre, no bajo meta.

        Un piloto al que todavía no se le ha asignado salario sale con salary null. Ese null NO es cero: es "sin asignar".
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Pilots'],
        parameters: [
            new OA\Parameter(
                name: 'carrierId',
                description: 'Acota el listado a los pilotos de una empresa transportista (carriers.id). ATENCIÓN — SOLO SURTE EFECTO PARA administrator Y manager: a un carrier se le IGNORA EN SILENCIO, porque su ámbito ya está fijado a su propia empresa, y mandar el id de otra empresa le devuelve 200 con sus propios pilotos, no 403 ni 422. Es TOLERANTE: un valor no numérico se ignora sin error; un id numérico de una empresa inexistente devuelve 200 con data vacío. No comprueba que la empresa exista, así que por sí solo nunca produce 404 ni 422.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 4),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que activa la paginación. Si se omite, o si no es numérico (por ejemplo limit=abc), se devuelven todos los pilotos del ámbito sin error y sin metadatos de paginación. Si es numérico se acota al rango [10, 100]: limit=1 devuelve páginas de 10 y limit=500 devuelve páginas de 100. Se combina con carrierId.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 10, example: 10),
            ),
            new OA\Parameter(
                name: 'page',
                description: 'Página solicitada. Solo tiene efecto cuando se envía un limit numérico.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Pilotos obtenidos correctamente. Sin limit se devuelve PilotListResponse; con limit numérico, PaginatedPilotListResponse. Una empresa sin pilotos, o un carrierId sin coincidencias, devuelven 200 con data vacío, nunca 404.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/PilotListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedPilotListResponse'),
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
                description: 'Dos causas distintas con mensajes distintos. Por ROL: el usuario autenticado es un pilot —el único de los cuatro roles que no puede leer este listado, ni siquiera para verse a sí mismo—, y el middleware role responde: No tienes permisos para acceder a este recurso. Por FALTA DE EMPRESA: el usuario es un carrier que todavía no ha registrado ninguna empresa y lo frena carrier.required antes de llegar al service, con el mensaje: Debes estar vinculado a un transportista para acceder a este recurso. Un administrator y un manager están exentos de esta segunda comprobación y alcanzan el listado aunque no pertenezcan a ninguna empresa.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function index(Request $request, PilotServiceInterface $pilotService)
    {
        try {
            $pilots = $pilotService->getPilots(auth('api')->user(), $this->filters($request));

            $data = $pilots instanceof LengthAwarePaginator
                ? new PaginatedResource($pilots, PilotResource::class)
                : PilotResource::collection($pilots);

            return ResponseHandler::success($data, 'Pilotos obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/pilots/{pilot}/salary',
        operationId: 'updatePilotSalary',
        summary: 'Asignar o actualizar el salario de un piloto',
        description: <<<'TEXT'
        Fija el SALARIO BASE MENSUAL EN QUETZALES (GTQ) de un piloto y deja registrado el cambio en la bitácora. Sirve igual para la PRIMERA asignación —el piloto tenía salary null— que para cualquier cambio posterior: no hay dos endpoints, y lo único que distingue a la primera vez es que su entrada del historial es la única con previousSalary null.

        Es la ÚNICA escritura de todo el dominio. El cuerpo tiene un solo campo, salary, y es OBLIGATORIO: un CUERPO VACÍO responde 422, no 200 como no-op. No se acepta ningún otro campo; en particular, el autor del cambio (changedBy) sale SIEMPRE del usuario autenticado y mandarlo en el cuerpo no tiene ningún efecto.

        ATENCIÓN — PERMISOS MÁS ESTRECHOS QUE LOS DE LECTURA: solo administrator y carrier. El MANAGER RECIBE 403 AQUÍ, aunque sí pueda listar pilotos y leer historiales de cualquier empresa: es el único punto del proyecto donde un rol tiene alcance total de lectura y cero de escritura. El pilot también recibe 403, incluso sobre su propio user_id.

        ATENCIÓN — MANDAR EL MISMO SALARIO QUE EL PILOTO YA TIENE RESPONDE 400 y NO añade ninguna fila a la bitácora. La comparación es sobre el valor formateado a DOS DECIMALES: contra un salary de 4500.00, los valores 4500, 4500.00 y 4500.004 son el mismo salario y los tres devuelven 400. Es deliberado, para que toda fila del historial sea un cambio real y el reenvío del mismo formulario no ensucie la bitácora. Un front que reenvíe sin cambios debe esperar este 400 y tratarlo como "no había nada que guardar".

        BAJAR EL SALARIO ESTÁ PERMITIDO, sin restricción ni aprobación, y se registra igual que una subida. Lo que no se puede es poner a alguien en cero: el mínimo es 0.01, porque dejar a un piloto en cero sería desvincularlo y eso no existe en esta API.

        El {pilot} de la ruta es el user_id del piloto, NO el id de la fila de carrier_pilots. El 404 cubre DOS CASOS a propósito y con el MISMO mensaje: un user_id que no existe y un usuario que existe pero no es piloto de ninguna empresa. Distinguirlos convertiría el endpoint en un oráculo de qué ids de usuario hay en el sistema.

        Para un carrier, tocar un piloto de OTRA empresa es 403, no 404: el piloto existe, simplemente no es suyo. Un administrator puede tocar el de cualquier empresa.

        La escritura de la columna y la inserción en la bitácora corren en la MISMA TRANSACCIÓN, con la fila bloqueada: no puede quedar un salario cambiado sin rastro, ni un rastro de un cambio que no ocurrió. Si la bitácora fallara, el salario tampoco se guardaría.

        El cambio rige DESDE QUE SE GUARDA: no hay effective_from ni aumentos programados a futuro, y no se notifica al piloto. La respuesta devuelve el piloto completo, ya con el salario nuevo.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdatePilotSalaryRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Pilots'],
        parameters: [
            new OA\Parameter(
                name: 'pilot',
                description: 'Identificador del USUARIO piloto (users.id), NO el id de la fila de carrier_pilots — ese id no sale nunca de la API. Es el mismo valor que devuelve el campo id de GET /api/pilots y el de GET /api/carriers/me/pilots. El service lo traduce internamente a la fila pivote; si no hay ninguna fila con ese user_id, la respuesta es 404, tanto si el usuario no existe como si existe pero no es piloto de ninguna empresa.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Salario actualizado correctamente. data trae el piloto completo con el salario YA APLICADO, como cadena con dos decimales, y en la bitácora ha quedado UNA fila nueva con el salario anterior (null si era la primera asignación), el nuevo y el usuario autenticado como autor.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Salario actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Pilot'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'El salario enviado es IDÉNTICO al que el piloto ya tiene registrado, comparado a dos decimales: contra un 4500.00 caen aquí tanto 4500 como 4500.00 y 4500.004. La bitácora NO cambia y el número de filas del historial se queda igual. No es un valor mal formado —eso sería 422— sino un cambio que no cambia nada. El mensaje devuelto es: El salario indicado es el mismo que el piloto ya tiene registrado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'Tres causas con dos mensajes distintos. Por ROL: el usuario es un manager o un pilot —ATENCIÓN, el manager SÍ puede listar pilotos y leer historiales, pero NO puede escribir el salario, y el pilot no alcanza nada del dominio ni sobre sí mismo—, con el mensaje del middleware role: No tienes permisos para acceder a este recurso. Por FALTA DE EMPRESA: un carrier que aún no ha registrado empresa lo frena carrier.required, con el mensaje: Debes estar vinculado a un transportista para acceder a este recurso. Por ÁMBITO: un carrier que intenta tocar un piloto de OTRA empresa, con el mensaje: No puedes acceder a un piloto que no pertenece a tu empresa transportista — nótese que este último confirma que el piloto existe, al contrario que el 404.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No hay ninguna fila de carrier_pilots con ese user_id. Cubre DOS casos a propósito y con el MISMO mensaje: el usuario no existe, o existe pero no es piloto de ninguna empresa transportista. Distinguirlos filtraría qué ids de usuario están registrados. Un piloto que existe pero es de otra empresa NO cae aquí, cae en el 403. El mensaje devuelto es: El piloto no existe o no está vinculado a ninguna empresa transportista',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos del cuerpo. Casos: falta salary —un CUERPO VACÍO cae aquí, este PATCH no admite no-op— (El salario es obligatorio); salary no es numérico, por ejemplo "abc" (El salario debe ser un número en quetzales); salary es 0 o negativo (El salario debe ser mayor que cero); o salary supera 99999999.99 (El salario no puede superar los 99999999.99 quetzales). Enviar el salario que el piloto ya tiene NO es 422: es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function updateSalary(UpdatePilotSalaryRequest $request, int $pilot, PilotServiceInterface $pilotService)
    {
        try {
            $updated = $pilotService->updateSalary($pilot, $request->validated(), auth('api')->user());

            return ResponseHandler::success(new PilotResource($updated), 'Salario actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/pilots/{pilot}/salary-history',
        operationId: 'salaryHistoryPilots',
        summary: 'Consultar la bitácora de salario de un piloto',
        description: <<<'TEXT'
        Devuelve todos los cambios de salario de un piloto: qué salario tenía, cuál pasó a tener, quién lo cambió y cuándo. Todos los importes son MENSUALES Y EN QUETZALES (GTQ), como cadenas con dos decimales.

        El orden es fijo: del cambio MÁS RECIENTE al MÁS ANTIGUO. Se ordena por id DESC y no por fecha, porque dos cambios en el mismo segundo empatarían el changedAt y el orden quedaría indefinido. La ÚLTIMA entrada de la lista es siempre la primera asignación, y es la única de todo el historial que trae previousSalary null.

        TODA ENTRADA ES UN CAMBIO REAL: un PATCH con el salario que el piloto ya tiene responde 400 y no escribe aquí, así que no hay filas en las que previousSalary y newSalary coincidan. Encadenando la lista al revés, el newSalary de cada entrada es el previousSalary de la siguiente.

        Un piloto al que NUNCA se le ha cambiado el salario —el caso normal de un recién unido, con salary null— devuelve 200 con data VACÍO, nunca 404. El 404 es solo del piloto, no del historial.

        LO LEEN administrator, manager Y carrier: es lectura, así que aquí el manager SÍ entra, al contrario que en el PATCH. Un pilot recibe 403 incluso consultando su propio historial. Para un carrier, el historial de un piloto de OTRA empresa es 403, no 404.

        El {pilot} es el user_id del piloto, igual que en el PATCH, y comparte exactamente la misma guarda: el mismo 404 de dos casos y el mismo 403 de ámbito.

        Pagina con la MISMA regla que el resto del proyecto: sin limit se devuelve el historial completo y el sobre no trae total, currentPage ni lastPage; con un limit numérico, acotado a [10, 100], esos tres campos salen aplanados en la raíz. La bitácora crece sin techo y no se purga nunca, así que un piloto con muchos años de ajustes conviene leerlo paginado.

        Es SOLO LECTURA: no hay endpoint que edite ni borre una entrada, ni existe un historial global de la empresa (GET /api/pilots/salary-history sin id no existe), ni exportación a CSV o Excel.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Pilots'],
        parameters: [
            new OA\Parameter(
                name: 'pilot',
                description: 'Identificador del USUARIO piloto (users.id), NO el id de la fila de carrier_pilots. Es el mismo valor que el campo id de GET /api/pilots y el mismo que se manda en el PATCH. Si no hay ninguna fila de carrier_pilots con ese user_id, la respuesta es 404, tanto si el usuario no existe como si existe pero no es piloto de ninguna empresa.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página del historial. Su presencia es lo que activa la paginación. Si se omite o no es numérico (limit=abc), se devuelve la bitácora completa sin metadatos de paginación. Si es numérico se acota a [10, 100]: limit=1 devuelve páginas de 10 y limit=500 de 100. No existe filtro por rango de fechas ni por autor del cambio.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 10, example: 10),
            ),
            new OA\Parameter(
                name: 'page',
                description: 'Página solicitada. Solo tiene efecto cuando se envía un limit numérico.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Historial de salario obtenido correctamente, ordenado del cambio más reciente al más antiguo. Sin limit se devuelve PilotSalaryHistoryListResponse; con limit numérico, PaginatedPilotSalaryHistoryListResponse. Un piloto sin ningún cambio de salario devuelve 200 con data vacío, nunca 404.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/PilotSalaryHistoryListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedPilotSalaryHistoryListResponse'),
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
                description: 'Tres causas con dos mensajes distintos. Por ROL: el usuario es un pilot —el único rol que no lee este historial, tampoco el suyo propio; el manager SÍ puede leerlo, aunque no pueda escribir el salario—, con el mensaje del middleware role: No tienes permisos para acceder a este recurso. Por FALTA DE EMPRESA: un carrier sin empresa registrada, frenado por carrier.required, con el mensaje: Debes estar vinculado a un transportista para acceder a este recurso. Por ÁMBITO: un carrier consultando el historial de un piloto de OTRA empresa, con el mensaje: No puedes acceder a un piloto que no pertenece a tu empresa transportista',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No hay ninguna fila de carrier_pilots con ese user_id. Cubre los mismos DOS casos que el PATCH y con el MISMO mensaje: el usuario no existe, o existe pero no es piloto de ninguna empresa transportista. Un piloto que existe pero no tiene ningún cambio registrado NO cae aquí: eso es un 200 con data vacío. El mensaje devuelto es: El piloto no existe o no está vinculado a ninguna empresa transportista',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function salaryHistory(Request $request, int $pilot, PilotServiceInterface $pilotService)
    {
        try {
            $histories = $pilotService->getSalaryHistory(
                $pilot,
                auth('api')->user(),
                $this->queryString($request, 'limit'),
            );

            $data = $histories instanceof LengthAwarePaginator
                ? new PaginatedResource($histories, PilotSalaryHistoryResource::class)
                : PilotSalaryHistoryResource::collection($histories);

            return ResponseHandler::success($data, 'Historial de salario obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Read the listing filters from the query string.
     *
     * Which of them are valid, and what to do with the invalid ones, is decided
     * by the service; here they are only normalized to strings, since anything
     * else is not a valid value.
     *
     * @return array{carrierId: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'carrierId' => $this->queryString($request, 'carrierId'),
            'limit' => $this->queryString($request, 'limit'),
        ];
    }

    /**
     * Read a query string parameter, discarding anything that is not a string.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
