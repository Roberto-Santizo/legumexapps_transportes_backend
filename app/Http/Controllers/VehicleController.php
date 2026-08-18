<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Vehicle\StoreVehicleRequest;
use App\Http\Requests\Vehicle\UpdateVehicleRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\Vehicle\VehicleResource;
use App\Interfaces\Vehicle\VehicleServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Vehicles',
    description: 'Inventario de vehículos de las empresas transportistas. Todos los endpoints requieren token JWT (Authorization: Bearer {token}), llevan el middleware carrier.required y están filtrados por rol: solo carrier y administrator entran, y el alta es exclusiva del carrier. Los roles pilot y manager reciben 403 en los cinco endpoints. El ámbito se resuelve en el servicio: un carrier solo alcanza los vehículos de su propia empresa y un administrator los de todas; el administrator está exento de carrier.required, así que opera aunque no esté vinculado a ninguna empresa. La capacidad va siempre EN LIBRAS y la imagen se valida pero no se almacena.',
)]
class VehicleController extends Controller
{
    #[OA\Get(
        path: '/api/vehicles',
        operationId: 'indexVehicles',
        summary: 'Listar vehículos',
        description: <<<'TEXT'
        Devuelve los vehículos visibles para el usuario autenticado. Pueden llamarlo los roles carrier y administrator (middlewares role:carrier,administrator y carrier.required; el administrator está exento del segundo).

        El ámbito lo fija el rol, no la petición: un carrier recibe únicamente los vehículos de su empresa y nunca los de otra; un administrator recibe los de todas las empresas.

        ATENCIÓN — el listado devuelve por defecto TODOS los estados, incluidos los inactive (los desactivados con DELETE) y los under_repair. Un cliente que pinte "mis vehículos" sin filtrar mostrará vehículos dados de baja como si estuvieran operativos: filtrar es responsabilidad del consumidor, y para eso está el parámetro status.

        Los filtros son tolerantes: un status o un condition que no pertenecen a su enum, un carrierId no numérico, un engineNumber vacío o un limit no numérico se ignoran en silencio y la lectura devuelve el listado completo del ámbito con 200, nunca una lista vacía ni un 422. Los cinco se combinan entre sí sin interferir. El único filtro por texto es engineNumber, que busca por coincidencia parcial; no hay ordenación.

