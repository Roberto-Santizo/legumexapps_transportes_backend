<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\DeparturePoint\StoreDeparturePointRequest;
use App\Http\Requests\DeparturePoint\UpdateDeparturePointRequest;
use App\Http\Resources\DeparturePoint\DeparturePointResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\DeparturePoint\DeparturePointServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Departure Points',
    description: 'Puntos de partida: los lugares desde los que ARRANCA un viaje —bodegas, fincas, centros de acopio—, anclados a un lugar real de Google por su googlePlaceId. El punto de partida NO pertenece a ninguna empresa transportista —es un dato de Legumex, común a todos—, por eso NINGUNA ruta lleva el middleware carrier.required y el recurso no expone carrierId. Los seis endpoints exigen token JWT (Authorization: Bearer {token}); sin él la respuesta es 401 con "El token de sesión no es válido o ha expirado". El reparto de permisos es asimétrico: la LECTURA (listado y detalle) está abierta a los cuatro roles —administrator, carrier, pilot y manager—, incluso a un carrier que todavía no ha registrado su empresa; la ESCRITURA (alta, actualización, toggle de estado y baja) es exclusiva del administrator (middleware role:administrator) y los otros tres roles reciben 403 con "No tienes permisos para acceder a este recurso". ATENCIÓN — ESTE DOMINIO ES UNA COPIA DECLARADA DE Locations Y CONFUNDIRLOS ES EL RIESGO NÚMERO UNO: /api/departure-points y /api/locations devuelven LAS MISMAS DIEZ CLAVES con la misma forma y aceptan el MISMO cuerpo, así que llamar al catálogo equivocado NO FALLA —responde 200 o 201 con datos plausibles del otro dominio— y el error solo se ve cuando el usuario lee un nombre que no esperaba. Un punto de partida es DE DÓNDE SALE el viaje (tabla departure_points); un destino es A DÓNDE LLEGA (tabla locations). Los id de las dos tablas NO SON INTERCAMBIABLES: son secuencias distintas y no hay ninguna relación entre el id 3 de aquí y el id 3 de allá. NO HAY TARIFAS: a diferencia de un destino, un punto de partida no se cotiza, no tiene ningún FreightRate colgando, ninguna tabla lo referencia y GET /api/freight-rates/quote no lo recibe; por eso desactivarlo o darlo de baja NO BLOQUEA NADA en el resto de la API y no existe ningún 400 por "punto inactivo". Reglas que atraviesan el dominio: el name se guarda SIEMPRE normalizado y en MAYÚSCULAS, con unicidad insensible a mayúsculas PERO SOLO DENTRO DE ESTA TABLA —el mismo nombre y el mismo googlePlaceId pueden existir a la vez en locations y el alta responde 201 sin aviso—; el googlePlaceId se guarda TAL CUAL, es opaco y sensible a mayúsculas, y su duplicado se responde con 400 —no con 422 como el del name—, nombrando al punto que ya lo ocupa; la API NUNCA llama a Google, el front resuelve el lugar en GET /api/places y manda place id y coordenadas ya resueltos; latitude y longitude salen como CADENAS de ocho decimales, no como números; createdAt y updatedAt usan el formato propio d-m-Y h:i:s A, NO ISO 8601; y el status es un BOOLEANO cuya baja es LÓGICA e idempotente —DELETE pone status en false, la fila nunca desaparece—, igual que en Locations, Products y Zones y al contrario que el DELETE de FuelPrices, que borra de verdad.',
)]
class DeparturePointController extends Controller
{
    #[OA\Get(
        path: '/api/departure-points',
        operationId: 'indexDeparturePoints',
        summary: 'Listar puntos de partida',
        description: <<<'TEXT'
        Devuelve los puntos de partida con sus coordenadas y el nombre de quien los capturó. Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier sin empresa, porque el punto de partida es un dato nacional y no está acotado por transportista. No hay ámbito ni filtrado por empresa: todos los usuarios ven exactamente las mismas filas.

