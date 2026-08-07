<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\Product\ProductResource;
use App\Interfaces\Product\ProductServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Products',
    description: 'Catálogo nacional de productos: la mercancía que se transporta. El producto NO pertenece a ninguna empresa transportista —es un dato de Legumex, común a todos los transportistas—, por eso NINGUNA ruta lleva el middleware carrier.required y el recurso no expone carrierId. Los seis endpoints exigen token JWT (Authorization: Bearer {token}), pero el reparto de permisos es asimétrico: la LECTURA (listado y detalle) está abierta a los cuatro roles —administrator, carrier, pilot y manager—, incluso a un carrier que todavía no ha registrado su empresa; la ESCRITURA (alta, actualización, toggle de estado y baja) es exclusiva del administrator (middleware role:administrator) y los otros tres roles reciben 403. Dos reglas atraviesan todo el dominio: el name se guarda SIEMPRE normalizado y en MAYÚSCULAS, con unicidad global insensible a mayúsculas, y el status es un BOOLEANO cuya baja es LÓGICA —DELETE pone status en false, la fila nunca desaparece—, al contrario que el DELETE de FuelPrices, que borra de verdad.',
)]
class ProductController extends Controller
{
    #[OA\Get(
        path: '/api/products',
        operationId: 'indexProducts',
        summary: 'Listar productos',
        description: <<<'TEXT'
        Devuelve el catálogo de productos. Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier sin empresa, porque el producto es un dato nacional y no está acotado por transportista. No hay ámbito ni filtrado por empresa: todos los usuarios ven exactamente las mismas filas.

        ATENCIÓN — el listado devuelve por defecto ACTIVOS E INACTIVOS mezclados. La baja de un producto es lógica, así que lo dado de baja sigue apareciendo aquí con status false; para quedarse solo con lo disponible hay que enviar status=true. Es intencionado: un catálogo de administración necesita ver lo que dio de baja para poder reactivarlo.

        El orden es fijo y no configurable: id ASC, es decir, el orden en que se dieron de alta. No hay sortBy ni sortDir.

        Los filtros son tolerantes: un status que no resuelve a booleano, un search en blanco y un limit no numérico se ignoran en silencio y la lectura devuelve 200, nunca 422. Un filtro sin coincidencias devuelve 200 con data vacío, tampoco 404. No hay filtro por rango de fechas ni por autor del alta.

