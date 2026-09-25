<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\FinishedProduct\StoreFinishedProductRequest;
use App\Http\Requests\FinishedProduct\UpdateFinishedProductRequest;
use App\Http\Resources\FinishedProduct\FinishedProductResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\FinishedProduct\FinishedProductServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Finished Products',
    description: 'Productos terminados (SPEC 36): catálogo nacional de SKUs —code único global, name, presentation, boxesPerPallet y un cliente obligatorio—. NO tiene relación con Products (SPEC 07). Ninguna ruta lleva carrier.required. Los cinco endpoints exigen token JWT (401 «El token de sesión no es válido o ha expirado»). LECTURA (listado y detalle) para todos los roles SALVO pilot —administrator, manager, carrier, export, user y shipment—; ESCRITURA (alta, actualización y borrado) solo administrator y export; el resto recibe 403 «No tienes permisos para acceder a este recurso». Borrado SoftDeletes real sin /restore: el borrado desaparece del listado y del detalle (404) y responde 400 «El producto terminado ya fue eliminado» en PATCH/DELETE. El code duplicado —incluso contra un borrado— es 400 desde el service, nunca 422. Borrar un cliente NO se bloquea por sus productos terminados, que conservan clientName.',
)]
class FinishedProductController extends Controller
{
    #[OA\Get(
        path: '/api/finished-products',
        operationId: 'indexFinishedProducts',
        summary: 'Listar productos terminados',
        description: <<<'TEXT'
        Devuelve los productos terminados vivos con el nombre de su cliente y de quien los registró. Todos los roles salvo pilot; sin ámbito por empresa: todos ven las mismas filas.

        Los borrados NO aparecen nunca y no hay parámetro que los devuelva. Orden fijo id ASC.

        Filtros TOLERANTES —nunca 422—: search (LIKE sobre code Y name, insensible a mayúsculas; en blanco se ignora), clientId (no numérico se ignora y devuelve el listado completo; un id sin SKUs devuelve data vacío) y limit. Sin coincidencias: 200 con data vacío.

