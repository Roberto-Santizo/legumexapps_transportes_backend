<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\FuelPrice\CurrentFuelPriceRequest;
use App\Http\Requests\FuelPrice\StoreFuelPriceRequest;
use App\Http\Requests\FuelPrice\UpdateFuelPriceRequest;
use App\Http\Resources\FuelPrice\FuelPriceResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\FuelPrice\FuelPriceServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'FuelPrices',
    description: 'Catálogo nacional de precios de combustible. El precio NO pertenece a ninguna empresa: es un dato único para toda la aplicación, por eso ninguna ruta lleva el middleware carrier.required. Los siete endpoints exigen token JWT (Authorization: Bearer {token}), pero el reparto de permisos es asimétrico: la LECTURA (listado, precio vigente y detalle) está abierta a los cuatro roles —administrator, carrier, pilot y manager—, incluso a un carrier que todavía no ha registrado su empresa; la ESCRITURA (alta, corrección, desactivación y borrado) es exclusiva del administrator (middleware role:administrator) y los otros tres roles reciben 403. Solo hay un precio active por tipo de combustible, el importe va siempre EN QUETZALES (GTQ) POR GALÓN y el histórico, una vez desplazado, es intocable.',
)]
class FuelPriceController extends Controller
{
    #[OA\Get(
        path: '/api/fuel-prices',
        operationId: 'indexFuelPrices',
        summary: 'Listar precios de combustible',
        description: <<<'TEXT'
        Devuelve el catálogo de precios de combustible. Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles, incluido un carrier sin empresa, porque el precio es un dato nacional y no está acotado por transportista. No hay ámbito ni filtrado por empresa: todos los usuarios ven exactamente las mismas filas.

        ATENCIÓN — el listado devuelve por defecto AMBOS estados, así que mezcla el precio vigente de cada tipo con todo su histórico ya desplazado. Un cliente que pinte "precios actuales" sin filtrar mostrará precios caducados como si estuvieran en vigor: para quedarse solo con los vigentes hay que enviar status=active, y para el vigente de un tipo concreto es mejor GET /api/fuel-prices/current.

        El orden es fijo y no configurable: created_at DESC y, como desempate de las altas del mismo instante, id DESC. Lo más reciente va primero, de modo que el precio vigente de un tipo es siempre el primer elemento de ese tipo.

        Los filtros son tolerantes: un fuelType o un status que no pertenecen a su enum, y un limit no numérico, se ignoran en silencio y la lectura devuelve 200, nunca 422. No hay búsqueda por texto ni filtro por rango de fechas.