        La forma de la respuesta depende del parámetro limit: sin limit se devuelven todos los registros y el sobre NO trae total, currentPage ni lastPage; con un limit numérico se devuelve el sobre paginado con esos tres campos aplanados en la raíz, no bajo meta.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Products'],
        parameters: [
            new OA\Parameter(
                name: 'status',
                description: 'Filtra por disponibilidad. El valor se interpreta con filter_var, así que los valores admitidos son true, false, 1 y 0 —status=1 devuelve solo los activos y status=false solo los dados de baja—. Cualquier valor que filter_var no resuelva a booleano (por ejemplo status=quizas) se ignora sin error y se devuelven ambos estados, igual que si se omitiera: este filtro nunca provoca un 422.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['true', 'false', '1', '0'], example: 'true'),
            ),
            new OA\Parameter(
                name: 'search',
                description: 'Búsqueda parcial sobre el nombre (LIKE %TERM%). El término se normaliza a mayúsculas antes de comparar y el name está siempre en mayúsculas, así que la búsqueda es insensible a mayúsculas: search=broc devuelve BROCOLI. En blanco se ignora y se devuelve el catálogo entero. Solo busca por nombre: no hay coincidencia exacta ni búsqueda por autor del alta.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'broc'),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que activa la paginación. Si se omite, o si no es numérico (por ejemplo limit=abc), se devuelven todos los registros sin error y sin metadatos de paginación —que es lo que quiere un selector—. Si es numérico se acota al rango [10, 100]: limit=3 devuelve páginas de 10 y limit=500 devuelve páginas de 100. Se combina con status y con search.',
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
                description: 'Productos obtenidos correctamente. Sin limit se devuelve ProductListResponse; con limit numérico, PaginatedProductListResponse. Un catálogo vacío o un filtro sin coincidencias devuelven 200 con data vacío, nunca 404.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/ProductListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedProductListResponse'),
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function index(Request $request, ProductServiceInterface $productService)
    {
        try {
            $products = $productService->getProducts($this->filters($request));

            $data = $products instanceof LengthAwarePaginator
                ? new PaginatedResource($products, ProductResource::class)
                : ProductResource::collection($products);

            return ResponseHandler::success($data, 'Productos obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/products',
        operationId: 'storeProduct',
        summary: 'Registrar un producto',
        description: <<<'TEXT'
        Da de alta un producto en el catálogo nacional. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403, aunque los tres sí puedan leer el catálogo. No lleva carrier.required, porque el producto no pertenece a ninguna empresa.

        El cuerpo solo acepta name. El status no se envía —el producto nace siempre en true— y el registeredBy tampoco: se toma del usuario autenticado y se devuelve resuelto en registeredByName. Mandar cualquiera de los dos en el cuerpo no tiene efecto.

        ATENCIÓN — el name se NORMALIZA: se recorta, se colapsan los espacios internos y se pasa a mayúsculas. Enviar "brocoli" devuelve un producto llamado "BROCOLI", y "  mini   zanahoria  " devuelve "MINI ZANAHORIA". El cliente debe pintar el name de la respuesta, no el que tecleó el usuario.

        La unicidad del nombre es GLOBAL e insensible a mayúsculas, porque se compara ya normalizado: enviar "brocoli" existiendo "BROCOLI" devuelve 422, no 201 ni 500.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreProductRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Products'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Producto registrado correctamente, con status true, el name ya normalizado en mayúsculas y registeredByName el del administrador autenticado.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Producto registrado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
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
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí, aunque los tres sí puedan leer el catálogo—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: falta el name, va vacío, supera los 255 caracteres, o ya existe otro producto con ese nombre una vez normalizado —incluido el caso de mandar "brocoli" existiendo "BROCOLI"— (mensajes: El nombre del producto es obligatorio / El nombre del producto debe ser texto / El nombre del producto no puede superar los 255 caracteres / Ya existe un producto con ese nombre)',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreProductRequest $request, ProductServiceInterface $productService)
    {
        try {
            $product = $productService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new ProductResource($product), 'Producto registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/products/{product}',
        operationId: 'showProduct',
        summary: 'Obtener un producto por id',
        description: <<<'TEXT'
        Devuelve un producto concreto del catálogo, activo o dado de baja, con el nombre del administrador que lo capturó.

        Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles, incluido un carrier sin empresa. No hay ámbito por empresa, así que no existe el 403 por recurso ajeno que sí tienen Vehicles o Carriers: o el id existe y se devuelve, o es 404.

        Un producto con status false se obtiene con toda normalidad: la baja es lógica y no oculta la fila en ninguna lectura. El campo status es lo que distingue si está disponible.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Products'],
        parameters: [
            new OA\Parameter(
                name: 'product',
                description: 'Identificador numérico del producto (products.id). No es el nombre: no existe consulta por name, para eso está el filtro search del listado.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Producto obtenido correctamente. Puede estar activo o dado de baja: el campo status lo distingue.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Producto obtenido correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
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
                response: 404,
                description: 'No existe ninguna fila con ese id. Un producto dado de baja NO cae aquí: sigue existiendo y se devuelve con 200. El mensaje devuelto es: El producto no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $product, ProductServiceInterface $productService)
    {
        try {
            $found = $productService->getProductById($product);

            return ResponseHandler::success(new ProductResource($found), 'Producto obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/products/{product}',
        operationId: 'updateProduct',
        summary: 'Actualizar un producto',
        description: <<<'TEXT'
        Modifica el nombre, el estado o ambos de un producto. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403. La ruta acepta PATCH y PUT indistintamente y en ambos casos el comportamiento es el mismo: el PUT no reemplaza el recurso completo.

        El cuerpo acepta name, status o ambos, y solo se toca lo que venga: mandar solo status no altera el nombre y al revés. AL MENOS UNO debe estar presente —una regla required_without cruzada—, así que un cuerpo vacío devuelve 422 con "Debe enviar al menos el nombre o el estado".

        El name se NORMALIZA igual que en el alta —recorte, colapso de espacios y mayúsculas—: mandar "fresa" deja el producto como "FRESA". La unicidad global se revalida ignorando la propia fila, así que reenviar su mismo nombre responde 200, y usar el de otro producto responde 422.

        Con status: true este endpoint REACTIVA un producto dado de baja; con status: false lo da de baja, exactamente igual que el DELETE. El solapamiento con PATCH /api/products/{product}/toggle-status es deliberado: el toggle sirve al interruptor de una tabla y este cuerpo al formulario de edición que manda estado y nombre juntos.

        El registeredBy no se reescribe: sigue apuntando a quien dio de alta el producto, aunque lo edite otro administrador. El createdAt tampoco cambia; el updatedAt sí.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateProductRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Products'],
        parameters: [
            new OA\Parameter(
                name: 'product',
                description: 'Identificador numérico del producto (products.id). Es también la fila que se ignora al revalidar la unicidad del nombre.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Producto actualizado correctamente. data trae la fila ya modificada, con el name normalizado en mayúsculas, el mismo registeredByName, el mismo createdAt y el updatedAt refrescado.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Producto actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
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
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ninguna fila con ese id. Un producto dado de baja NO cae aquí: se puede editar y reactivar con normalidad. El mensaje devuelto es: El producto no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'El cuerpo va vacío (mensaje: Debe enviar al menos el nombre o el estado, devuelto en los dos campos), el name supera los 255 caracteres o ya lo usa otro producto (Ya existe un producto con ese nombre), o el status no es booleano (El estado debe ser verdadero o falso)',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateProductRequest $request, int $product, ProductServiceInterface $productService)
    {
        try {
            $updated = $productService->update($product, $request->validated());

            return ResponseHandler::success(new ProductResource($updated), 'Producto actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/products/{product}/toggle-status',
        operationId: 'toggleStatusProduct',
        summary: 'Invertir el estado de un producto',
        description: <<<'TEXT'
        Invierte el status actual del producto: true pasa a false y false pasa a true. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        NO LLEVA CUERPO. Enviarlo no cambia nada: la operación no recibe datos porque el nuevo estado se deduce del actual. Es lo que necesita el interruptor de una tabla de administración, que no tiene por qué saber el estado en el que está la fila.

        NO es idempotente por diseño: dos llamadas seguidas devuelven el producto a su estado inicial. Si lo que se quiere es fijar un estado concreto sin depender del actual, usa PATCH /api/products/{product} con status, o DELETE para dar de baja.

        Es la vía de REACTIVACIÓN de un producto dado de baja, junto con PATCH y status: true. La baja aquí nunca es definitiva.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Products'],
        parameters: [
            new OA\Parameter(
                name: 'product',
                description: 'Identificador numérico del producto (products.id), activo o dado de baja: los dos casos son válidos y el resultado es el estado contrario.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Estado del producto actualizado correctamente. data trae la fila con el status ya invertido, para que el cliente pinte el interruptor sin releer el catálogo.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Estado del producto actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
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
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ninguna fila con ese id. El mensaje devuelto es: El producto no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function toggleStatus(int $product, ProductServiceInterface $productService)
    {
        try {
            $toggled = $productService->toggleStatus($product);

            return ResponseHandler::success(new ProductResource($toggled), 'Estado del producto actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/products/{product}',
        operationId: 'destroyProduct',
        summary: 'Dar de baja un producto',
        description: <<<'TEXT'
        ATENCIÓN — este DELETE NO BORRA NADA: es una BAJA LÓGICA que se limita a poner status en false. La fila sigue existiendo en la base, GET /api/products/{product} la sigue devolviendo con 200 y sigue apareciendo en GET /api/products sin filtros —para excluirla hay que pedir status=true—. Es lo contrario del DELETE de FuelPrices, que borra de verdad y hace desaparecer el id. El motivo es que un producto que aparezca en un histórico de viajes futuro no debe poder desaparecer.

        Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        Es IDEMPOTENTE: repetir la llamada sobre un producto ya dado de baja responde 200 y lo deja igual, no 400 ni 404. Dar de baja algo que ya lo está no es un error del cliente, porque el resultado que pidió ya se cumple.

        Es REVERSIBLE: el producto se reactiva con PATCH /api/products/{product}/toggle-status o con PATCH /api/products/{product} y status: true. No existe borrado real por ninguna vía.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Products'],
        parameters: [
            new OA\Parameter(
                name: 'product',
                description: 'Identificador numérico del producto (products.id). Sigue siendo válido después de la baja: el id nunca desaparece.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Producto dado de baja correctamente. data devuelve la fila ya con status false; la fila sigue existiendo, sigue siendo consultable por id y sigue apareciendo en el listado sin filtros.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Producto dado de baja correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
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
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ninguna fila con ese id. Un producto ya dado de baja NO cae aquí: al ser baja lógica la fila sigue existiendo y repetir el DELETE devuelve 200. El mensaje devuelto es: El producto no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $product, ProductServiceInterface $productService)
    {
        try {
            $deleted = $productService->destroy($product);

            return ResponseHandler::success(new ProductResource($deleted), 'Producto dado de baja correctamente', 200);
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
     * @return array{status: string|null, search: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'status' => $this->queryString($request, 'status'),
            'search' => $this->queryString($request, 'search'),
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