        ATENCIÓN — NO ES GET /api/locations. Los dos listados devuelven las mismas diez claves por elemento, así que llamar al equivocado responde 200 con datos del otro catálogo y nadie avisa. Aquí salen los ORÍGENES; los destinos están en /api/locations, en otra tabla y con otra secuencia de id.

        ATENCIÓN — el listado devuelve por defecto ACTIVOS E INACTIVOS mezclados. La baja de un punto de partida es lógica, así que lo dado de baja sigue apareciendo aquí con status false; para quedarse solo con lo publicado hay que enviar status=true. Es intencionado: una pantalla de administración necesita ver lo que dio de baja para poder reactivarlo.

        El orden es fijo y no configurable: id ASC, es decir, el orden en que se dieron de alta. No hay sortBy ni sortDir.

        Los tres filtros —status, search y limit— se combinan entre sí y son TOLERANTES: un status que no resuelve a booleano, un search en blanco y un limit no numérico se ignoran en silencio y la lectura devuelve 200, NUNCA 422. Un filtro sin coincidencias devuelve 200 con data vacío, tampoco 404. No hay búsqueda por googlePlaceId, ni por proximidad geográfica, ni filtro por autor del alta o por rango de fechas: cualquier otro query param se ignora.

        La forma de la respuesta depende del parámetro limit: sin limit se devuelven todos los registros y el sobre NO trae total, currentPage ni lastPage; con un limit numérico se devuelve el sobre paginado con esos tres campos APLANADOS EN LA RAÍZ, no bajo meta.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Departure Points'],
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
                description: 'Búsqueda parcial sobre el nombre (LIKE %TERM%). El término se normaliza igual que el name —recorte, colapso de espacios y mayúsculas— y el name está siempre en mayúsculas, así que la búsqueda es insensible a mayúsculas: search=bode y search=BODE devuelven ambas BODEGA CENTRAL ESCUINTLA. En blanco o solo espacios se ignora y se devuelven todos los puntos de partida. Solo busca por nombre: NO cubre la descripción, ni el googlePlaceId, ni el autor del alta.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'bode'),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que activa la paginación. Si se omite, o si no es numérico (por ejemplo limit=abc), se devuelven todos los registros sin error y sin metadatos de paginación —que es lo que quiere un selector de origen—. Si es numérico se ACOTA al rango [10, 100]: limit=1 devuelve páginas de 10 y limit=500 devuelve páginas de 100. Se combina con status y search.',
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
                description: 'Puntos de partida obtenidos correctamente. Sin limit se devuelve DeparturePointListResponse; con limit numérico, PaginatedDeparturePointListResponse, con total, currentPage y lastPage aplanados en la raíz del sobre. Una tabla vacía o un filtro sin coincidencias devuelven 200 con data vacío, nunca 404.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/DeparturePointListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedDeparturePointListResponse'),
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
    public function index(Request $request, DeparturePointServiceInterface $departurePointService)
    {
        try {
            $departurePoints = $departurePointService->getDeparturePoints($this->filters($request));

            $data = $departurePoints instanceof LengthAwarePaginator
                ? new PaginatedResource($departurePoints, DeparturePointResource::class)
                : DeparturePointResource::collection($departurePoints);

            return ResponseHandler::success($data, 'Puntos de partida obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/departure-points',
        operationId: 'storeDeparturePoint',
        summary: 'Registrar un punto de partida',
        description: <<<'TEXT'
        Da de alta un punto de partida nacional. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403, aunque los tres sí puedan leer los puntos de partida. No lleva carrier.required, porque el punto no pertenece a ninguna empresa.

        ATENCIÓN — NO ES POST /api/locations. El cuerpo de las dos altas es IDÉNTICO campo por campo, así que equivocarse de endpoint NO produce ningún error: crea la fila en la tabla equivocada y responde 201. Aquí se dan de alta ORÍGENES; los DESTINOS van en /api/locations.

        El cuerpo acepta name, description, googlePlaceId, latitude y longitude; obligatorios todos menos description. El status NO se envía —el punto nace siempre activo— y el registeredBy tampoco: se toma del usuario autenticado y se devuelve resuelto en registeredByName. Mandar cualquiera de los dos en el cuerpo no tiene efecto.

        El name se NORMALIZA: se recorta, se colapsan los espacios internos y se pasa a mayúsculas. Enviar "bodega central" devuelve un punto llamado "BODEGA CENTRAL"; el cliente debe pintar el name de la respuesta, no el que tecleó el usuario. La unicidad del nombre es insensible a mayúsculas, porque se compara ya normalizado: enviar "bodega central" existiendo "BODEGA CENTRAL" devuelve 422, no 201 ni 500.

        ATENCIÓN — LOS DOS DUPLICADOS POSIBLES RESPONDEN CON CÓDIGOS DISTINTOS, y es deliberado. Un name repetido es 422 (lo caza la regla unique del FormRequest, mensaje "Ya existe un punto de partida con ese nombre"). Un googlePlaceId repetido es 400 (lo caza el service, mensaje "El lugar seleccionado ya está registrado en el punto de partida {NOMBRE}"). El googlePlaceId se dejó sin regla unique justamente para poder NOMBRAR al punto que ya ocupa ese lugar: un 422 cortaría antes y el cliente nunca vería ese nombre, que es lo único que le dice dónde mirar.

        ATENCIÓN — LA UNICIDAD ES POR TABLA. Ni el name ni el googlePlaceId se cruzan con locations: el mismo nombre y el mismo lugar de Google pueden existir a la vez como destino y como punto de partida, y el alta responde 201 SIN NINGÚN AVISO. Es intencionado —un mismo almacén puede ser origen de unos viajes y destino de otros—, pero implica que un duplicado nacido de equivocarse de endpoint no lo detecta nadie.

        El googlePlaceId se guarda TAL CUAL LLEGA —sin recortar y sin pasar a mayúsculas, porque es opaco y sensible a mayúsculas— y NO se comprueba contra Google: LA API NUNCA LLAMA A GOOGLE. El front busca en GET /api/places, el usuario elige y aquí llegan el place id y las coordenadas ya resueltos; un place id inventado con el formato correcto se acepta.

        ATENCIÓN — NO HAY VALIDACIÓN CRUZADA entre el googlePlaceId y las coordenadas: nadie verifica que latitude y longitude correspondan al lugar elegido. Un pin desalineado se ve mal en el mapa y nada más: ningún cálculo del proyecto consume estas coordenadas.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreDeparturePointRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Departure Points'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Punto de partida registrado correctamente, con status true, el name ya normalizado en mayúsculas, el googlePlaceId exactamente como se envió, latitude y longitude como cadenas de ocho decimales y registeredByName el del administrador autenticado.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Punto de partida registrado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/DeparturePoint'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio incumplida, nunca un valor mal formado: otro PUNTO DE PARTIDA ya está anclado a ese googlePlaceId. El mensaje NOMBRA al ocupante: El lugar seleccionado ya está registrado en el punto de partida BODEGA CENTRAL ESCUINTLA. Es el único 400 del alta, y es la contrapartida de no poner una regla unique sobre el googlePlaceId: el nombre repetido, en cambio, sale por 422. Que el lugar ya esté usado por un DESTINO no cae aquí: la comprobación mira solo la tabla departure_points y el alta responde 201.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí, aunque los tres sí puedan leer los puntos de partida—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos. Casos típicos: falta el name o ya existe un punto de partida con ese nombre una vez normalizado —incluido mandar "bodega central" existiendo "BODEGA CENTRAL"— (El nombre del punto de partida es obligatorio / Ya existe un punto de partida con ese nombre); falta el googlePlaceId o supera los 255 caracteres (El lugar de Google es obligatorio / El lugar de Google no puede superar los 255 caracteres); falta alguna coordenada, no es numérica o está fuera de rango (La latitud es obligatoria / La latitud debe estar entre -90 y 90 / La longitud debe estar entre -180 y 180); o la descripción no es texto (La descripción debe ser texto). ATENCIÓN — un googlePlaceId ya usado por otro punto de partida NO cae aquí: es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreDeparturePointRequest $request, DeparturePointServiceInterface $departurePointService)
    {
        try {
            $departurePoint = $departurePointService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new DeparturePointResource($departurePoint), 'Punto de partida registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/departure-points/{departurePoint}',
        operationId: 'showDeparturePoint',
        summary: 'Obtener un punto de partida por id',
        description: <<<'TEXT'
        Devuelve un punto de partida concreto, activo o dado de baja, con sus coordenadas y el nombre del administrador que lo capturó.

        Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles, incluido un carrier sin empresa. No hay ámbito por empresa, así que no existe el 403 por recurso ajeno que sí tienen Vehicles o Carriers: o el id existe y se devuelve, o es 404.

        ATENCIÓN — EL id NO ES INTERCAMBIABLE CON EL DE UN Location. departure_points y locations son tablas distintas con secuencias distintas, y las dos respuestas tienen exactamente la misma forma: pedir aquí un id de destino no da 404 si esa fila existe también aquí, devuelve 200 con OTRO registro. El error no se detecta solo; hay que asegurarse de llamar al endpoint correcto.

        Un punto de partida con status false se obtiene con toda normalidad: la baja es lógica y no oculta la fila en ninguna lectura.

        No hay consulta por googlePlaceId ni por nombre: para buscar está el filtro search del listado.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Departure Points'],
        parameters: [
            new OA\Parameter(
                name: 'departurePoint',
                description: 'Identificador numérico del punto de partida (departure_points.id). NO es el googlePlaceId —no existe consulta por place id— y NO es el id de un Location: son secuencias distintas de tablas distintas.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 3),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Punto de partida obtenido correctamente. Puede estar activo o dado de baja: el campo status lo distingue. latitude y longitude vuelven como cadenas de ocho decimales, y createdAt/updatedAt con el formato d-m-Y h:i:s A, no ISO 8601.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Punto de partida obtenido correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/DeparturePoint'),
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
                description: 'No existe ninguna fila con ese id EN departure_points. Un punto dado de baja NO cae aquí: sigue existiendo y se devuelve con 200. El mensaje devuelto es: El punto de partida no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $departurePoint, DeparturePointServiceInterface $departurePointService)
    {
        try {
            $found = $departurePointService->getDeparturePointById($departurePoint);

            return ResponseHandler::success(new DeparturePointResource($found), 'Punto de partida obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/departure-points/{departurePoint}',
        operationId: 'updateDeparturePoint',
        summary: 'Actualizar un punto de partida',
        description: <<<'TEXT'
        Modifica el nombre, la descripción, el lugar de Google, las coordenadas, el estado o cualquier combinación de ellos. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403. La ruta acepta PATCH y PUT indistintamente y en ambos casos el comportamiento es el mismo: el PUT no reemplaza el recurso completo.

        Todos los campos son opcionales y solo se toca lo que venga: un PATCH que solo manda description no altera el nombre, el lugar ni las coordenadas. Un CUERPO VACÍO responde 200 como no-op, devolviendo el punto de partida sin cambios, en vez de 422.

        El name se NORMALIZA igual que en el alta —recorte, colapso de espacios y mayúsculas—. La unicidad se revalida ignorando la propia fila, así que reenviar su mismo nombre responde 200 y usar el de otro punto de partida responde 422. La unicidad sigue siendo POR TABLA: coincidir con el nombre de un destino no choca.

        ATENCIÓN — EL googlePlaceId ES EDITABLE, y esa es la decisión de diseño más delicada del dominio: reapuntar el punto a otro lugar CONSERVA la fila y su id, que es justo el motivo de permitirlo —corregir un lugar mal capturado sin dar de alta uno nuevo—. La contrapartida es que NO HAY VALIDACIÓN CRUZADA con las coordenadas: cambiar el googlePlaceId sin tocar latitude ni longitude responde 200 y deja el pin apuntando al lugar ANTERIOR, sin ningún aviso, sin log y sin error. Si se reapunta el lugar hay que mandar también las coordenadas nuevas en el mismo PATCH. Reenviar el propio googlePlaceId del punto que se edita es 200: la comprobación ignora su propia fila.

        MISMA ASIMETRÍA 422/400 QUE EN EL ALTA: el name que ya usa otro punto de partida es 422 (Ya existe un punto de partida con ese nombre); el googlePlaceId que ya usa otro punto de partida es 400 y nombra al ocupante (El lugar seleccionado ya está registrado en el punto de partida {NOMBRE}).

        La description acepta null para borrarla —se distingue de omitir la clave, que la deja como está—; ningún otro campo acepta null.

        Con status: true este endpoint REACTIVA un punto dado de baja; con status: false lo da de baja, exactamente igual que el DELETE. El solapamiento con PATCH /api/departure-points/{departurePoint}/toggle-status es deliberado: el toggle sirve al interruptor de una tabla y este cuerpo al formulario de edición, que manda estado y datos juntos.

        El registeredBy no se reescribe: sigue apuntando a quien dio de alta el punto de partida, aunque lo edite otro administrador. El createdAt tampoco cambia; el updatedAt sí. Nada de lo que se edite aquí afecta a ninguna cotización: de un punto de partida no cuelgan tarifas.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateDeparturePointRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Departure Points'],
        parameters: [
            new OA\Parameter(
                name: 'departurePoint',
                description: 'Identificador numérico del punto de partida (departure_points.id). Es también la fila que se ignora al revalidar la unicidad del nombre y la del googlePlaceId, de modo que reenviar los suyos propios no choca consigo misma. El id NO cambia al reapuntar el lugar. ATENCIÓN — no es el id de un Location: mandar uno de destino edita otra fila de ESTA tabla si existe, y responde 200 sin aviso.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 3),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Punto de partida actualizado correctamente. data trae la fila ya modificada, con el name normalizado en mayúsculas, el googlePlaceId tal cual se envió, las coordenadas como cadenas de ocho decimales, el mismo registeredByName, el mismo createdAt y el updatedAt refrescado. Un cuerpo vacío también responde 200, con el punto de partida intacto.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Punto de partida actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/DeparturePoint'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Otro punto de partida ya está anclado al googlePlaceId enviado. El mensaje nombra al ocupante: El lugar seleccionado ya está registrado en el punto de partida BODEGA CENTRAL ESCUINTLA. Reenviar el propio googlePlaceId del punto que se edita NO cae aquí: es 200. Un nombre repetido tampoco: es 422. Que el lugar lo use un DESTINO tampoco: la unicidad es por tabla.',
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
                description: 'No existe ninguna fila con ese id. Un punto de partida dado de baja NO cae aquí: se puede editar y reactivar con normalidad. El mensaje devuelto es: El punto de partida no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'El name ya lo usa otro punto de partida (Ya existe un punto de partida con ese nombre) o supera los 255 caracteres (El nombre del punto de partida no puede superar los 255 caracteres); el googlePlaceId no es texto o supera los 255 caracteres —que otro punto ya lo use es 400, no 422—; alguna coordenada no es numérica o está fuera de rango (La latitud debe estar entre -90 y 90 / La longitud debe estar entre -180 y 180); o el status no es booleano (El estado debe ser verdadero o falso). Un cuerpo vacío NO produce 422.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateDeparturePointRequest $request, int $departurePoint, DeparturePointServiceInterface $departurePointService)
    {
        try {
            $updated = $departurePointService->update($departurePoint, $request->validated());

            return ResponseHandler::success(new DeparturePointResource($updated), 'Punto de partida actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/departure-points/{departurePoint}/toggle-status',
        operationId: 'toggleStatusDeparturePoint',
        summary: 'Invertir el estado de un punto de partida',
        description: <<<'TEXT'
        Invierte el status actual del punto de partida: true pasa a false y false pasa a true. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        NO LLEVA CUERPO. Enviarlo no cambia nada: la operación no recibe datos porque el nuevo estado se deduce del actual. Es lo que necesita el interruptor de una tabla de administración, que no tiene por qué saber el estado en el que está la fila. El nombre, la descripción, el lugar de Google y las coordenadas no se tocan.

        NO es idempotente por diseño: dos llamadas seguidas devuelven el punto de partida a su estado inicial. Si lo que se quiere es fijar un estado concreto sin depender del actual, usa PATCH /api/departure-points/{departurePoint} con status, o DELETE para dar de baja.

        Es la vía de REACTIVACIÓN de un punto dado de baja, junto con PATCH y status: true. La baja aquí nunca es definitiva.

        ATENCIÓN — DESACTIVAR UN PUNTO DE PARTIDA NO BLOQUEA NADA. A diferencia de un destino, de él no cuelga ninguna tarifa de flete y ninguna otra tabla lo referencia, así que no hay cotización que deje de funcionar ni edición que se congele: el status es solo una marca de publicación para que el cliente lo oculte de sus selectores de origen.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Departure Points'],
        parameters: [
            new OA\Parameter(
                name: 'departurePoint',
                description: 'Identificador numérico del punto de partida (departure_points.id), activo o dado de baja: los dos casos son válidos y el resultado es el estado contrario.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 3),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Estado del punto de partida actualizado correctamente. data trae la fila con el status ya invertido y el resto de campos intactos, para que el cliente pinte el interruptor sin releer el listado.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Estado del punto de partida actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/DeparturePoint'),
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
                description: 'No existe ninguna fila con ese id. El mensaje devuelto es: El punto de partida no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function toggleStatus(int $departurePoint, DeparturePointServiceInterface $departurePointService)
    {
        try {
            $toggled = $departurePointService->toggleStatus($departurePoint);

            return ResponseHandler::success(new DeparturePointResource($toggled), 'Estado del punto de partida actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/departure-points/{departurePoint}',
        operationId: 'destroyDeparturePoint',
        summary: 'Dar de baja un punto de partida',
        description: <<<'TEXT'
        ATENCIÓN — este DELETE NO BORRA NADA: es una BAJA LÓGICA que se limita a poner status en false. La fila sigue existiendo en la base, GET /api/departure-points/{departurePoint} la sigue devolviendo con 200 y sigue apareciendo en GET /api/departure-points sin filtros —para excluirla hay que pedir status=true—. Es lo contrario del DELETE de FuelPrices, que borra de verdad, y del de FreightRates, que es un soft delete no idempotente. El motivo es que un punto de partida citado en viajes históricos no debe poder desaparecer.

        Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        Es IDEMPOTENTE: repetir la llamada sobre un punto de partida ya dado de baja responde 200 LAS DOS VECES y lo deja igual, no 400 ni 404. Dar de baja algo que ya lo está no es un error del cliente, porque el resultado que pidió ya se cumple.

        Es REVERSIBLE: el punto se reactiva con PATCH /api/departure-points/{departurePoint}/toggle-status o con PATCH /api/departure-points/{departurePoint} y status: true. No existe borrado real por ninguna vía.

        ATENCIÓN — LA BAJA NO TIENE EFECTOS COLATERALES. A diferencia de un destino, de un punto de partida no cuelga ninguna tarifa de flete y ninguna otra tabla lo referencia: darlo de baja no impide cotizar nada, no congela ninguna edición y no rompe ninguna otra llamada de la API. Es solo una marca de publicación.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Departure Points'],
        parameters: [
            new OA\Parameter(
                name: 'departurePoint',
                description: 'Identificador numérico del punto de partida (departure_points.id). Sigue siendo válido después de la baja: el id nunca desaparece.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 3),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Punto de partida dado de baja correctamente. data devuelve la fila ya con status false; la fila sigue existiendo, sigue siendo consultable por id y sigue apareciendo en el listado sin filtros. Repetir el DELETE vuelve a responder 200 con exactamente el mismo cuerpo: es idempotente.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Punto de partida dado de baja correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/DeparturePoint'),
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
                description: 'No existe ninguna fila con ese id. Un punto de partida ya dado de baja NO cae aquí: la baja es idempotente y responde 200. El mensaje devuelto es: El punto de partida no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $departurePoint, DeparturePointServiceInterface $departurePointService)
    {
        try {
            $deleted = $departurePointService->destroy($departurePoint);

            return ResponseHandler::success(new DeparturePointResource($deleted), 'Punto de partida dado de baja correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the listing filters the service understands.
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