        La forma de la respuesta depende del parámetro limit: sin limit se devuelven todos los registros y el sobre NO trae total, currentPage ni lastPage; con un limit numérico se devuelve el sobre paginado con esos tres campos aplanados en la raíz, no bajo meta.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['FuelPrices'],
        parameters: [
            new OA\Parameter(
                name: 'fuelType',
                description: 'Filtra por tipo de combustible. Solo se aplica si el valor pertenece al enum; cualquier otro valor (por ejemplo fuelType=gasolina) se ignora sin error y se devuelven todos los tipos. Aquí es opcional, al contrario que en GET /api/fuel-prices/current, donde es obligatorio.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['regular', 'premium', 'diesel', 'diesel_premium'], example: 'diesel'),
            ),
            new OA\Parameter(
                name: 'status',
                description: 'Filtra por estado. Con status=active se obtiene, como mucho, un precio por tipo de combustible: el vigente. Con status=inactive se obtiene solo el histórico. Un valor fuera del enum se ignora sin error y se devuelven ambos estados, igual que si se omitiera.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'], example: 'active'),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que activa la paginación. Si se omite, o si no es numérico (por ejemplo limit=abc), se devuelven todos los registros sin error y sin metadatos de paginación. Si es numérico se acota al rango [10, 100]: limit=3 devuelve páginas de 10 y limit=500 devuelve páginas de 100. Se combina con fuelType y con status.',
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
                description: 'Precios de combustible obtenidos correctamente. Sin limit se devuelve FuelPriceListResponse; con limit numérico, PaginatedFuelPriceListResponse. Un catálogo vacío o un filtro sin coincidencias devuelven 200 con data vacío, nunca 404.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/FuelPriceListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedFuelPriceListResponse'),
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
    public function index(Request $request, FuelPriceServiceInterface $fuelPriceService)
    {
        try {
            $fuelPrices = $fuelPriceService->getFuelPrices($this->filters($request));

            $data = $fuelPrices instanceof LengthAwarePaginator
                ? new PaginatedResource($fuelPrices, FuelPriceResource::class)
                : FuelPriceResource::collection($fuelPrices);

            return ResponseHandler::success($data, 'Precios de combustible obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/fuel-prices/current',
        operationId: 'currentFuelPrice',
        summary: 'Obtener el precio vigente de un tipo de combustible',
        description: <<<'TEXT'
        Devuelve la única fila con status active del tipo de combustible indicado en la query. Es el endpoint que debe usar cualquier pantalla que muestre "el precio de hoy": evita tener que listar el catálogo y quedarse con el primer elemento.

        Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles, incluido un carrier sin empresa.

        La ruta está declarada ANTES del recurso, así que /current nunca se confunde con el id de un precio: no existe ningún precio cuyo detalle se sirva en esta URL.

        El fuelType es OBLIGATORIO y viaja en la query string. Ausente o fuera del enum devuelve 422, no 404 ni el precio de otro tipo.

        Devuelve 404 cuando el tipo es válido pero no tiene ninguna fila active. Eso ocurre antes del primer alta del tipo y TAMBIÉN después de desactivar o eliminar su precio vigente: ninguna fila del histórico asciende para reemplazarlo. El tipo se queda sin vigente hasta el próximo POST /api/fuel-prices, así que el cliente debe tratar el 404 como "sin precio publicado" y no como un error de la petición.

        El importe devuelto va EN QUETZALES (GTQ) POR GALÓN, como cadena con dos decimales, y su createdAt es la fecha desde la que rige.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['FuelPrices'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/currentFuelTypeQuery'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Precio vigente obtenido correctamente. El status del recurso devuelto es siempre active.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Precio vigente obtenido correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FuelPrice'),
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
                description: 'El tipo de combustible es válido pero no tiene ningún precio vigente, porque nunca se registró uno o porque el último se desactivó o se eliminó. El mensaje devuelto es: No existe un precio vigente para el combustible indicado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'El fuelType falta en la query o no pertenece al enum (mensajes: El tipo de combustible es obligatorio / El tipo de combustible no es válido)',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function current(CurrentFuelPriceRequest $request, FuelPriceServiceInterface $fuelPriceService)
    {
        try {
            $fuelPrice = $fuelPriceService->getCurrentByType($request->validated()['fuelType']);

            return ResponseHandler::success(new FuelPriceResource($fuelPrice), 'Precio vigente obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/fuel-prices',
        operationId: 'storeFuelPrice',
        summary: 'Registrar un precio de combustible',
        description: <<<'TEXT'
        Publica el precio vigente de un tipo de combustible. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403. No lleva carrier.required, porque el precio no pertenece a ninguna empresa.

        ATENCIÓN — este endpoint no solo crea una fila: dentro de la MISMA TRANSACCIÓN desactiva el precio que estaba active de ese mismo fuelType, poniéndolo en inactive. Los otros tipos de combustible no se tocan. Al terminar, el tipo tiene exactamente un vigente —el recién creado— y el anterior queda como histórico de solo lectura. Si algo falla, no se aplica ninguna de las dos partes: el tipo nunca se queda con cero ni con dos vigentes.

        Esta es la única forma de cambiar el precio de mercado de un combustible, y la única que deja rastro. PATCH /api/fuel-prices/{fuelPrice} NO sirve para eso: corrige el importe de la fila vigente en sitio y borra el valor anterior sin dejar histórico.

        El cuerpo solo acepta fuelType y price. El status no se envía —el precio nace siempre en active— y el registeredBy tampoco: se toma del usuario autenticado y se devuelve resuelto en registeredByName.

        No hay regla de unicidad ni ventana mínima entre altas: registrar dos veces el mismo tipo devuelve 201 las dos veces. El createdAt de la fila nueva es su fecha de vigencia; no existe una columna effective_date porque sería idéntica, así que no se puede programar un precio con fecha futura ni corregir la fecha de vigencia a posteriori.

        El importe va EN QUETZALES (GTQ) POR GALÓN.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreFuelPriceRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['FuelPrices'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Precio de combustible registrado correctamente, con status active y registeredByName el del administrador autenticado. El precio vigente anterior de ese mismo tipo, si lo había, ha quedado en inactive: un cliente que tuviera cacheado el catálogo debe releerlo.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Precio de combustible registrado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FuelPrice'),
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
                description: 'Datos inválidos: falta el fuelType o el price, el fuelType no pertenece al enum, o el price no es numérico, es menor que 0.01 o supera 999999.99',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreFuelPriceRequest $request, FuelPriceServiceInterface $fuelPriceService)
    {
        try {
            $fuelPrice = $fuelPriceService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new FuelPriceResource($fuelPrice), 'Precio de combustible registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/fuel-prices/{fuelPrice}',
        operationId: 'showFuelPrice',
        summary: 'Obtener un precio de combustible por id',
        description: <<<'TEXT'
        Devuelve un precio concreto del catálogo, vigente o histórico, con el nombre del administrador que lo capturó.

        Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles, incluido un carrier sin empresa. No hay ámbito por empresa, así que no existe el 403 por recurso ajeno que sí tienen Vehicles o Carriers: o el id existe y se devuelve, o es 404.

        Una fila inactive se obtiene con toda normalidad: solo las ESCRITURAS distinguen entre vigente e histórico. Para saber cuál es el precio en vigor de un tipo, mira su campo status o usa GET /api/fuel-prices/current.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['FuelPrices'],
        parameters: [
            new OA\Parameter(
                name: 'fuelPrice',
                description: 'Identificador numérico de la fila del catálogo (fuel_prices.id). No es el tipo de combustible: para consultar por tipo está GET /api/fuel-prices/current.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Precio de combustible obtenido correctamente. Puede ser el vigente o una fila del histórico: el campo status lo distingue.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Precio de combustible obtenido correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FuelPrice'),
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
                description: 'No existe ninguna fila con ese id, o existía y se eliminó con DELETE, que en este dominio es un borrado real. El mensaje devuelto es: El precio de combustible no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $fuelPrice, FuelPriceServiceInterface $fuelPriceService)
    {
        try {
            $found = $fuelPriceService->getFuelPriceById($fuelPrice);

            return ResponseHandler::success(new FuelPriceResource($found), 'Precio de combustible obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/fuel-prices/{fuelPrice}',
        operationId: 'updateFuelPrice',
        summary: 'Corregir el importe del precio vigente',
        description: <<<'TEXT'
        Corrige el importe de un precio de combustible. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403. La ruta acepta PATCH y PUT indistintamente y en ambos casos el comportamiento es el mismo.

        El cuerpo acepta ÚNICAMENTE price, y es obligatorio: un cuerpo vacío devuelve 422. El fuelType no se puede cambiar —dejaría dos vigentes en el tipo destino— y el status tampoco —para eso está PATCH /api/fuel-prices/{fuelPrice}/deactivate—; enviarlos se descarta sin error.

        ATENCIÓN — el histórico es intocable. Solo se puede actualizar la fila que está active: sobre una inactive la respuesta es 400 con el mensaje "Solo se puede modificar el precio vigente", y no hay ninguna forma de editar ni de reactivar un precio ya desplazado.

        ATENCIÓN — esto NO es la forma de registrar un cambio de precio de mercado. La corrección se aplica en sitio: sobrescribe el importe de la fila vigente, no crea una fila nueva y no deja rastro del valor anterior, así que se pierde el dato de a cuánto estuvo el combustible hasta ese momento. Su uso legítimo es arreglar una captura equivocada. Para publicar un precio nuevo usa POST /api/fuel-prices, que archiva el anterior.

        El createdAt no se modifica: la fila conserva su fecha de vigencia original aunque su importe cambie.

        El importe va EN QUETZALES (GTQ) POR GALÓN.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateFuelPriceRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['FuelPrices'],
        parameters: [
            new OA\Parameter(
                name: 'fuelPrice',
                description: 'Identificador numérico de la fila del catálogo (fuel_prices.id). Debe ser el de la fila active de su tipo: cualquier otra devuelve 400.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Precio de combustible actualizado correctamente. data trae la fila con el importe nuevo, el mismo fuelType, el mismo status active y el mismo createdAt.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Precio de combustible actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FuelPrice'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'La fila existe pero su status es inactive: es histórico y no se puede modificar ni reactivar. No se guarda ningún cambio. El mensaje devuelto es: Solo se puede modificar el precio vigente',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
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
                description: 'No existe ninguna fila con ese id. El mensaje devuelto es: El precio de combustible no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'El cuerpo va vacío o el price no es numérico, es menor que 0.01 o supera 999999.99 (mensajes: El precio es obligatorio / El precio debe ser un número en quetzales por galón / El precio debe ser mayor que cero / El precio no puede superar los 999999.99 quetzales por galón)',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    #[OA\Put(
        path: '/api/fuel-prices/{fuelPrice}',
        operationId: 'replaceFuelPrice',
        summary: 'Corregir el importe del precio vigente (alias PUT)',
        description: 'Mismo comportamiento que PATCH /api/fuel-prices/{fuelPrice}: la ruta admite ambos verbos y en los dos el cuerpo acepta únicamente price, que es obligatorio, sin que el PUT reemplace el recurso completo. Consulta esa operación para el detalle de permisos, del 400 sobre el histórico y de por qué esto no sustituye a POST /api/fuel-prices para publicar un precio nuevo.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateFuelPriceRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['FuelPrices'],
        parameters: [
            new OA\Parameter(
                name: 'fuelPrice',
                description: 'Identificador numérico de la fila del catálogo (fuel_prices.id). Debe ser el de la fila active de su tipo: cualquier otra devuelve 400.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Precio de combustible actualizado correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Precio de combustible actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FuelPrice'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'La fila existe pero su status es inactive: es histórico y no se puede modificar ni reactivar. El mensaje devuelto es: Solo se puede modificar el precio vigente',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es administrator. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ninguna fila con ese id. El mensaje devuelto es: El precio de combustible no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'El cuerpo va vacío o el price no es numérico, es menor que 0.01 o supera 999999.99',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateFuelPriceRequest $request, int $fuelPrice, FuelPriceServiceInterface $fuelPriceService)
    {
        try {
            $updated = $fuelPriceService->update($fuelPrice, $request->validated());

            return ResponseHandler::success(new FuelPriceResource($updated), 'Precio de combustible actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/fuel-prices/{fuelPrice}/deactivate',
        operationId: 'deactivateFuelPrice',
        summary: 'Retirar de vigencia el precio actual de un tipo',
        description: <<<'TEXT'
        Pasa a inactive el precio vigente de un tipo de combustible, sin publicar ninguno en su lugar. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403. No lleva cuerpo.

        ATENCIÓN — el tipo se queda SIN PRECIO VIGENTE. Ninguna fila del histórico asciende para reemplazarla, así que GET /api/fuel-prices/current?fuelType=... empezará a devolver 404 para ese combustible hasta que se registre un precio nuevo con POST /api/fuel-prices. Úsalo solo cuando de verdad no hay precio publicado; para sustituir un precio por otro basta con el POST, que archiva el anterior en la misma transacción.

        La operación es IRREVERSIBLE: no existe reactivación por ninguna vía. Una vez inactive, la fila es histórico de solo lectura y tanto este endpoint como PATCH y DELETE responden 400 sobre ella con el mensaje "Solo se puede modificar el precio vigente". Tampoco es idempotente: repetir la llamada devuelve 400, no 200.

        La fila no se borra ni desaparece de GET /api/fuel-prices: sigue apareciendo con status inactive.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['FuelPrices'],
        parameters: [
            new OA\Parameter(
                name: 'fuelPrice',
                description: 'Identificador numérico de la fila del catálogo (fuel_prices.id). Debe ser el de la fila active de su tipo: una fila ya inactive devuelve 400.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Precio de combustible desactivado correctamente. data devuelve la fila ya con status inactive; la fila sigue existiendo y sigue apareciendo en los listados. Ese tipo de combustible se queda sin precio vigente.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Precio de combustible desactivado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FuelPrice'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'La fila existe pero ya está en inactive: es histórico y no se puede volver a desactivar ni reactivar. El mensaje devuelto es: Solo se puede modificar el precio vigente',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
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
                description: 'No existe ninguna fila con ese id. El mensaje devuelto es: El precio de combustible no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function deactivate(int $fuelPrice, FuelPriceServiceInterface $fuelPriceService)
    {
        try {
            $deactivated = $fuelPriceService->deactivate($fuelPrice);

            return ResponseHandler::success(new FuelPriceResource($deactivated), 'Precio de combustible desactivado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/fuel-prices/{fuelPrice}',
        operationId: 'destroyFuelPrice',
        summary: 'Eliminar definitivamente el precio vigente',
        description: <<<'TEXT'
        ATENCIÓN — este endpoint BORRA DE VERDAD, a diferencia del DELETE de Vehicles, que solo desactiva. La fila desaparece de la base de datos y de los listados, el id deja de existir y GET /api/fuel-prices/{fuelPrice} pasa a devolver 404. La operación es irreversible y no hay papelera.

        Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        Solo opera sobre la fila active: sobre una inactive responde 400 con "Solo se puede modificar el precio vigente", así que el histórico no se puede eliminar por esta vía ni por ninguna otra. Es decir, el borrado real solo alcanza al precio en vigor.

        Igual que la desactivación, deja al tipo de combustible SIN PRECIO VIGENTE: ninguna fila del histórico asciende, y /current devolverá 404 para ese tipo hasta el próximo POST /api/fuel-prices.

        La diferencia con PATCH /api/fuel-prices/{fuelPrice}/deactivate es qué queda después: deactivate conserva la fila como histórico consultable, DELETE la hace desaparecer. Elige deactivate salvo que el precio no debiera haberse registrado nunca.

        El 200 devuelve en data el recurso YA ELIMINADO —el modelo en memoria sobrevive al borrado para poder decir qué se borró—, así que ese id ya no se puede volver a pedir.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['FuelPrices'],
        parameters: [
            new OA\Parameter(
                name: 'fuelPrice',
                description: 'Identificador numérico de la fila del catálogo (fuel_prices.id). Debe ser el de la fila active de su tipo: una fila inactive devuelve 400 y no se puede eliminar.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Precio de combustible eliminado correctamente. data es una foto del recurso que acaba de desaparecer: la fila ya NO existe, el id no se puede volver a consultar y ese tipo de combustible se queda sin precio vigente.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Precio de combustible eliminado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FuelPrice'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'La fila existe pero su status es inactive: el histórico no se puede eliminar. No se borra nada. El mensaje devuelto es: Solo se puede modificar el precio vigente',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
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
                description: 'No existe ninguna fila con ese id, porque nunca existió o porque ya se eliminó con una llamada anterior: el borrado es real, así que repetir el DELETE devuelve 404 y no 200. El mensaje devuelto es: El precio de combustible no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $fuelPrice, FuelPriceServiceInterface $fuelPriceService)
    {
        try {
            $deleted = $fuelPriceService->destroy($fuelPrice);

            return ResponseHandler::success(new FuelPriceResource($deleted), 'Precio de combustible eliminado correctamente', 200);
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
     * @return array{fuelType: string|null, status: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'fuelType' => $this->queryString($request, 'fuelType'),
            'status' => $this->queryString($request, 'status'),
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
