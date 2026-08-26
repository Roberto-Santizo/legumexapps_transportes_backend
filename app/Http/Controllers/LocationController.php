<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Location\StoreLocationRequest;
use App\Http\Requests\Location\UpdateLocationRequest;
use App\Http\Resources\Location\LocationResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\Location\LocationServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Locations',
    description: 'Destinos nacionales: puntos concretos del mapa —bodegas, centros de acopio, mercados— anclados a un lugar real de Google por su googlePlaceId. El destino NO pertenece a ninguna empresa transportista —es un dato de Legumex, común a todos—, por eso NINGUNA ruta lleva el middleware carrier.required y el recurso no expone carrierId. Los seis endpoints exigen token JWT (Authorization: Bearer {token}), pero el reparto de permisos es asimétrico: la LECTURA (listado y detalle) está abierta a los cuatro roles —administrator, carrier, pilot y manager—, incluso a un carrier que todavía no ha registrado su empresa; la ESCRITURA (alta, actualización, toggle de estado y baja) es exclusiva del administrator (middleware role:administrator) y los otros tres roles reciben 403. ES EL EJE DE LAS TARIFAS DE FLETE: cada FreightRate cuelga de un destino y GET /api/freight-rates/quote se cotiza mandando su id, no coordenadas. ATENCIÓN — LAS COORDENADAS NO INFLUYEN EN EL PRECIO: la tarifa depende del destino ELEGIDO, no de dónde esté el pin; de latitude y longitude no se deriva distancia, kilometraje ni ruta, y corregirlas no cambia ninguna cotización. Reglas que atraviesan el dominio: el name se guarda SIEMPRE normalizado y en MAYÚSCULAS, con unicidad global insensible a mayúsculas; el googlePlaceId se guarda TAL CUAL, es sensible a mayúsculas y también es único, pero su duplicado se responde con 400 —no con 422 como el del name—, nombrando al destino que ya lo ocupa; latitude y longitude salen como CADENAS de ocho decimales, no como números; createdAt y updatedAt usan el formato propio d-m-Y h:i:s A, NO ISO 8601; y el status es un BOOLEANO cuya baja es LÓGICA e idempotente —DELETE pone status en false, la fila nunca desaparece—, igual que en Products y Zones y al contrario que el DELETE de FuelPrices, que borra de verdad, o el de FreightRates, que es un soft delete no idempotente.',
)]
class LocationController extends Controller
{
    #[OA\Get(
        path: '/api/locations',
        operationId: 'indexLocations',
        summary: 'Listar destinos',
        description: <<<'TEXT'
        Devuelve los destinos con sus coordenadas y el nombre de quien los capturó. Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier sin empresa, porque el destino es un dato nacional y no está acotado por transportista. No hay ámbito ni filtrado por empresa: todos los usuarios ven exactamente las mismas filas.

        ATENCIÓN — el listado devuelve por defecto ACTIVOS E INACTIVOS mezclados. La baja de un destino es lógica, así que lo dado de baja sigue apareciendo aquí con status false; para quedarse solo con lo cotizable hay que enviar status=true. Es intencionado: una pantalla de administración necesita ver lo que dio de baja para poder reactivarlo.

        El orden es fijo y no configurable: id ASC, es decir, el orden en que se dieron de alta. No hay sortBy ni sortDir.

        Los tres filtros —status, search y limit— se combinan entre sí y son TOLERANTES: un status que no resuelve a booleano, un search en blanco y un limit no numérico se ignoran en silencio y la lectura devuelve 200, NUNCA 422. Un filtro sin coincidencias devuelve 200 con data vacío, tampoco 404. No hay búsqueda por googlePlaceId, ni por proximidad geográfica, ni filtro por autor del alta o por rango de fechas: cualquier otro query param se ignora.

