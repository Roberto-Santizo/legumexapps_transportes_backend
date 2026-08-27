<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\ShippingLine\StoreShippingLineRequest;
use App\Http\Requests\ShippingLine\UpdateShippingLineRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\ShippingLine\ShippingLineResource;
use App\Interfaces\ShippingLine\ShippingLineServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'ShippingLines',
    description: 'Navieras: el catálogo nacional de las líneas navieras con las que Legumex opera, con UN SOLO CAMPO DE NEGOCIO —el nombre—. Es el dominio más pequeño del proyecto: no hay código, ni sigla, ni contacto, ni teléfono, ni correo, ni país, ni web, ni notas, ni logo, ni estado de publicación. La naviera NO pertenece a ninguna empresa transportista, por eso NINGUNA ruta lleva el middleware carrier.required y el recurso no expone carrierId. Los cinco endpoints exigen token JWT (Authorization: Bearer {token}); sin él la respuesta es 401 con "El token de sesión no es válido o ha expirado". El reparto de permisos es asimétrico: la LECTURA (listado y detalle) está abierta a los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier que todavía no ha registrado su empresa; la ESCRITURA (alta, actualización y borrado) es exclusiva del administrator (middleware role:administrator) y los otros tres roles reciben 403 con "No tienes permisos para acceder a este recurso". ATENCIÓN — ESTE CATÁLOGO BORRA DE VERDAD Y ES EL AVISO CENTRAL DEL DOMINIO: en Products, Locations, Zones y Departure Points el DELETE es una baja lógica sobre un status booleano, la fila se queda, se sigue listando y se puede reactivar; aquí el DELETE es un soft delete real, la fila DESAPARECE del listado y del detalle, NO hay filtro que la devuelva, NO existe /restore y NO HAY FORMA DE RECUPERARLA POR LA API —solo desde la base de datos—. ATENCIÓN — EL BORRADO NO LIBERA EL NOMBRE: el índice único sigue ocupado para siempre por la fila borrada, así que un alta puede chocar con 400 contra una naviera que no aparece en ningún endpoint; de ahí el "que puede haber sido eliminada" del mensaje de duplicado, y aquí duele más que en Clients, donde quedaba un segundo identificador al que agarrarse: en este dominio el nombre es lo único que hay. No tiene NINGUNA RUTA FIJA antes del apiResource: no hay /toggle-status —una naviera no se pausa, se borra— ni /restore, así que el comodín {shippingLine} no captura nada. Reglas que atraviesan el dominio: el duplicado de nombre se responde con 400 DESDE EL SERVICE y NUNCA con 422 —el FormRequest no lleva regla unique, porque la de Laravel no ve las filas borradas—; el nombre se guarda recortado, con los espacios internos colapsados y EN MAYÚSCULAS; el registeredBy sale siempre del usuario autenticado y el PATCH no lo reescribe; el listado ordena por id ASC sin alternativa, filtra con un search tolerante sobre el nombre y pagina opt-in por limit acotado a [10, 100]; y createdAt, updatedAt y deletedAt usan el formato propio d-m-Y h:i:s A, NO ISO 8601. La lectura del detalle no distingue una naviera borrada de un id inexistente —las dos son 404—, mientras que el PATCH y el DELETE sí, con un 400 «La naviera ya fue eliminada». NADA CUELGA TODAVÍA DE UNA NAVIERA: ninguna tabla del proyecto tiene shipping_line_id, no hay navieras por destino ni tarifas por naviera, y no está relacionada con los puertos —los destinos de tipo port de Locations—, así que borrar una no rompe ninguna otra entidad.',
)]
class ShippingLineController extends Controller
{
    #[OA\Get(
        path: '/api/shipping-lines',
        operationId: 'indexShippingLines',
        summary: 'Listar navieras',
        description: <<<'TEXT'
        Devuelve las navieras del catálogo nacional con el nombre de quien las capturó. Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier sin empresa, porque la naviera es un dato de Legumex y no está acotada por transportista. No hay ámbito ni filtrado por empresa: todos los usuarios ven exactamente las mismas filas.

        ATENCIÓN — LAS NAVIERAS BORRADAS NO APARECEN NUNCA Y NO HAY FORMA DE VERLAS. Aquí no funciona la costumbre de los otros catálogos, donde lo dado de baja sigue en el listado con status false y se filtra con status=true: este listado excluye siempre las filas borradas y NO EXISTE NINGÚN PARÁMETRO —ni status, ni withTrashed, ni onlyTrashed— que las devuelva. Una naviera que desapareció del listado está borrada, y solo se recupera desde la base de datos. El total de la paginación tampoco las cuenta.