        Sin limit (o no numérico) devuelve todo sin metadatos; con limit numérico pagina acotado a [10, 100] con total, currentPage y lastPage APLANADOS en la raíz del sobre.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Finished Products'],
        parameters: [
            new OA\Parameter(
                name: 'search',
                description: 'Búsqueda parcial sobre code y name a la vez (OR). El término se recorta y pasa a mayúsculas, así que es insensible a mayúsculas. En blanco se ignora.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'brócoli'),
            ),
            new OA\Parameter(
                name: 'clientId',
                description: 'Filtra por cliente (clients.id). Tolerante: un valor no entero (clientId=abc) se ignora en silencio, nunca 422. Un id inexistente devuelve data vacío, no 404.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 7),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página; su presencia activa la paginación. Omitido o no numérico: todos los registros sin metadatos. Numérico: acotado a [10, 100] (limit=1 → 10, limit=500 → 100).',
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
                description: 'Productos terminados obtenidos correctamente. Sin limit: FinishedProductListResponse; con limit numérico: PaginatedFinishedProductListResponse. deletedAt siempre null.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/FinishedProductListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedFinishedProductListResponse'),
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. Mensaje: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El usuario es pilot: es el único rol sin lectura. Mensaje: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function index(Request $request, FinishedProductServiceInterface $finishedProductService)
    {
        try {
            $finishedProducts = $finishedProductService->getFinishedProducts($this->filters($request));

            $data = $finishedProducts instanceof LengthAwarePaginator
                ? new PaginatedResource($finishedProducts, FinishedProductResource::class)
                : FinishedProductResource::collection($finishedProducts);

            return ResponseHandler::success($data, 'Productos terminados obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/finished-products',
        operationId: 'storeFinishedProduct',
        summary: 'Registrar un producto terminado',
        description: <<<'TEXT'
        Da de alta un SKU. Solo administrator y export (role:administrator,export); el resto de roles, 403.

        Los cinco campos son obligatorios. code se recorta y pasa a mayúsculas y no admite espacios (422); name solo pasa a mayúsculas y no es único. registeredBy sale del usuario autenticado.

        Orden de las guardas del service tras validar: primero el code duplicado (400, contra vivos Y borrados), después el cliente borrado (400). clientId inexistente lo corta antes el FormRequest con 422.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreFinishedProductRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Finished Products'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Producto terminado registrado correctamente, con code y name en mayúsculas, presentation/boxesPerPallet como string de dos decimales y deletedAt null.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Producto terminado registrado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FinishedProduct'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio. (1) «Ya existe un producto terminado con ese código, que puede haber sido eliminado» —el ocupante puede ser un SKU borrado invisible en todos los endpoints—; se comprueba primero. (2) «El cliente seleccionado ya fue eliminado».',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. Mensaje: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol no es administrator ni export. Mensaje: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Validación con el formato de Laravel {message, errors}. Casos: falta algún campo; code con más de 15 caracteres o con espacios (El código no puede contener espacios); name de más de 255; presentation o boxesPerPallet no numéricos, menores a 0.01 o mayores a 99999999.99; clientId no entero o inexistente (El cliente seleccionado no existe). El code duplicado NO cae aquí: es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreFinishedProductRequest $request, FinishedProductServiceInterface $finishedProductService)
    {
        try {
            $created = $finishedProductService->createFinishedProduct($request->validated(), auth('api')->user());

            return ResponseHandler::success(new FinishedProductResource($created), 'Producto terminado registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/finished-products/{finishedProduct}',
        operationId: 'showFinishedProduct',
        summary: 'Obtener un producto terminado por id',
        description: <<<'TEXT'
        Devuelve un SKU vivo. Todos los roles salvo pilot; sin ámbito por empresa.

        ATENCIÓN — un SKU BORRADO responde 404 con el MISMO mensaje que un id inexistente («El producto terminado no existe»). Solo la escritura distingue el borrado (400). deletedAt es siempre null aquí. clientName sale aunque el cliente haya sido borrado.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Finished Products'],
        parameters: [
            new OA\Parameter(
                name: 'finishedProduct',
                description: 'Id numérico del producto terminado (finished_products.id). NO es el code.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Producto terminado obtenido correctamente.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Producto terminado obtenido correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FinishedProduct'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. Mensaje: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El usuario es pilot. Mensaje: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'Id inexistente O producto terminado borrado, indistinguibles a propósito. Mensaje: El producto terminado no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $finishedProduct, FinishedProductServiceInterface $finishedProductService)
    {
        try {
            $found = $finishedProductService->getFinishedProductById($finishedProduct);

            return ResponseHandler::success(new FinishedProductResource($found), 'Producto terminado obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/finished-products/{finishedProduct}',
        operationId: 'updateFinishedProduct',
        summary: 'Actualizar un producto terminado',
        description: <<<'TEXT'
        Corrige cualquiera de los cinco campos. Solo administrator y export; el resto, 403. La ruta acepta PATCH y PUT con el mismo comportamiento parcial.

        Solo se toca lo que venga; un cuerpo vacío es un no-op con 200. Enviar un campo vacío o null es 422.

        Orden de las guardas del service: id inexistente 404 «El producto terminado no existe» → borrado 400 «El producto terminado ya fue eliminado» → code de OTRO SKU (vivo o borrado) 400 → cliente borrado 400 «El cliente seleccionado ya fue eliminado», INCLUSO si es el mismo cliente que ya tenía. Reenviar el propio code es 200. registeredBy no se reescribe.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateFinishedProductRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Finished Products'],
        parameters: [
            new OA\Parameter(
                name: 'finishedProduct',
                description: 'Id numérico del producto terminado (finished_products.id). Es también la fila que se ignora al revalidar la unicidad del code.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Producto terminado actualizado correctamente (también con cuerpo vacío, sin cambios).',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Producto terminado actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FinishedProduct'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio: «El producto terminado ya fue eliminado», «Ya existe un producto terminado con ese código, que puede haber sido eliminado» o «El cliente seleccionado ya fue eliminado».',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. Mensaje: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol no es administrator ni export. Mensaje: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ninguna fila con ese id, ni viva ni borrada. Mensaje: El producto terminado no existe. Un SKU borrado NO cae aquí: es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Validación con el formato de Laravel {message, errors}: campo enviado vacío o null, code con espacios o de más de 15 caracteres, name de más de 255, presentation/boxesPerPallet fuera de [0.01, 99999999.99] o no numéricos, clientId no entero o inexistente. Un cuerpo vacío NO es 422.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateFinishedProductRequest $request, int $finishedProduct, FinishedProductServiceInterface $finishedProductService)
    {
        try {
            $updated = $finishedProductService->updateFinishedProduct($finishedProduct, $request->validated());

            return ResponseHandler::success(new FinishedProductResource($updated), 'Producto terminado actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/finished-products/{finishedProduct}',
        operationId: 'destroyFinishedProduct',
        summary: 'Eliminar un producto terminado',
        description: <<<'TEXT'
        ATENCIÓN — BORRA DE VERDAD (SoftDeletes) y NO HAY /restore: el SKU desaparece del listado y del detalle (404) y solo se recupera desde la base de datos. Solo administrator y export; el resto, 403.

        El borrado NO LIBERA EL code: nadie puede volver a darlo de alta con ese código (400).

        NO es idempotente: el segundo DELETE responde 400 «El producto terminado ya fue eliminado»; un id que nunca existió, 404 «El producto terminado no existe».

        La respuesta es la ÚNICA en la que deletedAt viene con fecha (d-m-Y h:i:s A).
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Finished Products'],
        parameters: [
            new OA\Parameter(
                name: 'finishedProduct',
                description: 'Id numérico del producto terminado (finished_products.id) a borrar. Debe estar vivo.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Producto terminado eliminado correctamente. data trae la fila recién borrada con deletedAt con fecha.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Producto terminado eliminado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FinishedProduct'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'El producto terminado ya fue borrado antes. Mensaje: El producto terminado ya fue eliminado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. Mensaje: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol no es administrator ni export. Mensaje: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ninguna fila con ese id, ni viva ni borrada. Mensaje: El producto terminado no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $finishedProduct, FinishedProductServiceInterface $finishedProductService)
    {
        try {
            $deleted = $finishedProductService->deleteFinishedProduct($finishedProduct);

            return ResponseHandler::success(new FinishedProductResource($deleted), 'Producto terminado eliminado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the listing filters the service understands.
     *
     * @return array{search: string|null, clientId: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'search' => $this->queryString($request, 'search'),
            'clientId' => $this->queryString($request, 'clientId'),
            'limit' => $this->queryString($request, 'limit'),
        ];
    }

    /**
     * Read a query string parameter, keeping only actual strings.
     *
     * An array or a missing key degrades to null, which every filter treats as absent.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