        La forma de la respuesta depende del parámetro limit: sin limit se devuelven todos los registros y el sobre NO trae total, currentPage ni lastPage; con un limit numérico se devuelve el sobre paginado con esos tres campos APLANADOS EN LA RAÍZ, no bajo meta.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        parameters: [
            new OA\Parameter(
                name: 'status',
                description: 'Filtra por publicación. El valor se interpreta con filter_var, así que los valores admitidos son true, false, 1 y 0 —status=1 devuelve solo los activos y status=false solo los dados de baja—. Cualquier valor que filter_var no resuelva a booleano (por ejemplo status=quizas) se ignora sin error y se devuelven ambos estados, igual que si se omitiera: este filtro nunca provoca un 422.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['true', 'false', '1', '0'], example: 'true'),
            ),
            new OA\Parameter(
                name: 'search',
                description: 'Búsqueda parcial sobre el nombre (LIKE %TERM%). El término se normaliza igual que el name —recorte, colapso de espacios y mayúsculas— y el name está siempre en mayúsculas, así que la búsqueda es insensible a mayúsculas: search=bode y search=BODE devuelven ambas BODEGA CENTRAL ESCUINTLA. En blanco o solo espacios se ignora y se devuelven todos los destinos. Solo busca por nombre: NO cubre la descripción, ni el googlePlaceId, ni el autor del alta.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'bode'),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que activa la paginación. Si se omite, o si no es numérico (por ejemplo limit=abc), se devuelven todos los registros sin error y sin metadatos de paginación —que es lo que quiere un selector de destinos—. Si es numérico se ACOTA al rango [10, 100]: limit=5 devuelve páginas de 10 y limit=500 devuelve páginas de 100. Se combina con status y search.',
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
                description: 'Destinos obtenidos correctamente. Sin limit se devuelve LocationListResponse; con limit numérico, PaginatedLocationListResponse, con total, currentPage y lastPage aplanados en la raíz del sobre. Una tabla vacía o un filtro sin coincidencias devuelven 200 con data vacío, nunca 404.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/LocationListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedLocationListResponse'),
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
    public function index(Request $request, LocationServiceInterface $locationService)
    {
        try {
            $locations = $locationService->getLocations($this->filters($request));

            $data = $locations instanceof LengthAwarePaginator
                ? new PaginatedResource($locations, LocationResource::class)
                : LocationResource::collection($locations);

            return ResponseHandler::success($data, 'Destinos obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/locations',
        operationId: 'storeLocation',
        summary: 'Registrar un destino',
        description: <<<'TEXT'
        Da de alta un destino nacional. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403, aunque los tres sí puedan leer los destinos. No lleva carrier.required, porque el destino no pertenece a ninguna empresa.

        El cuerpo acepta name, description, googlePlaceId, latitude y longitude; obligatorios todos menos description. El status NO se envía —el destino nace siempre activo— y el registeredBy tampoco: se toma del usuario autenticado y se devuelve resuelto en registeredByName. Mandar cualquiera de los dos en el cuerpo no tiene efecto.

        El name se NORMALIZA: se recorta, se colapsan los espacios internos y se pasa a mayúsculas. Enviar "bodega central" devuelve un destino llamado "BODEGA CENTRAL"; el cliente debe pintar el name de la respuesta, no el que tecleó el usuario. La unicidad del nombre es GLOBAL e insensible a mayúsculas, porque se compara ya normalizado: enviar "bodega central" existiendo "BODEGA CENTRAL" devuelve 422, no 201 ni 500.

        ATENCIÓN — LOS DOS DUPLICADOS POSIBLES RESPONDEN CON CÓDIGOS DISTINTOS, y es deliberado. Un name repetido es 422 (lo caza la regla unique del FormRequest, mensaje "Ya existe un destino con ese nombre"). Un googlePlaceId repetido es 400 (lo caza el service, mensaje "El lugar seleccionado ya está registrado en el destino {NOMBRE}"). El googlePlaceId se dejó sin regla unique justamente para poder NOMBRAR al destino que ya ocupa ese lugar: un 422 cortaría antes y el cliente nunca vería ese nombre, que es lo único que le dice dónde mirar. El índice único de la columna sigue existiendo en base, pero como último cortafuegos, no como vía de respuesta.

        El googlePlaceId se guarda TAL CUAL LLEGA —sin recortar y sin pasar a mayúsculas, porque es opaco y sensible a mayúsculas— y NO se comprueba contra Google: un place id inventado con el formato correcto se acepta. Se obtiene de GET /api/places.

        ATENCIÓN — NO HAY VALIDACIÓN CRUZADA entre el googlePlaceId y las coordenadas: nadie verifica que latitude y longitude correspondan al lugar elegido. De todas formas las coordenadas NO INFLUYEN EN EL PRECIO —la tarifa depende del destino elegido, no de dónde esté—, así que un pin desalineado se ve mal en el mapa pero no cobra de más.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreLocationRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Destino registrado correctamente, con status true, el name ya normalizado en mayúsculas, el googlePlaceId exactamente como se envió, latitude y longitude como cadenas de ocho decimales y registeredByName el del administrador autenticado.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Destino registrado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Location'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio incumplida, nunca un valor mal formado: otro destino ya está anclado a ese googlePlaceId. El mensaje NOMBRA al ocupante: El lugar seleccionado ya está registrado en el destino BODEGA CENTRAL ESCUINTLA. Es el único 400 del alta, y es la contrapartida de no poner una regla unique sobre el googlePlaceId: el nombre repetido, en cambio, sale por 422.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí, aunque los tres sí puedan leer los destinos—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos. Casos típicos: falta el name o ya existe un destino con ese nombre una vez normalizado —incluido mandar "bodega central" existiendo "BODEGA CENTRAL"— (Ya existe un destino con ese nombre); falta el googlePlaceId o supera los 255 caracteres (El lugar de Google es obligatorio / El lugar de Google no puede superar los 255 caracteres); falta alguna coordenada, no es numérica o está fuera de rango (La latitud debe estar entre -90 y 90 / La longitud debe estar entre -180 y 180); o la descripción no es texto. ATENCIÓN — un googlePlaceId ya usado por otro destino NO cae aquí: es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreLocationRequest $request, LocationServiceInterface $locationService)
    {
        try {
            $location = $locationService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new LocationResource($location), 'Destino registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/locations/{location}',
        operationId: 'showLocation',
        summary: 'Obtener un destino por id',
        description: <<<'TEXT'
        Devuelve un destino concreto, activo o dado de baja, con sus coordenadas y el nombre del administrador que lo capturó.

        Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles, incluido un carrier sin empresa. No hay ámbito por empresa, así que no existe el 403 por recurso ajeno que sí tienen Vehicles o Carriers: o el id existe y se devuelve, o es 404.

        Un destino con status false se obtiene con toda normalidad: la baja es lógica y no oculta la fila en ninguna lectura. El campo status es lo que distingue si se puede cotizar.

        No hay consulta por googlePlaceId ni por nombre: para buscar está el filtro search del listado.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        parameters: [
            new OA\Parameter(
                name: 'location',
                description: 'Identificador numérico del destino (locations.id). Es el mismo valor que se manda como locationId al cotizar un flete y en el filtro locationId del listado de tarifas. No es el googlePlaceId: no existe consulta por place id.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 3),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Destino obtenido correctamente. Puede estar activo o dado de baja: el campo status lo distingue. latitude y longitude vuelven como cadenas de ocho decimales, y createdAt/updatedAt con el formato d-m-Y h:i:s A, no ISO 8601.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Destino obtenido correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Location'),
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
                description: 'No existe ninguna fila con ese id. Un destino dado de baja NO cae aquí: sigue existiendo y se devuelve con 200. El mensaje devuelto es: El destino no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $location, LocationServiceInterface $locationService)
    {
        try {
            $found = $locationService->getLocationById($location);

            return ResponseHandler::success(new LocationResource($found), 'Destino obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/locations/{location}',
        operationId: 'updateLocation',
        summary: 'Actualizar un destino',
        description: <<<'TEXT'
        Modifica el nombre, la descripción, el lugar de Google, las coordenadas, el estado o cualquier combinación de ellos. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403. La ruta acepta PATCH y PUT indistintamente y en ambos casos el comportamiento es el mismo: el PUT no reemplaza el recurso completo.

        Todos los campos son opcionales y solo se toca lo que venga: un PATCH que solo manda description no altera el nombre, el lugar ni las coordenadas. Un CUERPO VACÍO responde 200 como no-op, devolviendo el destino sin cambios, en vez de 422.

        El name se NORMALIZA igual que en el alta —recorte, colapso de espacios y mayúsculas—. La unicidad global se revalida ignorando la propia fila, así que reenviar su mismo nombre responde 200 y usar el de otro destino responde 422.

        ATENCIÓN — EL googlePlaceId ES EDITABLE, y esa es la decisión de diseño más delicada del dominio: reapuntar el destino a otro lugar CONSERVA la fila, su id y TODAS SUS TARIFAS de flete, que es justo el motivo de permitirlo —corregir un lugar mal capturado sin perder el historial de precios—. La contrapartida es que NO HAY VALIDACIÓN CRUZADA con las coordenadas: cambiar el googlePlaceId sin tocar latitude ni longitude responde 200 y deja el pin apuntando al lugar ANTERIOR, sin ningún aviso, sin log y sin error. Si se reapunta el lugar hay que mandar también las coordenadas nuevas en el mismo PATCH. Reenviar el propio googlePlaceId del destino que se edita es 200: la comprobación ignora su propia fila.

        MISMA ASIMETRÍA 422/400 QUE EN EL ALTA: el name que ya usa otro destino es 422 (Ya existe un destino con ese nombre); el googlePlaceId que ya usa otro destino es 400 y nombra al ocupante (El lugar seleccionado ya está registrado en el destino {NOMBRE}).

        La description acepta null para borrarla —se distingue de omitir la clave, que la deja como está—; ningún otro campo acepta null.

        Con status: true este endpoint REACTIVA un destino dado de baja; con status: false lo da de baja, exactamente igual que el DELETE. El solapamiento con PATCH /api/locations/{location}/toggle-status es deliberado: el toggle sirve al interruptor de una tabla y este cuerpo al formulario de edición, que manda estado y datos juntos.

        El registeredBy no se reescribe: sigue apuntando a quien dio de alta el destino, aunque lo edite otro administrador. El createdAt tampoco cambia; el updatedAt sí. Corregir las coordenadas NO cambia ninguna cotización: el precio depende del destino elegido, no de dónde esté.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateLocationRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        parameters: [
            new OA\Parameter(
                name: 'location',
                description: 'Identificador numérico del destino (locations.id). Es también la fila que se ignora al revalidar la unicidad del nombre y la del googlePlaceId, de modo que reenviar los suyos propios no choca consigo misma. El id NO cambia al reapuntar el lugar: las tarifas que cuelgan de él se conservan.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 3),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Destino actualizado correctamente. data trae la fila ya modificada, con el name normalizado en mayúsculas, el googlePlaceId tal cual se envió, las coordenadas como cadenas de ocho decimales, el mismo registeredByName, el mismo createdAt y el updatedAt refrescado. Un cuerpo vacío también responde 200, con el destino intacto.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Destino actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Location'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Otro destino ya está anclado al googlePlaceId enviado. El mensaje nombra al ocupante: El lugar seleccionado ya está registrado en el destino BODEGA CENTRAL ESCUINTLA. Reenviar el propio googlePlaceId del destino que se edita NO cae aquí: es 200. Un nombre repetido tampoco: es 422.',
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
                description: 'No existe ninguna fila con ese id. Un destino dado de baja NO cae aquí: se puede editar y reactivar con normalidad. El mensaje devuelto es: El destino no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'El name ya lo usa otro destino (Ya existe un destino con ese nombre) o supera los 255 caracteres; el googlePlaceId no es texto o supera los 255 caracteres —que otro destino ya lo use es 400, no 422—; alguna coordenada no es numérica o está fuera de rango (La latitud debe estar entre -90 y 90 / La longitud debe estar entre -180 y 180); o el status no es booleano (El estado debe ser verdadero o falso). Un cuerpo vacío NO produce 422.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateLocationRequest $request, int $location, LocationServiceInterface $locationService)
    {
        try {
            $updated = $locationService->update($location, $request->validated());

            return ResponseHandler::success(new LocationResource($updated), 'Destino actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/locations/{location}/toggle-status',
        operationId: 'toggleStatusLocation',
        summary: 'Invertir el estado de un destino',
        description: <<<'TEXT'
        Invierte el status actual del destino: true pasa a false y false pasa a true. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        NO LLEVA CUERPO. Enviarlo no cambia nada: la operación no recibe datos porque el nuevo estado se deduce del actual. Es lo que necesita el interruptor de una tabla de administración, que no tiene por qué saber el estado en el que está la fila. El nombre, la descripción, el lugar de Google y las coordenadas no se tocan.

        NO es idempotente por diseño: dos llamadas seguidas devuelven el destino a su estado inicial. Si lo que se quiere es fijar un estado concreto sin depender del actual, usa PATCH /api/locations/{location} con status, o DELETE para dar de baja.

        Es la vía de REACTIVACIÓN de un destino dado de baja, junto con PATCH y status: true. La baja aquí nunca es definitiva.

        No se comprueba si el destino tiene tarifas cotizadas: desactivarlo es decisión del administrador. Un destino inactivo deja de poder cotizarse —GET /api/freight-rates/quote responde 400 con "El destino seleccionado no está activo"— y sus tarifas quedan congeladas para edición, aunque no se borran ni desaparecen del listado de tarifas.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        parameters: [
            new OA\Parameter(
                name: 'location',
                description: 'Identificador numérico del destino (locations.id), activo o dado de baja: los dos casos son válidos y el resultado es el estado contrario.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 3),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Estado del destino actualizado correctamente. data trae la fila con el status ya invertido y el resto de campos intactos, para que el cliente pinte el interruptor sin releer el listado.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Estado del destino actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Location'),
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
                description: 'No existe ninguna fila con ese id. El mensaje devuelto es: El destino no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function toggleStatus(int $location, LocationServiceInterface $locationService)
    {
        try {
            $toggled = $locationService->toggleStatus($location);

            return ResponseHandler::success(new LocationResource($toggled), 'Estado del destino actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/locations/{location}',
        operationId: 'destroyLocation',
        summary: 'Dar de baja un destino',
        description: <<<'TEXT'
        ATENCIÓN — este DELETE NO BORRA NADA: es una BAJA LÓGICA que se limita a poner status en false. La fila sigue existiendo en la base, GET /api/locations/{location} la sigue devolviendo con 200 y sigue apareciendo en GET /api/locations sin filtros —para excluirla hay que pedir status=true—. Es lo contrario del DELETE de FuelPrices, que borra de verdad, y del de FreightRates, que es un soft delete no idempotente. El motivo es que un destino referenciado por tarifas y por viajes históricos no debe poder desaparecer; la propia clave foránea de freight_rates.location_id está declarada SIN cascade justamente para frenar un borrado real.

        Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        Es IDEMPOTENTE: repetir la llamada sobre un destino ya dado de baja responde 200 LAS DOS VECES y lo deja igual, no 400 ni 404. Dar de baja algo que ya lo está no es un error del cliente, porque el resultado que pidió ya se cumple.

        Es REVERSIBLE: el destino se reactiva con PATCH /api/locations/{location}/toggle-status o con PATCH /api/locations/{location} y status: true. No existe borrado real por ninguna vía.

        EFECTO SOBRE LAS TARIFAS: las tarifas del destino NO se borran ni desaparecen de GET /api/freight-rates, pero el destino deja de ser cotizable —GET /api/freight-rates/quote responde 400 con "El destino seleccionado no está activo"— y sus tarifas quedan congeladas para edición, porque el PATCH de una tarifa revalida que su destino y su producto sigan activos.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        parameters: [
            new OA\Parameter(
                name: 'location',
                description: 'Identificador numérico del destino (locations.id). Sigue siendo válido después de la baja: el id nunca desaparece y sus tarifas siguen colgando de él.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 3),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Destino dado de baja correctamente. data devuelve la fila ya con status false; la fila sigue existiendo, sigue siendo consultable por id y sigue apareciendo en el listado sin filtros. Repetir el DELETE vuelve a responder 200 con exactamente el mismo cuerpo: es idempotente.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Destino dado de baja correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Location'),
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
                description: 'No existe ninguna fila con ese id. Un destino ya dado de baja NO cae aquí: la baja es idempotente y responde 200. El mensaje devuelto es: El destino no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $location, LocationServiceInterface $locationService)
    {
        try {
            $deleted = $locationService->destroy($location);

            return ResponseHandler::success(new LocationResource($deleted), 'Destino dado de baja correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the listing filters the service understands.
     *
     * @return array{status: string|null, type: string|null, search: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'status' => $this->queryString($request, 'status'),
            'type' => $this->queryString($request, 'type'),
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
