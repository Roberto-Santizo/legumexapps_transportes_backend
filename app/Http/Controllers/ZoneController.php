<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Zone\StoreZoneRequest;
use App\Http\Requests\Zone\UpdateZoneRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\Zone\ZoneResource;
use App\Interfaces\Zone\ZoneServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Zones',
    description: 'Zonas geográficas nacionales: polígonos que delimitan áreas de cobertura sobre el mapa. La zona NO pertenece a ninguna empresa transportista —es un dato de Legumex, común a todos los transportistas—, por eso NINGUNA ruta lleva el middleware carrier.required y el recurso no expone carrierId. Los seis endpoints exigen token JWT (Authorization: Bearer {token}), pero el reparto de permisos es asimétrico: la LECTURA (listado y detalle) está abierta a los cuatro roles —administrator, carrier, pilot y manager—, incluso a un carrier que todavía no ha registrado su empresa; la ESCRITURA (alta, actualización, toggle de estado y baja) es exclusiva del administrator (middleware role:administrator) y los otros tres roles reciben 403. Tres reglas atraviesan todo el dominio: el polígono entra y sale como pares [LATITUD, LONGITUD] con el ANILLO ABIERTO —latitud primero, al revés que GeoJSON y Leaflet, y sin repetir el primer punto al final—; el name se guarda SIEMPRE normalizado y en MAYÚSCULAS, con unicidad global insensible a mayúsculas; y el status es un BOOLEANO cuya baja es LÓGICA e idempotente —DELETE pone status en false, la fila nunca desaparece—, igual que en Products y al contrario que el DELETE de FuelPrices, que borra de verdad. REQUISITO DE ENTORNO: este dominio es el primero del proyecto que exige PostgreSQL con la extensión PostGIS habilitada (CREATE EXTENSION postgis, por base de datos y con un rol privilegiado); sin ella la tabla zones no se crea y ninguno de los seis endpoints responde.',
)]
class ZoneController extends Controller
{
    #[OA\Get(
        path: '/api/zones',
        operationId: 'indexZones',
        summary: 'Listar zonas',
        description: <<<'TEXT'
        Devuelve las zonas geográficas con su polígono completo. Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier sin empresa, porque la zona es un dato nacional y no está acotada por transportista. No hay ámbito ni filtrado por empresa: todos los usuarios ven exactamente las mismas filas.

        ATENCIÓN — el listado devuelve por defecto ACTIVAS E INACTIVAS mezcladas. La baja de una zona es lógica, así que lo dado de baja sigue apareciendo aquí con status false; para quedarse solo con lo publicado hay que enviar status=true. Es intencionado: una pantalla de administración necesita ver lo que dio de baja para poder reactivarlo.

        El orden es fijo y no configurable: id ASC, es decir, el orden en que se dieron de alta. No hay sortBy ni sortDir.

        Los cuatro filtros —status, search, lat+lng y limit— se combinan entre sí y son TOLERANTES: un status que no resuelve a booleano, un search en blanco, unas coordenadas incompletas o fuera de rango y un limit no numérico se ignoran en silencio y la lectura devuelve 200, nunca 422. Un filtro sin coincidencias devuelve 200 con data vacío, tampoco 404. No hay filtro por rango de fechas ni por autor del alta.