        La forma de la respuesta depende del parámetro limit: sin limit se devuelven todos los registros del ámbito y el sobre NO trae total, currentPage ni lastPage; con un limit numérico se devuelve el sobre paginado con esos tres campos aplanados en la raíz.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Vehicles'],
        parameters: [
            new OA\Parameter(
                name: 'status',
                description: 'Filtra por estado operativo. Solo se aplica si el valor pertenece al enum; cualquier otro valor (por ejemplo status=cualquiercosa) se ignora sin error y se devuelven todos los registros del ámbito. Si se omite, el listado incluye los tres estados.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['active', 'inactive', 'under_repair'], example: 'under_repair'),
            ),
            new OA\Parameter(
                name: 'carrierId',
                description: 'Filtra por empresa transportista. ATENCIÓN: solo lo aplica el rol administrator. En un carrier se ignora en silencio —no devuelve 403 ni 422—, porque su ámbito ya está fijado por su empresa: seguirá recibiendo solo los suyos aunque envíe el id de otra empresa. Un valor no numérico se ignora igualmente.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
            new OA\Parameter(
                name: 'condition',
                description: 'Filtra por la condición con la que se adquirió el vehículo, por COINCIDENCIA EXACTA con el valor del enum. NO ES EL FILTRO status: son dos ejes distintos y se pueden combinar —status es el estado operativo (active, inactive, under_repair) y condition es cómo se compró el vehículo (new, used)—, así que ?status=active&condition=new devuelve los vehículos operativos comprados nuevos. ES TOLERANTE: solo se aplica si el valor pertenece al enum; cualquier otro (por ejemplo condition=antiguo, o el parámetro vacío) se ignora en silencio y se devuelve el listado COMPLETO del ámbito, nunca una lista vacía ni un 422. Si se omite, el listado incluye las dos condiciones.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['new', 'used'], example: 'new'),
            ),
            new OA\Parameter(
                name: 'engineNumber',
                description: 'Filtra por número de motor, por COINCIDENCIA PARCIAL (LIKE %término%): engineNumber=abc casa con un vehículo cuyo número es XABC123. Es CASE-INSENSITIVE porque la columna se guarda siempre en mayúsculas y el término se normaliza a mayúsculas antes de buscar, así que abc y ABC dan el mismo resultado. Los vehículos con engineNumber null —los registrados antes de esta versión— NO aparecen nunca en un resultado de este filtro: no tienen número que buscar. ES TOLERANTE: un valor que no sea texto, o que quede vacío tras recortar espacios, se ignora en silencio y se devuelve el listado COMPLETO del ámbito, nunca una lista vacía ni un 422. Recuerda que el número de motor no es único: la búsqueda puede devolver varios vehículos aunque el término coincida entero.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', maxLength: 50, example: 'ABC123'),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que activa la paginación. Si se omite, o si no es numérico (por ejemplo limit=abc), se devuelven todos los registros sin error y sin metadatos de paginación. Si es numérico se acota al rango [10, 100]: limit=3 devuelve páginas de 10 y limit=500 devuelve páginas de 100. Se combina con status y con carrierId.',
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
                description: 'Vehículos obtenidos correctamente. Sin limit se devuelve VehicleListResponse; con limit numérico, PaginatedVehicleListResponse.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/VehicleListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedVehicleListResponse'),
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
                description: 'El rol del usuario autenticado no es carrier ni administrator —un pilot o un manager caen aquí— (mensaje del middleware role: No tienes permisos para acceder a este recurso) o es un carrier que todavía no está vinculado a ninguna empresa (mensaje del middleware carrier.required: Debes estar vinculado a un transportista para acceder a este recurso)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function index(Request $request, VehicleServiceInterface $vehicleService)
    {
        try {
            $vehicles = $vehicleService->getVehicles(auth('api')->user(), $this->filters($request));

            $data = $vehicles instanceof LengthAwarePaginator
                ? new PaginatedResource($vehicles, VehicleResource::class)
                : VehicleResource::collection($vehicles);

            return ResponseHandler::success($data, 'Vehículos obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/vehicles',
        operationId: 'storeVehicle',
        summary: 'Registrar un vehículo en la empresa propia',
        description: <<<'TEXT'
        Registra un vehículo en la empresa del usuario autenticado, que debe tener rol carrier (middlewares role:carrier y carrier.required). Un administrator recibe 403: supervisa y corrige el inventario, pero el alta la hace quien es dueño de él.

        La empresa no se envía en el cuerpo: se resuelve desde el usuario autenticado, por eso un carrier sin empresa queda cortado por carrier.required antes de llegar al servicio. El status tampoco se envía: el vehículo nace siempre en active y mandarlo no cambia nada.

        La placa se normaliza a mayúsculas antes de validarla y de persistirla (p123abc se guarda como P123ABC). Su unicidad es condicional y se comprueba en el servicio, no en base de datos: hay conflicto —400— si existe cualquier vehículo, de la propia empresa o de otra, con esa misma placa y un status distinto de inactive. Un vehículo under_repair también bloquea la placa; solo el desactivado la libera, y entonces el alta devuelve 201 y quedan dos filas con la misma placa sin vínculo entre ellas.

        La capacidad va EN LIBRAS. La unidad no se guarda ni se valida: nada impide que un cliente envíe kilos y nada lo detectará.

        La imagen sí se almacena, pero no tal cual llegó. El archivo se valida (jpg, jpeg o png; cualquier otro tipo es 422, y más de 3 MB también) y antes de subirlo se recorta a un cuadrado centrado de 800x800 px conservando su formato: se pierden los bordes del lado largo y el original no se guarda en ningún sitio. El campo image de la respuesta es la URL pública y permanente del resultado. La subida ocurre ANTES de crear la fila y DESPUÉS de comprobar el ámbito y la placa: si la imagen no se puede procesar o el almacenamiento falla, la respuesta es 400 y el vehículo no se crea.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(ref: '#/components/schemas/StoreVehicleRequest'),
            ),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Vehicles'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Vehículo registrado correctamente, con carrier_id el de la empresa del usuario autenticado y status active.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Vehículo registrado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Vehicle'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'La placa ya la usa un vehículo cuyo status no es inactive, sea de la propia empresa o de otra, y no se registra nada. El mensaje devuelto es: La placa ya está registrada en un vehículo que no está desactivado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es carrier —un administrator, un pilot o un manager caen aquí— (mensaje del middleware role: No tienes permisos para acceder a este recurso) o es un carrier que todavía no ha registrado su empresa (mensaje del middleware carrier.required: Debes estar vinculado a un transportista para acceder a este recurso)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: falta alguno de los siete campos obligatorios, la placa supera los 15 caracteres, la marca o el modelo superan los 100, el año no es entero o queda fuera de [1900, año actual + 1], la capacidad no es numérica o es negativa, el tipo no pertenece al enum, o la imagen no es un archivo jpg, jpeg ni png',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreVehicleRequest $request, VehicleServiceInterface $vehicleService)
    {
        try {
            $vehicle = $vehicleService->createVehicle($request->validated(), auth('api')->user());

            return ResponseHandler::success(new VehicleResource($vehicle), 'Vehículo registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/vehicles/{vehicle}',
        operationId: 'showVehicle',
        summary: 'Obtener un vehículo por id',
        description: <<<'TEXT'
        Devuelve un vehículo concreto, con carrierName incluido. Pueden llamarlo los roles carrier y administrator (middlewares role:carrier,administrator y carrier.required; el administrator está exento del segundo).

        El ámbito se comprueba en el servicio: un carrier solo alcanza los vehículos de su propia empresa y recibe 403 si pide uno ajeno; un administrator obtiene el de cualquier empresa. Se responde 403 y no 404 en ese caso, de forma coherente con el resto del proyecto: se acepta revelar que el id existe a cambio de un mensaje honesto.

        Un vehículo desactivado (status inactive) se sigue obteniendo con normalidad: la fila nunca se borra.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Vehicles'],
        parameters: [
            new OA\Parameter(
                name: 'vehicle',
                description: 'Identificador numérico del vehículo (vehicles.id). No es la placa.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Vehículo obtenido correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Vehículo obtenido correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Vehicle'),
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
                description: 'El rol no es carrier ni administrator —un pilot o un manager caen aquí— (mensaje del middleware role: No tienes permisos para acceder a este recurso), el carrier autenticado todavía no tiene empresa (mensaje del middleware carrier.required: Debes estar vinculado a un transportista para acceder a este recurso) o el carrier pide un vehículo de otra empresa (mensaje: No puedes acceder a un vehículo que no pertenece a tu empresa transportista)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ningún vehículo con ese id. El mensaje devuelto es: El vehículo no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $vehicle, VehicleServiceInterface $vehicleService)
    {
        try {
            $found = $vehicleService->getVehicleById(auth('api')->user(), $vehicle);

            return ResponseHandler::success(new VehicleResource($found), 'Vehículo obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/vehicles/{vehicle}',
        operationId: 'updateVehicle',
        summary: 'Actualizar un vehículo',
        description: <<<'TEXT'
        Actualiza plate, brand, model, year, capacity, type, image y status de un vehículo. La ruta acepta PATCH y PUT indistintamente: en ambos casos la actualización es parcial, solo se modifican los campos presentes en el cuerpo y ninguno es obligatorio. La empresa dueña no se puede cambiar.

        Pueden llamarlo los roles carrier y administrator (middlewares role:carrier,administrator y carrier.required). Un carrier solo puede actualizar vehículos de su propia empresa: si intenta uno ajeno recibe 403. Un administrator puede actualizar el de cualquier empresa.

        A diferencia del alta, aquí sí se acepta status, con los tres valores del enum. La placa se normaliza a mayúsculas y su unicidad se revalida cuando cambia respecto a la que ya tiene el vehículo, excluyéndolo a él mismo: reenviar la placa que ya se tiene devuelve 200 y nunca colisiona consigo mismo. Cambiarla a una que usa un vehículo no desactivado de cualquier empresa devuelve 400.

        ATENCIÓN — sacar un vehículo de inactive (a active o a under_repair) también revalida su placa, aunque el cuerpo no la envíe: un vehículo que vuelve al servicio no puede compartir placa con otro que ya la usa. Si otra empresa registró esa placa mientras el vehículo estaba desactivado, la reactivación devuelve 400 con el mensaje "No puedes reactivar este vehículo: su placa ya está registrada en otro vehículo que no está desactivado" y el vehículo se queda desactivado. Resolver ese conflicto (liberar la placa ajena o asignarle otra en la misma operación) está fuera del alcance de la SPEC 04. Mientras el vehículo siga en inactive su placa duplicada no molesta: el resto de campos se actualizan con normalidad.

        Si se envía image, el cuerpo debe ir como multipart/form-data. Igual que en el alta, la imagen se recorta a un cuadrado centrado de 800x800 px antes de subirla y no puede pasar de 3 MB. Al reemplazarla se borra la anterior del almacenamiento, de forma irreversible y solo después de que la fila quede guardada. Si no se toca la imagen, basta con application/json y la que hubiera se queda como está. La capacidad sigue siendo EN LIBRAS.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'multipart/form-data',
                    schema: new OA\Schema(ref: '#/components/schemas/UpdateVehicleRequest'),
                ),
                new OA\JsonContent(ref: '#/components/schemas/UpdateVehicleRequest'),
            ],
        ),
        security: [['bearerAuth' => []]],
        tags: ['Vehicles'],
        parameters: [
            new OA\Parameter(
                name: 'vehicle',
                description: 'Identificador numérico del vehículo (vehicles.id). No es la placa.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Vehículo actualizado correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Vehículo actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Vehicle'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'La placa nueva ya la usa un vehículo cuyo status no es inactive, sea de la propia empresa o de otra, y no se guarda ningún cambio (mensaje: La placa ya está registrada en un vehículo que no está desactivado); o se intenta sacar de inactive un vehículo cuya placa ya tomó otro que sigue en servicio (mensaje: No puedes reactivar este vehículo: su placa ya está registrada en otro vehículo que no está desactivado)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol no es carrier ni administrator —un pilot o un manager caen aquí— (mensaje del middleware role: No tienes permisos para acceder a este recurso), el carrier autenticado todavía no tiene empresa (mensaje del middleware carrier.required: Debes estar vinculado a un transportista para acceder a este recurso) o el carrier intenta actualizar un vehículo de otra empresa (mensaje: No puedes acceder a un vehículo que no pertenece a tu empresa transportista)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ningún vehículo con ese id. El mensaje devuelto es: El vehículo no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: algún campo enviado va vacío, la placa supera los 15 caracteres, la marca o el modelo superan los 100, el año no es entero o queda fuera de [1900, año actual + 1], la capacidad no es numérica o es negativa, el tipo no pertenece al enum, la imagen no es un archivo jpg, jpeg ni png, o el estado no pertenece al enum',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    #[OA\Put(
        path: '/api/vehicles/{vehicle}',
        operationId: 'replaceVehicle',
        summary: 'Actualizar un vehículo (alias PUT)',
        description: 'Mismo comportamiento que PATCH /api/vehicles/{vehicle}: la ruta admite ambos verbos y la actualización es parcial en los dos, no un reemplazo completo del recurso. Consulta esa operación para el detalle de permisos, del ámbito por rol, de la revalidación de la placa, del caso de la reactivación y del tratamiento de la imagen.',
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'multipart/form-data',
                    schema: new OA\Schema(ref: '#/components/schemas/UpdateVehicleRequest'),
                ),
                new OA\JsonContent(ref: '#/components/schemas/UpdateVehicleRequest'),
            ],
        ),
        security: [['bearerAuth' => []]],
        tags: ['Vehicles'],
        parameters: [
            new OA\Parameter(
                name: 'vehicle',
                description: 'Identificador numérico del vehículo (vehicles.id). No es la placa.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Vehículo actualizado correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Vehículo actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Vehicle'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'La placa nueva ya la usa un vehículo cuyo status no es inactive (mensaje: La placa ya está registrada en un vehículo que no está desactivado), o se intenta sacar de inactive un vehículo cuya placa ya tomó otro que sigue en servicio (mensaje: No puedes reactivar este vehículo: su placa ya está registrada en otro vehículo que no está desactivado)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol no es carrier ni administrator, el carrier autenticado todavía no tiene empresa, o el carrier intenta actualizar un vehículo de otra empresa (mensaje: No puedes acceder a un vehículo que no pertenece a tu empresa transportista)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ningún vehículo con ese id. El mensaje devuelto es: El vehículo no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: algún campo enviado va vacío o incumple su regla de tipo, longitud, rango o enum',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateVehicleRequest $request, int $vehicle, VehicleServiceInterface $vehicleService)
    {
        try {
            $updated = $vehicleService->updateVehicle($request->validated(), $vehicle, auth('api')->user());

            return ResponseHandler::success(new VehicleResource($updated), 'Vehículo actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/vehicles/{vehicle}',
        operationId: 'destroyVehicle',
        summary: 'Desactivar un vehículo (no lo borra)',
        description: <<<'TEXT'
        ATENCIÓN — este endpoint NO borra nada. Lo único que hace es poner el status del vehículo en inactive y devolver el vehículo ya desactivado en data. La fila sigue existiendo en base de datos y sigue apareciendo en GET /api/vehicles, porque el listado devuelve por defecto todos los estados: el cliente no debe retirar el vehículo de su estado local al recibir el 200, sino releer o filtrar por status. El borrado real queda fuera del alcance de la SPEC 04.

        Desactivar un vehículo que ya está inactive responde 200 sin efectos adicionales: la operación es idempotente.

        Desactivar libera la placa: a partir de ese momento otra empresa —o la misma— puede registrar un vehículo con esa misma placa y recibir 201, quedando dos filas con el mismo valor y sin vínculo entre ellas. Volver a activar el vehículo desactivado después de eso no está resuelto; consulta PATCH /api/vehicles/{vehicle}.

        Pueden llamarlo los roles carrier y administrator (middlewares role:carrier,administrator y carrier.required). Un carrier solo puede desactivar vehículos de su propia empresa: con uno ajeno recibe 403.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Vehicles'],
        parameters: [
            new OA\Parameter(
                name: 'vehicle',
                description: 'Identificador numérico del vehículo (vehicles.id). No es la placa.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Vehículo desactivado correctamente. El vehículo NO ha sido eliminado: data devuelve la fila con status inactive y esa fila sigue existiendo y apareciendo en los listados.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Vehículo desactivado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Vehicle'),
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
                description: 'El rol no es carrier ni administrator —un pilot o un manager caen aquí— (mensaje del middleware role: No tienes permisos para acceder a este recurso), el carrier autenticado todavía no tiene empresa (mensaje del middleware carrier.required: Debes estar vinculado a un transportista para acceder a este recurso) o el carrier intenta desactivar un vehículo de otra empresa (mensaje: No puedes acceder a un vehículo que no pertenece a tu empresa transportista)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ningún vehículo con ese id. El mensaje devuelto es: El vehículo no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $vehicle, VehicleServiceInterface $vehicleService)
    {
        try {
            $deactivated = $vehicleService->deleteVehicle($vehicle, auth('api')->user());

            return ResponseHandler::success(new VehicleResource($deactivated), 'Vehículo desactivado correctamente', 200);
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
     * @return array{status: string|null, carrierId: string|null, condition: string|null, engineNumber: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'status' => $this->queryString($request, 'status'),
            'carrierId' => $this->queryString($request, 'carrierId'),
            'condition' => $this->queryString($request, 'condition'),
            'engineNumber' => $this->queryString($request, 'engineNumber'),
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