        El orden es fijo y no configurable: id ASC, es decir, el orden en que se dieron de alta. No hay sortBy ni sortDir.

        Los dos filtros —search y limit— se combinan entre sí y son TOLERANTES: un search en blanco o de solo espacios y un limit no numérico se ignoran en silencio y la lectura devuelve 200, NUNCA 422. Un filtro sin coincidencias devuelve 200 con data vacío, tampoco 404, igual que un catálogo vacío. No hay filtro por estado —no existe—, ni por autor del alta, ni por rango de fechas: cualquier otro query param se ignora.

        La forma de la respuesta depende del parámetro limit: sin limit se devuelven todos los registros y el sobre NO trae total, currentPage ni lastPage; con un limit numérico se devuelve el sobre paginado con esos tres campos APLANADOS EN LA RAÍZ, no bajo meta.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['ShippingLines'],
        parameters: [
            new OA\Parameter(
                name: 'search',
                description: 'Búsqueda parcial sobre el NOMBRE (LIKE %TERM%), que es el único campo del dominio: no hay ningún otro por el que buscar. El término se normaliza igual que un nombre —recorte, colapso de espacios y mayúsculas— y la columna está siempre en mayúsculas, así que la búsqueda es insensible a mayúsculas: search=maersk, search=MAERSK y search=Maersk funcionan igual. En blanco o de solo espacios se ignora y se devuelve el catálogo completo, nunca 422. NO alcanza a las navieras borradas, ni siquiera cuando el término coincidiría exactamente con su nombre.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'maersk'),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que activa la paginación. Si se omite, o si no es numérico (por ejemplo limit=abc), se devuelven todos los registros sin error y sin metadatos de paginación —que es lo que quiere un selector de naviera—. Si es numérico se ACOTA al rango [10, 100]: limit=1 devuelve páginas de 10 y limit=500 devuelve páginas de 100. Se combina con search.',
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
                description: 'Navieras obtenidas correctamente. Sin limit se devuelve ShippingLineListResponse; con limit numérico, PaginatedShippingLineListResponse, con total, currentPage y lastPage aplanados en la raíz del sobre. Un catálogo vacío o un search sin coincidencias devuelven 200 con data vacío, nunca 404. El deletedAt de cada elemento es siempre null: las borradas no se listan.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/ShippingLineListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedShippingLineListResponse'),
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
    public function index(Request $request, ShippingLineServiceInterface $shippingLineService)
    {
        try {
            $shippingLines = $shippingLineService->getShippingLines($this->filters($request));

            $data = $shippingLines instanceof LengthAwarePaginator
                ? new PaginatedResource($shippingLines, ShippingLineResource::class)
                : ShippingLineResource::collection($shippingLines);

            return ResponseHandler::success($data, 'Navieras obtenidas correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/shipping-lines',
        operationId: 'storeShippingLine',
        summary: 'Registrar una naviera',
        description: <<<'TEXT'
        Da de alta una naviera nacional. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403, aunque los tres sí puedan leer las navieras. No lleva carrier.required, porque la naviera no pertenece a ninguna empresa.

        El cuerpo acepta UN SOLO CAMPO, name, y es obligatorio. No hay sigla, ni contacto, ni teléfono, ni correo, ni país, ni web, ni notas, ni logo, ni status: es el alta más pequeña del proyecto. El registeredBy no se envía —se toma del usuario autenticado y se devuelve resuelto en registeredByName— y mandarlo en el cuerpo no tiene efecto.

        ATENCIÓN — NO HAY status: NO SE PUEDE CREAR UNA NAVIERA «INACTIVA» ni pausarla después. Este catálogo no tiene el booleano de publicación de Products, Locations, Zones y Departure Points; una naviera existe o está borrada, y el borrado no se deshace por la API.

        EL NOMBRE SE NORMALIZA: se recorta, se le COLAPSAN los espacios internos y se pasa a mayúsculas —"  maersk   line  " crea "MAERSK LINE"—. El cliente debe pintar el name de la respuesta, no el que tecleó el usuario.

        ATENCIÓN — EL DUPLICADO DE NOMBRE ES 400 DESDE EL SERVICE Y NUNCA 422, al revés que en Departure Points y Locations, donde el nombre repetido es 422. El campo no lleva regla unique a propósito: LA REGLA unique DE LARAVEL NO VE LAS FILAS BORRADAS, así que dejaría pasar un nombre ocupado por una naviera eliminada y el alta reventaría contra el índice único con un 500. Mensaje literal: «Ya existe una naviera con ese nombre, que puede haber sido eliminada».

        ATENCIÓN — EL BORRADO NO LIBERA EL NOMBRE. Una naviera eliminada lo sigue reservando para siempre, de modo que este 400 puede venir de una fila INVISIBLE EN TODOS LOS ENDPOINTS: no se lista, no se consulta por id y no hay parámetro que la muestre. El «que puede haber sido eliminada» del mensaje está precisamente para que el usuario no crea que el error miente. Reutilizar el nombre de una naviera borrada exige tocar la base de datos, y como el nombre es el único campo del dominio, no queda ninguna variante legítima a la que recurrir.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreShippingLineRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['ShippingLines'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Naviera registrada correctamente, con el name ya normalizado en mayúsculas, registeredByName el del administrador autenticado, createdAt y updatedAt con el formato d-m-Y h:i:s A y deletedAt en null.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Naviera registrada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/ShippingLine'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio incumplida, nunca un valor mal formado: el nombre ya está ocupado por otra naviera, VIVA O BORRADA. Mensaje literal: «Ya existe una naviera con ese nombre, que puede haber sido eliminada». ATENCIÓN — ES AQUÍ, Y NO EN EL 422, DONDE CAE EL DUPLICADO: el FormRequest no lleva regla unique porque la de Laravel no vería las filas borradas. La ocupante puede ser una naviera eliminada que no aparece en ningún endpoint.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí, aunque los tres sí puedan leer las navieras—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos, con el formato propio de Laravel {message, errors} y NO con el sobre {statusCode, message, data} del resto de la API. Casos posibles: falta el name, o llega vacío o de solo espacios —el recorte lo deja vacío antes de validarse— (El nombre de la naviera es obligatorio); no es texto (El nombre de la naviera debe ser texto); o supera los 255 caracteres (El nombre de la naviera no puede superar los 255 caracteres). ATENCIÓN — un nombre ya registrado NO cae aquí: es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreShippingLineRequest $request, ShippingLineServiceInterface $shippingLineService)
    {
        try {
            $shippingLine = $shippingLineService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new ShippingLineResource($shippingLine), 'Naviera registrada correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/shipping-lines/{shippingLine}',
        operationId: 'showShippingLine',
        summary: 'Obtener una naviera por id',
        description: <<<'TEXT'
        Devuelve una naviera concreta con el nombre del administrador que la capturó.

        Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles, incluido un carrier sin empresa. No hay ámbito por empresa, así que no existe el 403 por recurso ajeno que sí tienen Vehicles o Carriers: o el id existe y se devuelve, o es 404.

        ATENCIÓN — UNA NAVIERA BORRADA RESPONDE 404 CON EL MISMO MENSAJE QUE UN id INEXISTENTE, y es deliberado: quien lee no distingue «ya no está» de «nunca existió», porque distinguirlo revelaría qué ids llegaron a existir. La única operación que sí los diferencia es la escritura, que responde 400 «La naviera ya fue eliminada» sobre una fila borrada. Es lo contrario de Departure Points o Locations, donde una fila dada de baja se sigue consultando con 200.

        El deletedAt de esta respuesta es SIEMPRE null: por definición, aquí solo se alcanzan navieras vivas.

        No hay consulta por nombre: para buscar está el filtro search del listado.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['ShippingLines'],
        parameters: [
            new OA\Parameter(
                name: 'shippingLine',
                description: 'Identificador numérico de la naviera (shipping_lines.id). NO es su nombre —no existe consulta por nombre— y no se reutiliza: el id de una naviera borrada sigue ocupado en la base, pero ya no es alcanzable por esta ruta.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 7),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Naviera obtenida correctamente. Siempre una naviera viva, con el name en mayúsculas, deletedAt en null y las dos fechas con el formato d-m-Y h:i:s A, no ISO 8601.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Naviera obtenida correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/ShippingLine'),
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
                description: 'No existe ninguna naviera alcanzable con ese id. ATENCIÓN — AQUÍ CAEN DOS CASOS INDISTINGUIBLES A PROPÓSITO: el id que nunca existió y el de la naviera que fue borrada. El mensaje es el mismo en ambos: La naviera no existe. Si se necesita saber si la fila estuvo ahí, el PATCH y el DELETE responden 400 «La naviera ya fue eliminada» sobre una borrada.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $shippingLine, ShippingLineServiceInterface $shippingLineService)
    {
        try {
            $found = $shippingLineService->getShippingLineById($shippingLine);

            return ResponseHandler::success(new ShippingLineResource($found), 'Naviera obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/shipping-lines/{shippingLine}',
        operationId: 'updateShippingLine',
        summary: 'Actualizar una naviera',
        description: <<<'TEXT'
        Corrige el nombre de una naviera, que es lo único que hay que corregir. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403. La ruta acepta PATCH y PUT indistintamente y en ambos casos el comportamiento es el mismo: el PUT no reemplaza el recurso completo.

        El único campo es opcional y solo se toca lo que venga. Un CUERPO VACÍO responde 200 como no-op, devolviendo la naviera sin cambios, en vez de 422. Opcional no es vaciable: enviar el campo vacío —o de solo espacios— es 422, y no acepta null.

        No hay nada más que editar: este catálogo no tiene status, así que aquí no se pausa ni se reactiva nada, y NO EXISTE FORMA DE RESTAURAR UNA NAVIERA BORRADA —ni con este PATCH ni con ningún otro endpoint—.

        ATENCIÓN — SOBRE UNA NAVIERA YA BORRADA ESTE ENDPOINT RESPONDE 400 «La naviera ya fue eliminada», NO 404. Es la asimetría deliberada del dominio: la lectura no distingue la borrada de la inexistente (las dos son 404), la escritura sí, porque quien edita necesita saber que la fila estuvo ahí. Un id que nunca existió sigue siendo 404 aquí.

        MISMA REGLA DE DUPLICADOS QUE EL ALTA, Y SIGUE SIENDO 400 Y NUNCA 422: el nombre de otra naviera —VIVA O BORRADA— responde «Ya existe una naviera con ese nombre, que puede haber sido eliminada». La comprobación IGNORA LA PROPIA FILA, así que reenviar el propio nombre responde 200 y no choca consigo mismo.

        El nombre se normaliza igual que en el alta: se recorta, se le colapsan los espacios internos y se pasa a mayúsculas.

        El registeredBy NO SE REESCRIBE: sigue apuntando a quien dio de alta la naviera, aunque la edite otro administrador, y mandarlo en el cuerpo se descarta sin error. El createdAt tampoco cambia; el updatedAt sí, salvo en el no-op del cuerpo vacío. No se guarda el valor anterior: no hay bitácora de cambios.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateShippingLineRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['ShippingLines'],
        parameters: [
            new OA\Parameter(
                name: 'shippingLine',
                description: 'Identificador numérico de la naviera (shipping_lines.id). Es también la fila que se ignora al revalidar la unicidad del nombre, de modo que reenviar el suyo propio no choca consigo misma. El id NO cambia al corregir el nombre: editar una naviera conserva su identidad y su historial.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 7),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Naviera actualizada correctamente. data trae la fila ya modificada, con el name normalizado en mayúsculas, el mismo registeredByName, el mismo createdAt, el updatedAt refrescado y deletedAt en null. Un cuerpo vacío también responde 200, con la naviera intacta.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Naviera actualizada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/ShippingLine'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Dos causas distintas, ambas de negocio. (1) LA NAVIERA YA FUE BORRADA: mensaje «La naviera ya fue eliminada». Una naviera borrada no se edita ni se restaura por la API. (2) El nombre enviado ya lo ocupa OTRA naviera, viva o borrada: «Ya existe una naviera con ese nombre, que puede haber sido eliminada». Reenviar el propio nombre de la naviera que se edita NO cae aquí: es 200.',
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
                description: 'No existe ninguna fila con ese id, ni viva ni borrada. El mensaje devuelto es: La naviera no existe. ATENCIÓN — una naviera BORRADA no cae aquí: la escritura sí distingue los dos casos y responde 400 «La naviera ya fue eliminada», al contrario que el GET del detalle, que devuelve 404 en ambos.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos, con el formato propio de Laravel {message, errors}. Casos posibles: se envía el name vacío o de solo espacios (El nombre de la naviera es obligatorio); no es texto (El nombre de la naviera debe ser texto); o supera los 255 caracteres (El nombre de la naviera no puede superar los 255 caracteres). Un cuerpo vacío NO produce 422, y un nombre ya registrado tampoco: es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateShippingLineRequest $request, int $shippingLine, ShippingLineServiceInterface $shippingLineService)
    {
        try {
            $updated = $shippingLineService->update($shippingLine, $request->validated());

            return ResponseHandler::success(new ShippingLineResource($updated), 'Naviera actualizada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/shipping-lines/{shippingLine}',
        operationId: 'destroyShippingLine',
        summary: 'Eliminar una naviera',
        description: <<<'TEXT'
        ATENCIÓN — ESTE DELETE BORRA DE VERDAD, Y ES LA OPERACIÓN MÁS PELIGROSA DEL DOMINIO. No se parece al DELETE de Products, Locations, Zones o Departure Points, que solo ponen un status booleano en false y dejan la fila listándose y reactivable. Aquí es un soft delete real: la naviera DESAPARECE de GET /api/shipping-lines y de GET /api/shipping-lines/{shippingLine} —que pasa a responder 404—, NO hay ningún filtro que la devuelva, NO existe endpoint /restore y NO HAY FORMA DE RECUPERARLA POR LA API. Deshacerlo exige tocar la base de datos.

        Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        ATENCIÓN — EL BORRADO NO LIBERA EL NOMBRE. El índice único sigue ocupado por la fila borrada para siempre, así que después de este DELETE NADIE PUEDE DAR DE ALTA OTRA NAVIERA CON ESE NOMBRE: el alta responde 400 «Ya existe una naviera con ese nombre, que puede haber sido eliminada» contra una fila que ya no se ve por ninguna vía. Es lo contrario de la placa de un Vehicle, que un vehículo inactivo sí libera, y aquí pesa más que en Clients: el nombre es el ÚNICO campo del dominio, así que no queda ningún otro identificador con el que volver a crearla. Borrar para volver a crear con los mismos datos NO FUNCIONA.

        NO ES IDEMPOTENTE, y esa es su segunda diferencia con los catálogos booleanos: el segundo DELETE sobre el mismo id responde 400 «La naviera ya fue eliminada», no 200. Un id que nunca existió responde 404 «La naviera no existe». Distinguirlos es intencionado —igual que en FreightRates—, para que repetir la llamada no parezca un éxito.

        LA RESPUESTA ES LA ÚNICA DEL DOMINIO CON deletedAt NO NULO: devuelve la fila que se acaba de borrar, con su marca de tiempo en el formato d-m-Y h:i:s A. Conviene guardarla, porque después ya no se puede volver a leer.

        Borrar una naviera NO ROMPE NADA en el resto de la API: ninguna tabla del proyecto tiene shipping_line_id, no hay navieras por destino ni tarifas por naviera, y tampoco está relacionada con los puertos —los destinos de tipo port de Locations—. El riesgo es la pérdida del dato, no una cascada.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['ShippingLines'],
        parameters: [
            new OA\Parameter(
                name: 'shippingLine',
                description: 'Identificador numérico de la naviera (shipping_lines.id) que se va a borrar. Debe estar viva: una ya borrada responde 400 y una inexistente 404. Tras el borrado, ese id deja de ser alcanzable por cualquier endpoint, aunque la fila siga en la base reservando su nombre.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 7),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Naviera eliminada correctamente. data trae la fila recién borrada, y es la ÚNICA respuesta del dominio en la que deletedAt viene con valor —con el formato d-m-Y h:i:s A— en vez de null. A partir de aquí la naviera ya no se lista ni se consulta por id, y su nombre queda ocupado para siempre.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Naviera eliminada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/ShippingLine'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'La naviera ya fue borrada antes: este DELETE NO ES IDEMPOTENTE y el segundo intento no responde 200. El mensaje devuelto es: La naviera ya fue eliminada. Es la respuesta que distingue «ya no está» de «nunca existió», que sería 404.',
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
                description: 'No existe ninguna fila con ese id, ni viva ni borrada. El mensaje devuelto es: La naviera no existe. Una naviera ya borrada NO cae aquí: es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $shippingLine, ShippingLineServiceInterface $shippingLineService)
    {
        try {
            $deleted = $shippingLineService->destroy($shippingLine);

            return ResponseHandler::success(new ShippingLineResource($deleted), 'Naviera eliminada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the listing filters the service understands.
     *
     * @return array{search: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'search' => $this->queryString($request, 'search'),
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
