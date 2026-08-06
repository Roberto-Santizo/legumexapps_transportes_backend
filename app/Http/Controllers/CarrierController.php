<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Carrier\JoinCarrierRequest;
use App\Http\Requests\Carrier\StoreCarrierRequest;
use App\Http\Requests\Carrier\UpdateCarrierRequest;
use App\Http\Resources\Carrier\CarrierPilotResource;
use App\Http\Resources\Carrier\CarrierResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\Carrier\CarrierServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Carriers',
    description: 'Empresas transportistas y vinculación de pilotos. Todos los endpoints requieren token JWT (Authorization: Bearer {token}) y están filtrados por rol; además, los marcados con carrier.required exigen que el usuario autenticado esté vinculado a una empresa, salvo si es administrator o manager, que quedan exentos. El rol manager no puede llamar a ninguno de estos endpoints.',
)]
class CarrierController extends Controller
{
    #[OA\Get(
        path: '/api/carriers',
        operationId: 'indexCarriers',
        summary: 'Listar todas las empresas transportistas',
        description: 'Devuelve el listado completo de empresas. Solo el rol administrator puede llamarlo (middlewares role:administrator y carrier.required; el administrator está exento del segundo). La forma de la respuesta depende del parámetro limit: sin limit se devuelven todos los registros y el sobre NO trae total, currentPage ni lastPage; con un limit numérico se devuelve el sobre paginado con esos tres campos aplanados en la raíz. No hay búsqueda, filtros ni ordenación.',
        security: [['bearerAuth' => []]],
        tags: ['Carriers'],
        parameters: [
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que activa la paginación. Si se omite, o si no es numérico (por ejemplo limit=abc), se devuelven todos los registros sin error y sin metadatos de paginación. Si es numérico se acota al rango [10, 100]: limit=1 devuelve páginas de 10 y limit=500 devuelve páginas de 100.',
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
                description: 'Transportistas obtenidos correctamente. Sin limit se devuelve CarrierListResponse; con limit numérico, PaginatedCarrierListResponse.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/CarrierListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedCarrierListResponse'),
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
                description: 'El rol del usuario autenticado no es administrator. El mensaje devuelto por el middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function index(Request $request, CarrierServiceInterface $carrierService)
    {
        try {
            $carriers = $carrierService->getCarriers($this->limit($request));

            $data = $carriers instanceof LengthAwarePaginator
                ? new PaginatedResource($carriers, CarrierResource::class)
                : CarrierResource::collection($carriers);

            return ResponseHandler::success($data, 'Transportistas obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/carriers',
        operationId: 'storeCarrier',
        summary: 'Crear la empresa transportista propia',
        description: <<<'TEXT'
        Crea la empresa del usuario autenticado, que debe tener rol carrier (middleware role:carrier; este endpoint NO lleva carrier.required, porque es justamente el que deja de tener empresa a tenerla). La cardinalidad es uno a uno: un carrier tiene como máximo una empresa, y un segundo intento devuelve 400.

        El cuerpo va como multipart/form-data, con name e image. El code lo genera el servidor (6 caracteres alfanuméricos en mayúsculas, único) y active nace siempre en true: ninguno de los dos se puede enviar.

        La imagen sí se almacena, pero no tal cual llegó. El archivo se valida (jpg, jpeg o png; cualquier otro tipo es 422, y más de 3 MB también) y antes de subirlo se recorta a un cuadrado centrado de 800x800 px conservando su formato: se pierden los bordes del lado largo y el original no se guarda en ningún sitio. El campo image de la respuesta es la URL pública y permanente del resultado. La subida ocurre ANTES de crear la fila: si la imagen no se puede procesar o el almacenamiento falla, la respuesta es 400 y la empresa no se crea.

        Este endpoint no devuelve token nuevo: los claims carrierId, carrierName y carrierCode del JWT siguen en null hasta que el cliente llame a GET /api/auth/check-status.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(ref: '#/components/schemas/StoreCarrierRequest'),
            ),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Carriers'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Empresa transportista creada correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Empresa transportista creada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Carrier'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'El usuario autenticado ya tiene una empresa registrada y no se crea una segunda. El mensaje devuelto es: Ya tienes una empresa transportista registrada',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es carrier. El mensaje devuelto por el middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: falta el nombre o supera los 255 caracteres, falta la imagen, la imagen no es un archivo o su tipo no es jpg, jpeg ni png',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreCarrierRequest $request, CarrierServiceInterface $carrierService)
    {
        try {
            $carrier = $carrierService->createCarrier($request->validated(), auth('api')->user());

            return ResponseHandler::success(new CarrierResource($carrier), 'Empresa transportista creada correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/carriers/{carrier}',
        operationId: 'showCarrier',
        summary: 'Obtener una empresa transportista por id',
        description: 'Devuelve una empresa concreta, incluido su code. Solo el rol administrator puede llamarlo (middlewares role:administrator y carrier.required; el administrator está exento del segundo).',
        security: [['bearerAuth' => []]],
        tags: ['Carriers'],
        parameters: [
            new OA\Parameter(
                name: 'carrier',
                description: 'Identificador numérico de la empresa (carriers.id). No es el código de 6 caracteres.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transportista obtenido correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Transportista obtenido correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Carrier'),
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
                description: 'El rol del usuario autenticado no es administrator. El mensaje devuelto por el middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ninguna empresa con ese id. El mensaje devuelto es: El transportista no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $carrier, CarrierServiceInterface $carrierService)
    {
        try {
            $found = $carrierService->getCarrierById($carrier);

            return ResponseHandler::success(new CarrierResource($found), 'Transportista obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/carriers/{carrier}',
        operationId: 'updateCarrier',
        summary: 'Actualizar una empresa transportista',
        description: <<<'TEXT'
        Actualiza name, image y active de una empresa. La ruta acepta PATCH y PUT indistintamente: en ambos casos la actualización es parcial, solo se modifican los campos presentes en el cuerpo y ninguno es obligatorio.

        Pueden llamarlo los roles carrier y administrator (middlewares role:carrier,administrator y carrier.required). Un carrier solo puede actualizar su propia empresa: si intenta actualizar una ajena recibe 403. Un administrator puede actualizar cualquiera.

        Si se envía image, el cuerpo debe ir como multipart/form-data. Igual que en el alta, la imagen se recorta a un cuadrado centrado de 800x800 px antes de subirla y no puede pasar de 3 MB. Al reemplazarla se borra la anterior del almacenamiento, de forma irreversible y solo después de que la fila quede guardada. Si solo se actualizan name o active, basta con application/json y la imagen no se toca.

        El code no se puede modificar ni rotar, y active es hoy un dato informativo: ponerlo en false no bloquea a los pilotos ni impide que se unan con el código.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'multipart/form-data',
                    schema: new OA\Schema(ref: '#/components/schemas/UpdateCarrierRequest'),
                ),
                new OA\JsonContent(ref: '#/components/schemas/UpdateCarrierRequest'),
            ],
        ),
        security: [['bearerAuth' => []]],
        tags: ['Carriers'],
        parameters: [
            new OA\Parameter(
                name: 'carrier',
                description: 'Identificador numérico de la empresa (carriers.id). No es el código de 6 caracteres.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transportista actualizado correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Transportista actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Carrier'),
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
                description: 'El rol no es carrier ni administrator (mensaje del middleware role: No tienes permisos para acceder a este recurso), el carrier autenticado todavía no tiene empresa (mensaje del middleware carrier.required: Debes estar vinculado a un transportista para acceder a este recurso) o el carrier intenta actualizar una empresa ajena (mensaje: No puedes actualizar una empresa transportista que no te pertenece)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ninguna empresa con ese id. El mensaje devuelto es: El transportista no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: nombre vacío o de más de 255 caracteres, imagen que no es un archivo o cuyo tipo no es jpg, jpeg ni png, o estado que no es booleano',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    #[OA\Put(
        path: '/api/carriers/{carrier}',
        operationId: 'replaceCarrier',
        summary: 'Actualizar una empresa transportista (alias PUT)',
        description: 'Mismo comportamiento que PATCH /api/carriers/{carrier}: la ruta admite ambos verbos y la actualización es parcial en los dos, no un reemplazo completo del recurso. Consulta esa operación para el detalle de permisos, del tratamiento de la imagen y de los errores.',
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'multipart/form-data',
                    schema: new OA\Schema(ref: '#/components/schemas/UpdateCarrierRequest'),
                ),
                new OA\JsonContent(ref: '#/components/schemas/UpdateCarrierRequest'),
            ],
        ),
        security: [['bearerAuth' => []]],
        tags: ['Carriers'],
        parameters: [
            new OA\Parameter(
                name: 'carrier',
                description: 'Identificador numérico de la empresa (carriers.id). No es el código de 6 caracteres.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transportista actualizado correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Transportista actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Carrier'),
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
                description: 'El rol no es carrier ni administrator, el carrier autenticado todavía no tiene empresa, o el carrier intenta actualizar una empresa ajena (mensaje: No puedes actualizar una empresa transportista que no te pertenece)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ninguna empresa con ese id. El mensaje devuelto es: El transportista no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: nombre vacío o de más de 255 caracteres, imagen que no es un archivo o cuyo tipo no es jpg, jpeg ni png, o estado que no es booleano',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateCarrierRequest $request, int $carrier, CarrierServiceInterface $carrierService)
    {
        try {
            $updated = $carrierService->updateCarrier($request->validated(), $carrier, auth('api')->user());

            return ResponseHandler::success(new CarrierResource($updated), 'Transportista actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/carriers/{carrier}',
        operationId: 'destroyCarrier',
        summary: 'Eliminar una empresa transportista (sin efecto)',
        description: <<<'TEXT'
        ATENCIÓN — este endpoint NO borra nada. Comprueba que la empresa existe (404 si no) y responde 200, pero la fila sigue en base de datos y los pilotos vinculados conservan su empresa. El borrado real está fuera del alcance de la SPEC 03, para no dejar pilotos huérfanos ni empresas fantasma; la operación existe solo para completar el apiResource.

        El cliente no debe interpretar el 200 como confirmación de borrado ni retirar la empresa de su estado local: una lectura posterior la seguirá devolviendo.

        Solo el rol administrator puede llamarlo (middlewares role:administrator y carrier.required; el administrator está exento del segundo).
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Carriers'],
        parameters: [
            new OA\Parameter(
                name: 'carrier',
                description: 'Identificador numérico de la empresa (carriers.id). No es el código de 6 caracteres.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Respuesta de éxito. La empresa NO ha sido eliminada: la fila sigue existiendo.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Transportista eliminado correctamente'),
                        new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
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
                description: 'El rol del usuario autenticado no es administrator. El mensaje devuelto por el middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ninguna empresa con ese id. El mensaje devuelto es: El transportista no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $carrier, CarrierServiceInterface $carrierService)
    {
        try {
            $carrierService->deleteCarrier($carrier);

            return ResponseHandler::success(null, 'Transportista eliminado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/carriers/join',
        operationId: 'joinCarrier',
        summary: 'Vincular al piloto autenticado con una empresa',
        description: <<<'TEXT'
        Vincula al usuario autenticado, que debe tener rol pilot (middleware role:pilot; este endpoint NO lleva carrier.required, porque es el que hace pasar al piloto de no tener empresa a tenerla), con la empresa dueña del código enviado.

        El código se normaliza a mayúsculas antes de buscarlo, así que a7k2qx y A7K2QX vinculan a la misma empresa. Un código que no pertenece a ninguna empresa devuelve 404, y un piloto que ya pertenece a una empresa devuelve 400 sin alterar su vínculo original.

        La cardinalidad es de una empresa por piloto y está impuesta por un índice único en base de datos. La SPEC 03 no ofrece forma de deshacer la vinculación: no hay salida voluntaria ni expulsión, de modo que un código tecleado mal que acierte el de otra empresa solo se corrige a mano en base. Conviene que el cliente pida confirmación antes de enviar.

        La respuesta solo trae un mensaje, con data en null: no devuelve ni la empresa ni un token nuevo. Para refrescar los claims carrierId, carrierName y carrierCode, el cliente debe llamar después a GET /api/auth/check-status.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/JoinCarrierRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Carriers'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'El piloto ha quedado vinculado a la empresa transportista',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Te has vinculado a la empresa transportista correctamente'),
                        new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'El piloto ya pertenece a una empresa transportista y su vínculo original no cambia. El mensaje devuelto es: Ya perteneces a una empresa transportista',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es pilot. El mensaje devuelto por el middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El código tiene el formato correcto pero no pertenece a ninguna empresa, y no se crea ningún vínculo. El mensaje devuelto es: El código no pertenece a ninguna empresa transportista',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: falta el código, no es texto o no tiene exactamente 6 caracteres',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function join(JoinCarrierRequest $request, CarrierServiceInterface $carrierService)
    {
        try {
            $carrierService->joinCarrier($request->validated(), auth('api')->user());

            return ResponseHandler::success(null, 'Te has vinculado a la empresa transportista correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/carriers/me',
        operationId: 'meCarrier',
        summary: 'Obtener la empresa transportista propia',
        description: 'Devuelve la empresa del usuario autenticado, incluido su code, que es el que debe compartir con sus pilotos. Solo el rol carrier puede llamarlo (middlewares role:carrier y carrier.required), por lo que un carrier que todavía no ha creado su empresa recibe 403 del middleware antes de llegar al controller. La ruta está declarada antes del apiResource para que me no entre por el comodín {carrier}.',
        security: [['bearerAuth' => []]],
        tags: ['Carriers'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Empresa transportista obtenida correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Empresa transportista obtenida correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Carrier'),
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
                description: 'El rol del usuario autenticado no es carrier (mensaje: No tienes permisos para acceder a este recurso) o es un carrier que todavía no ha registrado su empresa (mensaje: Debes estar vinculado a un transportista para acceder a este recurso)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function me(Request $request, CarrierServiceInterface $carrierService)
    {
        try {
            $carrier = $carrierService->getMyCarrier(auth('api')->user());

            return ResponseHandler::success(new CarrierResource($carrier), 'Empresa transportista obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/carriers/me/pilots',
        operationId: 'pilotsCarrier',
        summary: 'Listar los pilotos de la empresa propia',
        description: 'Devuelve los pilotos vinculados a la empresa del usuario autenticado, con su fecha de unión; los pilotos de otras empresas nunca aparecen. Solo el rol carrier puede llamarlo (middlewares role:carrier y carrier.required). La paginación funciona igual que en GET /api/carriers: sin limit se devuelven todos los pilotos y el sobre NO trae total, currentPage ni lastPage; con un limit numérico se pagina, acotado al rango [10, 100], y esos tres campos salen aplanados en la raíz. No hay búsqueda, filtros ni ordenación.',
        security: [['bearerAuth' => []]],
        tags: ['Carriers'],
        parameters: [
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que activa la paginación. Si se omite, o si no es numérico (por ejemplo limit=abc), se devuelven todos los pilotos sin error y sin metadatos de paginación. Si es numérico se acota al rango [10, 100]: limit=1 devuelve páginas de 10 y limit=500 devuelve páginas de 100.',
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
                description: 'Pilotos obtenidos correctamente. Sin limit se devuelve CarrierPilotListResponse; con limit numérico, PaginatedCarrierPilotListResponse.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/CarrierPilotListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedCarrierPilotListResponse'),
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
                description: 'El rol del usuario autenticado no es carrier (mensaje: No tienes permisos para acceder a este recurso) o es un carrier que todavía no ha registrado su empresa (mensaje: Debes estar vinculado a un transportista para acceder a este recurso)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function pilots(Request $request, CarrierServiceInterface $carrierService)
    {
        try {
            $pilots = $carrierService->getMyPilots(auth('api')->user(), $this->limit($request));

            $data = $pilots instanceof LengthAwarePaginator
                ? new PaginatedResource($pilots, CarrierPilotResource::class)
                : CarrierPilotResource::collection($pilots);

            return ResponseHandler::success($data, 'Pilotos obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Read the page size requested on the query string.
     *
     * Whether it paginates or not is decided by the service; here it is only
     * normalized to a string, since anything else is not a valid limit.
     */
    private function limit(Request $request): ?string
    {
        $limit = $request->query('limit');

        return is_string($limit) ? $limit : null;
    }
}