        La forma de la respuesta depende del parámetro limit: sin limit se devuelven todos los registros y el sobre NO trae total, currentPage ni lastPage; con un limit numérico se devuelve el sobre paginado con esos tres campos aplanados en la raíz, no bajo meta. Ten en cuenta que cada elemento incluye TODOS los vértices de su polígono: con muchas zonas detalladas la respuesta crece rápido, y limit es la única mitigación disponible.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Zones'],
        parameters: [
            new OA\Parameter(
                name: 'status',
                description: 'Filtra por publicación. El valor se interpreta con filter_var, así que los valores admitidos son true, false, 1 y 0 —status=1 devuelve solo las publicadas y status=false solo las dadas de baja—. Cualquier valor que filter_var no resuelva a booleano (por ejemplo status=quizas) se ignora sin error y se devuelven ambos estados, igual que si se omitiera: este filtro nunca provoca un 422.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['true', 'false', '1', '0'], example: 'true'),
            ),
            new OA\Parameter(
                name: 'search',
                description: 'Búsqueda parcial sobre el nombre (LIKE %TERM%). El término se normaliza a mayúsculas antes de comparar y el name está siempre en mayúsculas, así que la búsqueda es insensible a mayúsculas: search=nor y search=NOR devuelven ambas ZONA NORTE. En blanco se ignora y se devuelven todas las zonas. Solo busca por nombre: no cubre la descripción ni el autor del alta.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'nor'),
            ),
            new OA\Parameter(
                name: 'lat',
                description: 'Latitud del punto a consultar, en [-90, 90]. Junto con lng acota el listado a las zonas que CONTIENEN ese punto. Solo se aplica si llegan LAS DOS coordenadas, ambas numéricas y en rango: enviar solo lat, o lat=200, hace que el filtro se ignore ENTERO y el listado salga completo, sin error ni 422. Ojo con el orden respecto al area: en el query string las coordenadas van en parámetros separados, así que aquí no hay ambigüedad, pero el valor de lat sigue siendo la latitud.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'number', format: 'float', maximum: 90, minimum: -90, example: 14.6349),
            ),
            new OA\Parameter(
                name: 'lng',
                description: 'Longitud del punto a consultar, en [-180, 180]. Se usa siempre junto a lat. El resultado puede traer VARIAS zonas: el solape entre zonas está permitido y no se valida, por eso la consulta devuelve una lista y no una zona suelta. Si ninguna zona contiene el punto, la respuesta es 200 con data vacío, NUNCA 404. El filtro no aplica status implícitamente: sin status=true también salen zonas inactivas que contengan el punto.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'number', format: 'float', maximum: 180, minimum: -180, example: -90.5069),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que activa la paginación. Si se omite, o si no es numérico (por ejemplo limit=abc), se devuelven todos los registros sin error y sin metadatos de paginación —que es lo que quiere un mapa que pinta todas las zonas—. Si es numérico se acota al rango [10, 100]: limit=5 devuelve páginas de 10 y limit=500 devuelve páginas de 100. Se combina con status, search y lat+lng.',
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
                description: 'Zonas obtenidas correctamente. Sin limit se devuelve ZoneListResponse; con limit numérico, PaginatedZoneListResponse. Una tabla vacía, un filtro sin coincidencias o un punto que no cae en ninguna zona devuelven 200 con data vacío, nunca 404.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/ZoneListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedZoneListResponse'),
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
    public function index(Request $request, ZoneServiceInterface $zoneService)
    {
        try {
            $zones = $zoneService->getZones($this->filters($request));

            $data = $zones instanceof LengthAwarePaginator
                ? new PaginatedResource($zones, ZoneResource::class)
                : ZoneResource::collection($zones);

            return ResponseHandler::success($data, 'Zonas obtenidas correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/zones',
        operationId: 'storeZone',
        summary: 'Registrar una zona',
        description: <<<'TEXT'
        Da de alta una zona geográfica nacional. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403, aunque los tres sí puedan leer las zonas. No lleva carrier.required, porque la zona no pertenece a ninguna empresa.

        El cuerpo acepta name, description, color y area; obligatorios solo name y area. El status no se envía —la zona nace siempre en true— y el registeredBy tampoco: se toma del usuario autenticado y se devuelve resuelto en registeredByName. Mandar cualquiera de los dos en el cuerpo no tiene efecto.

        ATENCIÓN — el area se manda como pares [LATITUD, LONGITUD] con el ANILLO ABIERTO: mínimo 3 vértices distintos y SIN repetir el primero al final, que es lo que el GET devolverá tal cual. La latitud va primero, al revés que GeoJSON, Mapbox y la forma [lng, lat] de Leaflet; el par invertido normalmente cae en 422 por rango de latitud, pero un par ambiguo se guardaría en el sitio equivocado sin error alguno.

        El name se NORMALIZA: se recorta, se colapsan los espacios internos y se pasa a mayúsculas. Enviar "zona norte" devuelve una zona llamada "ZONA NORTE"; el cliente debe pintar el name de la respuesta, no el que tecleó el usuario. La unicidad del nombre es GLOBAL e insensible a mayúsculas, porque se compara ya normalizado: enviar "zona norte" existiendo "ZONA NORTE" devuelve 422, no 201 ni 500.

        El color es OPCIONAL: omitirlo crea la zona con #3388FF, el azul por defecto de Leaflet, de modo que una zona recién creada ya se ve bien en un mapa sin decisión de nadie.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreZoneRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Zones'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Zona registrada correctamente, con status true, el name ya normalizado en mayúsculas, el color en mayúsculas —#3388FF si no se envió—, el area devuelta como los mismos pares [lat, lng] que se enviaron, con el anillo abierto, y registeredByName el del administrador autenticado.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Zona registrada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Zone'),
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
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí, aunque los tres sí puedan leer las zonas—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos. Casos típicos: falta el name o ya existe una zona con ese nombre una vez normalizado —incluido mandar "zona norte" existiendo "ZONA NORTE"— (Ya existe una zona con ese nombre); falta el area, tiene menos de 3 puntos, algún punto no es un par de exactamente 2 números, o una coordenada está fuera de rango, incluido el caso del par invertido (La latitud del punto 1 debe estar entre -90 y 90 / La longitud del punto 1 debe estar entre -180 y 180); el color no es un hexadecimal #RRGGBB (El color debe ser hexadecimal con el formato #RRGGBB); o la descripción supera los 1000 caracteres.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreZoneRequest $request, ZoneServiceInterface $zoneService)
    {
        try {
            $zone = $zoneService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new ZoneResource($zone), 'Zona registrada correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/zones/{zone}',
        operationId: 'showZone',
        summary: 'Obtener una zona por id',
        description: <<<'TEXT'
        Devuelve una zona concreta, publicada o dada de baja, con su polígono completo y el nombre del administrador que la capturó.

        Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles, incluido un carrier sin empresa. No hay ámbito por empresa, así que no existe el 403 por recurso ajeno que sí tienen Vehicles o Carriers: o el id existe y se devuelve, o es 404.

        El area sale como los mismos pares [latitud, longitud] con el anillo abierto que se enviaron al crearla o en la última actualización: mismo número de puntos, mismo orden y sin punto de cierre repetido.

        Una zona con status false se obtiene con toda normalidad: la baja es lógica y no oculta la fila en ninguna lectura. El campo status es lo que distingue si está publicada.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Zones'],
        parameters: [
            new OA\Parameter(
                name: 'zone',
                description: 'Identificador numérico de la zona (zones.id). No es el nombre: no existe consulta por name, para eso está el filtro search del listado, ni consulta por punto, para eso están lat y lng.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Zona obtenida correctamente. Puede estar publicada o dada de baja: el campo status lo distingue.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Zona obtenida correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Zone'),
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
                description: 'No existe ninguna fila con ese id. Una zona dada de baja NO cae aquí: sigue existiendo y se devuelve con 200. El mensaje devuelto es: La zona no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $zone, ZoneServiceInterface $zoneService)
    {
        try {
            $found = $zoneService->getZoneById($zone);

            return ResponseHandler::success(new ZoneResource($found), 'Zona obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/zones/{zone}',
        operationId: 'updateZone',
        summary: 'Actualizar una zona',
        description: <<<'TEXT'
        Modifica el nombre, la descripción, el color, el estado, el polígono o cualquier combinación de ellos. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403. La ruta acepta PATCH y PUT indistintamente y en ambos casos el comportamiento es el mismo: el PUT no reemplaza el recurso completo.

        Todos los campos son opcionales y solo se toca lo que venga: un PATCH que solo manda name no altera area, color ni description. ATENCIÓN — a diferencia de Products, aquí NO hay required_without cruzado: un CUERPO VACÍO responde 200 como no-op, devolviendo la zona sin cambios, en vez de 422.

        El name se NORMALIZA igual que en el alta —recorte, colapso de espacios y mayúsculas—. La unicidad global se revalida ignorando la propia fila, así que reenviar su mismo nombre responde 200, y usar el de otra zona responde 422.

        El area, si viene, SUSTITUYE al polígono entero, con las mismas reglas del alta —mínimo 3 pares [latitud, longitud], anillo abierto, latitud primero— y sin conservar rastro del trazado anterior. No hay edición de vértices sueltos.

        La description acepta null para borrarla; el color NO acepta null (devuelve 422): para dejarlo igual se omite la clave.

        Con status: true este endpoint REACTIVA una zona dada de baja; con status: false la da de baja, exactamente igual que el DELETE. El solapamiento con PATCH /api/zones/{zone}/toggle-status es deliberado: el toggle sirve al interruptor de una tabla y este cuerpo al formulario de edición que manda estado y datos juntos.

        El registeredBy no se reescribe: sigue apuntando a quien dio de alta la zona, aunque la edite otro administrador. El createdAt tampoco cambia; el updatedAt sí.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateZoneRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Zones'],
        parameters: [
            new OA\Parameter(
                name: 'zone',
                description: 'Identificador numérico de la zona (zones.id). Es también la fila que se ignora al revalidar la unicidad del nombre.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Zona actualizada correctamente. data trae la fila ya modificada, con el name normalizado en mayúsculas, el color en mayúsculas, el area releída de la base como pares [lat, lng] con el anillo abierto, el mismo registeredByName, el mismo createdAt y el updatedAt refrescado. Un cuerpo vacío también responde 200, con la zona intacta.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Zona actualizada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Zone'),
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
                description: 'No existe ninguna fila con ese id. Una zona dada de baja NO cae aquí: se puede editar y reactivar con normalidad. El mensaje devuelto es: La zona no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'El name ya lo usa otra zona (Ya existe una zona con ese nombre) o supera los 255 caracteres; el area tiene menos de 3 puntos, algún punto no es un par de 2 números o una coordenada está fuera de rango (La latitud del punto N debe estar entre -90 y 90); el color no es #RRGGBB o llega como null (El color debe ser hexadecimal con el formato #RRGGBB / El color debe ser texto); el status no es booleano (El estado debe ser verdadero o falso); o la descripción supera los 1000 caracteres. Un cuerpo vacío NO produce 422.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateZoneRequest $request, int $zone, ZoneServiceInterface $zoneService)
    {
        try {
            $updated = $zoneService->update($zone, $request->validated());

            return ResponseHandler::success(new ZoneResource($updated), 'Zona actualizada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/zones/{zone}/toggle-status',
        operationId: 'toggleStatusZone',
        summary: 'Invertir el estado de una zona',
        description: <<<'TEXT'
        Invierte el status actual de la zona: true pasa a false y false pasa a true. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        NO LLEVA CUERPO. Enviarlo no cambia nada: la operación no recibe datos porque el nuevo estado se deduce del actual. Es lo que necesita el interruptor de una tabla de administración, que no tiene por qué saber el estado en el que está la fila. El polígono, el color y la descripción no se tocan.

        NO es idempotente por diseño: dos llamadas seguidas devuelven la zona a su estado inicial. Si lo que se quiere es fijar un estado concreto sin depender del actual, usa PATCH /api/zones/{zone} con status, o DELETE para dar de baja.

        Es la vía de REACTIVACIÓN de una zona dada de baja, junto con PATCH y status: true. La baja aquí nunca es definitiva.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Zones'],
        parameters: [
            new OA\Parameter(
                name: 'zone',
                description: 'Identificador numérico de la zona (zones.id), publicada o dada de baja: los dos casos son válidos y el resultado es el estado contrario.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Estado de la zona actualizado correctamente. data trae la fila con el status ya invertido y el resto de campos intactos, para que el cliente pinte el interruptor sin releer el listado.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Estado de la zona actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Zone'),
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
                description: 'No existe ninguna fila con ese id. El mensaje devuelto es: La zona no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function toggleStatus(int $zone, ZoneServiceInterface $zoneService)
    {
        try {
            $toggled = $zoneService->toggleStatus($zone);

            return ResponseHandler::success(new ZoneResource($toggled), 'Estado de la zona actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/zones/{zone}',
        operationId: 'destroyZone',
        summary: 'Dar de baja una zona',
        description: <<<'TEXT'
        ATENCIÓN — este DELETE NO BORRA NADA: es una BAJA LÓGICA que se limita a poner status en false. La fila sigue existiendo en la base con su polígono intacto, GET /api/zones/{zone} la sigue devolviendo con 200 y sigue apareciendo en GET /api/zones sin filtros —para excluirla hay que pedir status=true—, incluido el filtro lat+lng. Es lo contrario del DELETE de FuelPrices, que borra de verdad y hace desaparecer el id. El motivo es que una zona que aparezca en un histórico de viajes futuro no debe poder desaparecer.

        Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        Es IDEMPOTENTE: repetir la llamada sobre una zona ya dada de baja responde 200 y la deja igual, no 400 ni 404. Dar de baja algo que ya lo está no es un error del cliente, porque el resultado que pidió ya se cumple.

        Es REVERSIBLE: la zona se reactiva con PATCH /api/zones/{zone}/toggle-status o con PATCH /api/zones/{zone} y status: true. No existe borrado real por ninguna vía, ni de la fila ni de la geometría.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Zones'],
        parameters: [
            new OA\Parameter(
                name: 'zone',
                description: 'Identificador numérico de la zona (zones.id). Sigue siendo válido después de la baja: el id nunca desaparece.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Zona dada de baja correctamente. data devuelve la fila ya con status false; la fila sigue existiendo, sigue siendo consultable por id y sigue apareciendo en el listado sin filtros, con su polígono sin tocar.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Zona dada de baja correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Zone'),
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
                description: 'No existe ninguna fila con ese id. Una zona ya dada de baja NO cae aquí: al ser baja lógica la fila sigue existiendo y repetir el DELETE devuelve 200. El mensaje devuelto es: La zona no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $zone, ZoneServiceInterface $zoneService)
    {
        try {
            $deleted = $zoneService->destroy($zone);

            return ResponseHandler::success(new ZoneResource($deleted), 'Zona dada de baja correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Read the listing filters from the query string.
     *
     * Which of them are valid, and what to do with the invalid ones, is decided by the
     * service; here they are only normalized to strings, since anything else is not a
     * valid value.
     *
     * @return array{status: string|null, search: string|null, lat: string|null, lng: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'status' => $this->queryString($request, 'status'),
            'search' => $this->queryString($request, 'search'),
            'lat' => $this->queryString($request, 'lat'),
            'lng' => $this->queryString($request, 'lng'),
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
