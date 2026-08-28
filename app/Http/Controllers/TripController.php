<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Trip\AssignTripRequest;
use App\Http\Requests\Trip\StoreTripRequest;
use App\Http\Requests\Trip\UpdateTripRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\Trip\TripListResource;
use App\Http\Resources\Trip\TripResource;
use App\Interfaces\Trip\TripServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Trips',
    description: <<<'TEXT'
    Viajes de exportación: el viaje que enlaza cliente, naviera, punto de partida y puerto de destino. Es el dominio con más dependencias del proyecto —consume Clients, Shipping Lines, Departure Points, Locations, Vehicles, Pilots y la polilínea de Places— y el único con OCHO ENDPOINTS, porque tres rutas fijas de acción (/assignment, /start y /finish) se reparten la escritura con el PATCH general. Los ocho exigen token JWT (Authorization: Bearer {token}); sin él la respuesta es 401 con «El token de sesión no es válido o ha expirado».

    PERMISOS POR RUTA, que no se deducen de las firmas. GET /api/trips y GET /api/trips/{trip}: CUALQUIER AUTENTICADO, sin role: —lo que cada uno alcanza lo decide el ámbito dentro del service, no el middleware—. POST /api/trips, PATCH /api/trips/{trip} y DELETE /api/trips/{trip}: SOLO administrator. PATCH /api/trips/{trip}/assignment: SOLO carrier, y además con carrier.required —un carrier sin empresa recibe 403 antes de llegar al service—. PATCH /api/trips/{trip}/start y /finish: SOLO pilot. Los 403 de rol traen «No tienes permisos para acceder a este recurso» y el de empresa, «Debes estar vinculado a un transportista para acceder a este recurso».

    ATENCIÓN — EL ÁMBITO DE LECTURA ES ADQUIRIDO, NO HEREDADO, Y ES EL AVISO CENTRAL DEL DOMINIO. Un viaje NO TIENE carrierId: nace de nadie, porque el administrador lo publica antes de saber quién lo hará. administrator y manager ven TODOS los viajes; un carrier ve los pending con pilotId y vehicleId en null —LA BOLSA DE VIAJES DISPONIBLES— MÁS los asignados por su propia empresa; un pilot ve SOLO aquellos donde pilotId es él, y LA BOLSA NO LE APARECE. assignedBy guarda el USUARIO que asignó, pero el filtro compara la EMPRESA de ese usuario, así que cualquier compañero de esa empresa ve el viaje y puede reasignarlo, y el viaje no queda huérfano de vista si esa persona se va. En el show, un viaje FUERA DE ÁMBITO ES 403, NO 404. Y el ámbito se aplica ANTES que los filtros: un ?status=pending desde otra empresa no revela los viajes ya tomados.

    ATENCIÓN — EL ADMINISTRADOR NO PUEDE ASIGNAR Y NO SE PUEDE DESASIGNAR. /assignment es exclusiva del carrier, porque asignar es el acto por el que una empresa TOMA el viaje; el PATCH general NO ACEPTA pilotId ni vehicleId y mandarlos se ignora en silencio con 200. pilot_id y vehicle_id NO VUELVEN NUNCA A null —no hay desasignación, y null en /assignment es 422—. Reasignar solo se puede mientras el viaje siga pending: en in_route o finished es 400. CONSECUENCIA REAL: si ninguna empresa toma un viaje, o lo toma la equivocada y no lo suelta, NADIE PUEDE DESATASCARLO POR API —la única salida es borrarlo y volver a crearlo—.

    ATENCIÓN — status NO TIENE MÁQUINA DE ESTADOS. El PATCH del administrador acepta los tres valores sin comprobar el orden y sin tocar las fechas: un finished puede volver a pending CONSERVANDO startDate y endDate, y quedan combinaciones que se contradicen con sus propias fechas (pending con las dos puestas, finished sin ninguna). AVISO PARA EL FRONTEND: cuando status y las fechas se contradigan, EL RELATO LO CUENTAN LAS FECHAS.

    ATENCIÓN — LA POLILÍNEA LA MANDA EL FRONTEND Y NO SE RECALCULA NUNCA. polyline es obligatoria en el alta Y en el PATCH; la resuelve el front con GET /api/places/directions (SPEC 16) y LA API NUNCA LLAMA A GOOGLE. Si un PATCH cambia locationId o departurePointId y no remanda la polilínea, la guardada queda OBSOLETA y la API no avisa.

    NORMALIZACIÓN ASIMÉTRICA: order y container se guardan EN MAYÚSCULAS con los espacios interiores colapsados; destination, transport y observations se guardan TAL COMO SE TECLEAN, con solo trim. NI order NI container SON ÚNICOS: dos viajes pueden compartir los dos.

    ATENCIÓN — EL REPARTO 422 / 400 ES LA TRAMPA PRINCIPAL DEL ALTA. Las cuatro claves foráneas llevan exists:, así que un id INVENTADO es 422; pero esa regla lee la tabla EN CRUDO y NO VE EL BORRADO LÓGICO, de modo que un clientId o un shippingLineId de una fila BORRADA pasa la validación y lo para el service con 400. Las demás reglas de negocio también son 400 desde el service, cada una con su mensaje literal: «El cliente seleccionado fue eliminado», «La naviera seleccionada fue eliminada», «El destino seleccionado no es un puerto», «El puerto de destino está inactivo», «El punto de partida está inactivo», «El usuario seleccionado no es un piloto», «El piloto seleccionado no pertenece a ninguna empresa transportista», «El vehículo seleccionado no está activo» y «El piloto y el vehículo deben pertenecer a la misma empresa transportista».

    CINCO CAMPOS SE DESCARTAN EN EL ALTA SIN ERROR: status, pilotId, vehicleId, assignedBy y registeredBy. El viaje nace pending, sin tripulación, y registeredBy sale del usuario autenticado. En el PATCH, además de pilotId y vehicleId, tampoco se reescriben assignedBy ni registeredBy.

    /start Y /finish NO TIENEN CUERPO. La fecha la pone el now() DEL SERVIDOR y mandarla en el body no se usa. start sobre un viaje ya iniciado es 400; finish sobre uno ya finalizado es 400, y sobre uno SIN startDate también es 400. Solo el piloto asignado las alcanza: otro piloto recibe 403.

    SOFT DELETES. El listado y el show EXCLUYEN los borrados y no hay parámetro que los devuelva —ni withTrashed, ni onlyTrashed—; un show de un borrado es 404, indistinguible de un id inexistente. En cambio PATCH, /assignment, /start, /finish y el segundo DELETE responden 400 «El viaje ya fue eliminado». NO EXISTE /restore.

    EL LISTADO: ocho filtros TOLERANTES (status, clientId, shippingLineId, locationId, pilotId, vehicleId, dateFrom/dateTo sobre recolectionDate, y search sobre order Y container), donde un valor inválido SE IGNORA y nunca vacía el listado ni da 422; dateFrom y dateTo se leen en Y-m-d estricto; orden fijo recolection_date DESC, id DESC, sin sortBy; y paginación OPT-IN por limit acotado a [10, 100].

    LA SALIDA NO ES LA MISMA EN EL LISTADO Y EN EL DETALLE. GET /api/trips devuelve TripListItem: 15 CLAVES pensadas para una tabla —sin los ids de las relaciones, sin clientName, sin destination ni transport, sin polyline ni points y sin createdAt, updatedAt ni deletedAt—. Los otros siete endpoints devuelven Trip: 31 claves en camelCase, con las seis relaciones como par id + nombre PLANO, nunca anidadas, y con points, un campo calculado en lectura, sin columna y sin caché, que SOLO se calcula en el detalle. En los dos esquemas las fechas usan el formato propio d-m-Y h:i:s A, NO ISO 8601, y status sale con el valor crudo del enum en inglés.

    IMPACTO SOBRE SPEC 22 Y SPEC 23: este dominio es el primer consumidor de Clients y de Shipping Lines, y por eso DELETE /api/clients/{client} y DELETE /api/shipping-lines/{shippingLine} responden AHORA 400 si el cliente o la naviera tienen viajes, INCLUIDOS LOS BORRADOS. Mensajes literales: «No se puede eliminar el cliente porque tiene viajes asociados» y «No se puede eliminar la naviera porque tiene viajes asociados». El resto del contrato de esos dos dominios queda intacto.
    TEXT,
)]
class TripController extends Controller
{
    #[OA\Get(
        path: '/api/trips',
        operationId: 'indexTrips',
        summary: 'Listar viajes',
        description: <<<'TEXT'
        Devuelve los viajes que el usuario autenticado tiene derecho a ver, con las relaciones ya resueltas como NOMBRE PLANO. NO LLEVA role:: lo puede llamar cualquiera de los cuatro roles y los cuatro obtienen 200; lo que cambia es QUÉ FILAS DEVUELVE.

        ATENCIÓN — DOS USUARIOS DISTINTOS RECIBEN LISTADOS DISTINTOS SOBRE LOS MISMOS DATOS. administrator y manager ven TODOS los viajes. Un carrier ve la BOLSA —los pending con pilotId y vehicleId en null, que están libres para cualquier empresa— MÁS los que asignó su propia empresa; en cuanto la empresa A toma un viaje, ese viaje DESAPARECE del listado de la empresa B. Un pilot ve SOLO aquellos donde pilotId es él: LA BOLSA NO LE APARECE, porque él no elige viajes, se los asignan. La comparación de empresa se hace sobre los usuarios de la empresa de assignedBy, así que un compañero ve el viaje que tomó otro.

        ATENCIÓN — EL ÁMBITO SE APLICA ANTES QUE LOS FILTROS. Un ?status=pending desde la empresa B no revela los viajes ya tomados por la A: los filtros solo pueden RECORTAR lo que el ámbito dejó pasar, nunca ampliarlo. No hay filtro por empresa asignataria ni por assignedBy: ni siquiera el administrador puede pedir «los viajes de la empresa X».

        ATENCIÓN — LOS VIAJES BORRADOS NO APARECEN NUNCA Y NO HAY FORMA DE VERLOS: no existe withTrashed, ni onlyTrashed, ni un status que los recupere, ni endpoint /restore. El total de la paginación tampoco los cuenta.

        LOS OCHO FILTROS SON TOLERANTES y se combinan entre sí: un status fuera del enum, un id no numérico, una fecha que no sea exactamente Y-m-d o un search en blanco SE IGNORAN EN SILENCIO y la lectura devuelve 200 con el listado completo, NUNCA 422. Un filtro sin coincidencias devuelve 200 con data vacío, tampoco 404. Cualquier otro query param se ignora.

        ATENCIÓN — EL LISTADO NO DEVUELVE EL RECURSO COMPLETO. Cada elemento es un TripListItem de 15 CLAVES, no el Trip de 31 del detalle: no vienen los ids de las relaciones (shippingLineId, locationId, pilotId, vehicleId…), ni clientId ni clientName, ni destination, ni transport, ni polyline, NI points, ni createdAt, updatedAt o deletedAt. De cada relación sale solo su nombre. Para pintar el mapa, filtrar por un id que salga de una fila o ver el resto de campos hay que pedir GET /api/trips/{trip}.

        El orden es FIJO y no configurable: recolection_date DESC —lo próximo a recoger primero— y, a igualdad, id DESC. No hay sortBy ni sortDir.

        La forma de la respuesta depende de limit: sin limit se devuelven todos los registros y el sobre NO trae total, currentPage ni lastPage; con un limit numérico salen esos tres APLANADOS EN LA RAÍZ, no bajo meta.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trips'],
        parameters: [
            new OA\Parameter(
                name: 'status',
                description: 'Estado exacto del viaje. Solo se aplica si coincide con uno de los tres valores del enum; cualquier otra cosa (status=PENDING, status=basura, status[]=pending) se ignora y devuelve el listado completo, nunca 422 ni lista vacía. ATENCIÓN — no amplía el ámbito: un carrier que pide status=pending sigue viendo solo la bolsa y lo suyo, no los pending que tomó otra empresa.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['pending', 'in_route', 'finished'], example: 'pending'),
            ),
            new OA\Parameter(
                name: 'clientId',
                description: 'Filtra por cliente exportador (comparación exacta contra client_id). Un valor no numérico se ignora. Un id que no existe devuelve 200 con data vacío, no 404.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 3),
            ),
            new OA\Parameter(
                name: 'shippingLineId',
                description: 'Filtra por naviera (comparación exacta contra shipping_line_id). Un valor no numérico se ignora.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 2),
            ),
            new OA\Parameter(
                name: 'locationId',
                description: 'Filtra por puerto de destino (comparación exacta contra location_id). Un valor no numérico se ignora. NO hay filtro por departurePointId ni por el destination de texto libre.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 9),
            ),
            new OA\Parameter(
                name: 'pilotId',
                description: 'Filtra por piloto asignado (comparación exacta contra pilot_id). Un valor no numérico se ignora. Para un pilot es redundante: su ámbito ya lo acota a sus propios viajes, así que pedir el id de otro devuelve lista vacía y nunca los viajes ajenos.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
            new OA\Parameter(
                name: 'vehicleId',
                description: 'Filtra por vehículo asignado (comparación exacta contra vehicle_id). Un valor no numérico se ignora.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 8),
            ),
            new OA\Parameter(
                name: 'dateFrom',
                description: 'Límite inferior sobre la FECHA DE RECOLECCIÓN planificada (no sobre createdAt ni sobre shipDate). ATENCIÓN — se lee en Y-m-d ESTRICTO: 2026-09-02 se aplica, pero 02-09-2026, 2026-9-2 o una fecha con hora SE IGNORAN en silencio y devuelven el listado completo, nunca 422. Compara por día completo.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: '2026-09-01'),
            ),
            new OA\Parameter(
                name: 'dateTo',
                description: 'Límite superior sobre la FECHA DE RECOLECCIÓN planificada, también en Y-m-d estricto y también tolerante. Compara POR DÍA COMPLETO: un viaje de las 18:00 entra en un dateTo de ese mismo día. Se combina con dateFrom; un rango invertido devuelve 200 con data vacío, no 422.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: '2026-09-30'),
            ),
            new OA\Parameter(
                name: 'search',
                description: 'Búsqueda parcial (LIKE %TERM%) sobre DOS campos a la vez: order Y container. El término se normaliza como una referencia —recorte, colapso de espacios y mayúsculas— y las dos columnas están siempre en mayúsculas, así que la búsqueda es insensible a mayúsculas: search=ord-2026, search=ORD-2026 y search=Ord-2026 funcionan igual. En blanco o de solo espacios se ignora. NO busca en destination, transport ni observations, y NO alcanza a los viajes borrados.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'ORD-2026'),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que ACTIVA la paginación. Si se omite, o si no es numérico (limit=abc), se devuelven todos los registros sin error y sin metadatos de paginación. Si es numérico se ACOTA al rango [10, 100]: limit=1 devuelve páginas de 10 y limit=500 devuelve páginas de 100. El listado NO decodifica polilíneas —points solo existe en el detalle—, así que un limit alto no encarece la respuesta por ese lado.',
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
                description: 'Viajes obtenidos correctamente. Sin limit se devuelve TripListResponse; con limit numérico, PaginatedTripListResponse, con total, currentPage y lastPage aplanados en la raíz del sobre. Cada elemento es un TripListItem de 15 claves, NO el Trip de 31 del detalle. Un listado vacío —porque el ámbito no deja ver ninguno o porque los filtros no casan— devuelve 200 con data vacío, nunca 404 ni 403. Los viajes borrados no se listan, y por eso el listado tampoco trae deletedAt.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/TripListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedTripListResponse'),
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
    public function index(Request $request, TripServiceInterface $tripService)
    {
        try {
            $trips = $tripService->getTrips(auth('api')->user(), $this->filters($request));

            $data = $trips instanceof LengthAwarePaginator
                ? new PaginatedResource($trips, TripListResource::class)
                : TripListResource::collection($trips);

            return ResponseHandler::success($data, 'Viajes obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/trips',
        operationId: 'storeTrip',
        summary: 'Publicar un viaje',
        description: <<<'TEXT'
        Publica un viaje de exportación. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403, aunque los tres sí puedan leer viajes.

        El cuerpo tiene DOCE CAMPOS Y LOS DOCE SON OBLIGATORIOS. El viaje NACE EN LA BOLSA: status pending, pilotId, vehicleId y assignedById en null, y registeredByName el del administrador autenticado.

        ATENCIÓN — CINCO CAMPOS SE DESCARTAN SIN ERROR: status, pilotId, vehicleId, assignedBy y registeredBy. Mandarlos no cambia nada y no da 422. EL ADMINISTRADOR NO PUEDE ASIGNAR TRIPULACIÓN NI AQUÍ NI EN NINGUNA OTRA RUTA: quien toma el viaje es una empresa transportista, con PATCH /api/trips/{trip}/assignment.

        ATENCIÓN — EL REPARTO 422 / 400 ES LA TRAMPA PRINCIPAL. Un id de catálogo INVENTADO es 422, porque las cuatro claves foráneas llevan exists:. Pero esa regla LEE LA TABLA EN CRUDO y NO VE EL BORRADO LÓGICO: un clientId o un shippingLineId de una fila BORRADA pasa la validación y lo para el service con 400. Y el exists: tampoco mira el type ni el status, así que un destino que no es puerto, un puerto inactivo o un punto de partida inactivo también caen en 400, no en 422.

        ATENCIÓN — LA POLILÍNEA ES OBLIGATORIA Y LA API NUNCA LLAMA A GOOGLE. Se resuelve antes con GET /api/places/directions (SPEC 16) y se manda ya calculada; el backend la guarda tal cual y no comprueba que corresponda al punto de partida y al puerto enviados.

        NORMALIZACIÓN ASIMÉTRICA: order y container se guardan en MAYÚSCULAS con espacios colapsados —el cliente debe pintar los de la respuesta, no los que tecleó el usuario—; destination, transport y observations se guardan tal cual, con solo trim. NI order NI container SON ÚNICOS: publicar dos viajes con la misma orden y el mismo contenedor es legal y no da ni 400 ni 422.

        Las dos fechas planificadas deben ser FUTURAS y shipDate nunca anterior a recolectionDate; las dos incumplencias son 422.

        IMPACTO SOBRE SPEC 22 Y SPEC 23: en cuanto un cliente o una naviera tienen su primer viaje, su DELETE pasa a responder 400 —«No se puede eliminar el cliente porque tiene viajes asociados» y «No se puede eliminar la naviera porque tiene viajes asociados»—, y la guarda mira también los viajes borrados.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreTripRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Trips'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Viaje registrado correctamente, con status pending, pilotId, pilotName, vehicleId, vehiclePlate, assignedById y assignedByName en null —el viaje entra en la bolsa—, startDate y endDate en null, registeredByName el del administrador autenticado, order y container ya normalizados en mayúsculas y deletedAt en null. Las fechas salen en el formato propio d-m-Y h:i:s A, no en el que se enviaron.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Viaje registrado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Trip'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio incumplida, nunca un valor mal formado. Cinco casos, cada uno con su mensaje literal: «El cliente seleccionado fue eliminado» y «La naviera seleccionada fue eliminada» —los dos llegan hasta aquí porque el exists: de Laravel NO VE EL BORRADO LÓGICO—; «El destino seleccionado no es un puerto» cuando la location es de tipo destination; «El puerto de destino está inactivo»; y «El punto de partida está inactivo». ATENCIÓN — un id que NO EXISTE en ninguna de las cuatro tablas no cae aquí: es 422.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí, aunque los tres sí puedan leer viajes—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos, con el formato propio de Laravel {message, errors} y NO con el sobre {statusCode, message, data} del resto de la API. Casos: falta cualquiera de los doce campos obligatorios; un clientId, shippingLineId, departurePointId o locationId que NO EXISTE (El cliente seleccionado no existe, La naviera seleccionada no existe, El punto de partida seleccionado no existe, El puerto de destino seleccionado no existe); una fecha mal formada o EN EL PASADO (La fecha de recolección debe ser futura / La fecha de embarque debe ser futura); un shipDate anterior al recolectionDate (La fecha de embarque no puede ser anterior a la de recolección); o un texto que supere los 255 caracteres. ATENCIÓN — un catálogo BORRADO, INACTIVO o QUE NO ES PUERTO no cae aquí: es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreTripRequest $request, TripServiceInterface $tripService)
    {
        try {
            $trip = $tripService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new TripResource($trip), 'Viaje registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/trips/{trip}',
        operationId: 'showTrip',
        summary: 'Obtener un viaje por id',
        description: <<<'TEXT'
        Devuelve un viaje concreto con sus 31 claves y las seis relaciones resueltas en pares planos. NO LLEVA role:: lo puede llamar cualquiera de los cuatro roles, pero el ÁMBITO decide si lo alcanza.

        ATENCIÓN — UN VIAJE FUERA DE ÁMBITO RESPONDE 403, NO 404, y es deliberado: el ámbito esconde filas de un listado, no pretende que nunca se publicaran. Es lo contrario del viaje borrado, que sí es 404. Los dos mensajes de 403 son distintos según el rol: un pilot que pide un viaje que no tiene asignado recibe «No puedes acceder a un viaje que no tienes asignado»; un carrier que pide uno tomado por otra empresa recibe «No puedes acceder a un viaje que no pertenece a tu empresa transportista».

        Qué alcanza cada rol: administrator y manager, CUALQUIER viaje; un carrier, los de la BOLSA —pending con pilotId y vehicleId en null— y los que asignó su propia empresa, comparando la empresa de assignedBy y no el usuario exacto; un pilot, SOLO aquellos donde pilotId es él —ni siquiera los de la bolsa—.

        ATENCIÓN — UN VIAJE BORRADO RESPONDE 404 CON EL MISMO MENSAJE QUE UN id INEXISTENTE («El viaje no existe»), y es deliberado: quien lee no distingue «ya no está» de «nunca existió». Las cinco rutas de escritura sí los distinguen, con 400 «El viaje ya fue eliminado». No hay parámetro que devuelva los borrados ni endpoint /restore.

        El deletedAt de esta respuesta es SIEMPRE null: por definición, aquí solo se alcanzan viajes vivos. No hay consulta por order ni por container: para eso está el filtro search del listado, que además tampoco es único.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trips'],
        parameters: [
            new OA\Parameter(
                name: 'trip',
                description: 'Identificador numérico del viaje (trips.id). NO es su order ni su container —ninguno de los dos es único y no existe consulta por ellos—. El id de un viaje borrado sigue ocupado en la base, pero ya no es alcanzable por esta ruta: responde 404.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Viaje obtenido correctamente. Siempre un viaje vivo y dentro del ámbito del usuario, con deletedAt en null, status con el valor crudo del enum en inglés, points ya decodificado desde polyline y las siete fechas con el formato d-m-Y h:i:s A, no ISO 8601.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Viaje obtenido correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Trip'),
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
                description: 'EL VIAJE EXISTE PERO ESTÁ FUERA DEL ÁMBITO DEL USUARIO, y por eso es 403 y no 404. Un pilot que pide un viaje que no tiene asignado —incluidos los de la bolsa— recibe «No puedes acceder a un viaje que no tienes asignado»; un carrier que pide uno ya tomado por otra empresa recibe «No puedes acceder a un viaje que no pertenece a tu empresa transportista». Un administrator y un manager no ven nunca este 403. Esta ruta NO lleva role: ni carrier.required, así que el 403 lo levanta siempre el service, no un middleware.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El viaje no existe, o EXISTE PERO ESTÁ BORRADO: los dos casos salen por el mismo mensaje «El viaje no existe» y no se pueden distinguir desde la lectura. Distinguirlos revelaría qué ids llegaron a existir. La escritura sí los separa, con 400 sobre un viaje borrado.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $trip, TripServiceInterface $tripService)
    {
        try {
            $found = $tripService->getTripById(auth('api')->user(), $trip);

            return ResponseHandler::success(new TripResource($found), 'Viaje obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/trips/{trip}',
        operationId: 'updateTrip',
        summary: 'Actualizar un viaje',
        description: <<<'TEXT'
        Edita los datos de un viaje. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403. La ruta acepta PATCH y PUT indistintamente y en ambos casos el comportamiento es el mismo: el PUT no reemplaza el recurso completo. NO comprueba ámbito, porque solo lo alcanza el administrador, que ve todos los viajes.

        Todos los campos son opcionales y solo se toca lo que venga. Un CUERPO VACÍO responde 200 como no-op, devolviendo el viaje sin cambios y sin mover updatedAt. Opcional no es vaciable: enviar una clave en blanco o con null es 422.

        ATENCIÓN — ESTE PATCH NO ASIGNA NI DESASIGNA. pilotId y vehicleId NO SE ACEPTAN y mandarlos SE IGNORA EN SILENCIO CON 200: el viaje conserva su asignación. Tampoco se reescriben assignedBy ni registeredBy. Cambiar la tripulación es cosa de PATCH /api/trips/{trip}/assignment, EXCLUSIVA DEL carrier. CONSECUENCIA REAL: si ninguna empresa toma un viaje, el administrador NO PUEDE DESATASCARLO por esta vía —la única salida es borrarlo y volver a crearlo—.

        ATENCIÓN — status SE ACEPTA Y NO HAY MÁQUINA DE ESTADOS. Los tres valores son válidos en cualquier orden y NO se tocan las fechas: un finished puede volver a pending CONSERVANDO startDate y endDate, y quedan combinaciones que se contradicen con sus propias fechas. Cuando status y fechas discrepen, EL RELATO LO CUENTAN LAS FECHAS. Un valor fuera del enum es 422. startDate y endDate no se aceptan por ninguna vía: las pone el servidor en /start y /finish.

        ATENCIÓN — LOS CUATRO CATÁLOGOS SE REVALIDAN SIEMPRE, aunque el cuerpo solo mueva una fecha: el service fusiona lo enviado sobre lo almacenado. Un viaje que apunta a un puerto que se desactivó DESPUÉS del alta responde 400 aunque el PATCH no toque locationId. Mismo reparto que el alta: id inventado 422, cliente o naviera BORRADOS 400.

        ATENCIÓN — SI SE CAMBIA locationId O departurePointId HAY QUE REMANDAR polyline EN EL MISMO PATCH. La API NO la recalcula, NO llama a Google y NO AVISA: la polilínea guardada queda obsoleta y el mapa dibuja una ruta que ya no corresponde, con points obsoleto también. Debe resolverse de nuevo con GET /api/places/directions.

        Las dos fechas planificadas DEJAN DE EXIGIR FUTURO aquí, al contrario que en el alta.

        ATENCIÓN — SOBRE UN VIAJE YA BORRADO ESTE ENDPOINT RESPONDE 400 «El viaje ya fue eliminado», NO 404. Es la asimetría del dominio: la lectura no distingue el borrado del inexistente, la escritura sí. Un id que nunca existió sigue siendo 404 aquí. Y NO EXISTE FORMA DE RESTAURAR UN VIAJE BORRADO: ni con este PATCH ni con ningún otro endpoint.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateTripRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Trips'],
        parameters: [
            new OA\Parameter(
                name: 'trip',
                description: 'Identificador numérico del viaje (trips.id). El id NO cambia al editar: corregir cualquier campo conserva la identidad del viaje y su asignación.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Viaje actualizado correctamente, con order y container ya normalizados y el resto de campos tal como se teclearon. Conserva su asignación —pilotId, vehicleId y assignedById intactos, aunque se hayan enviado—, su registeredByName y sus startDate y endDate. Un cuerpo vacío devuelve el viaje sin cambios, también con 200.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Viaje actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Trip'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio incumplida. Seis casos con su mensaje literal: «El viaje ya fue eliminado» —sobre una fila borrada, que aquí NO es 404—; «El cliente seleccionado fue eliminado»; «La naviera seleccionada fue eliminada»; «El destino seleccionado no es un puerto»; «El puerto de destino está inactivo»; y «El punto de partida está inactivo». ATENCIÓN — los cinco últimos pueden salir AUNQUE EL PATCH NO TOQUE ESE CAMPO, porque los cuatro catálogos se revalidan en cada edición con los valores almacenados.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es administrator. El mensaje del middleware role es: No tienes permisos para acceder a este recurso. ATENCIÓN — este 403 es siempre de rol, nunca de ámbito: el administrador ve todos los viajes, así que aquí no existe el 403 por recurso ajeno del show.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El id no corresponde a ningún viaje, ni vivo ni borrado. Mensaje: El viaje no existe. Un viaje que SÍ existió y fue borrado NO cae aquí: es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos, con el formato propio de Laravel {message, errors}. Casos: una clave enviada en blanco, de solo espacios o con null —opcional no es vaciable—; un id de catálogo que NO EXISTE; una fecha mal formada; un shipDate anterior al recolectionDate cuando las dos viajan juntas; un status fuera del enum (El estado del viaje no es válido); o un texto que supere los 255 caracteres. ATENCIÓN — pilotId y vehicleId NO producen 422: simplemente se ignoran.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateTripRequest $request, int $trip, TripServiceInterface $tripService)
    {
        try {
            /**
             * Sin el usuario: solo lo alcanza el administrador, que ve todos los viajes, así
             * que no queda ámbito que comprobar.
             */
            $updated = $tripService->update($trip, $request->validated());

            return ResponseHandler::success(new TripResource($updated), 'Viaje actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/trips/{trip}',
        operationId: 'destroyTrip',
        summary: 'Eliminar un viaje',
        description: <<<'TEXT'
        Da de baja un viaje. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        ES UN BORRADO LÓGICO (soft delete): la fila SIGUE EN LA BASE con su deleted_at puesto, pero DESAPARECE de la API para siempre. No se lista, no se consulta por id —el GET responde 404— y NO EXISTE NINGÚN PARÁMETRO —ni withTrashed, ni onlyTrashed, ni un status— que la devuelva, ni endpoint /restore. UN VIAJE BORRADO POR ERROR SOLO SE RECUPERA DESDE LA BASE DE DATOS.

        ATENCIÓN — ESTA ES LA ÚNICA RESPUESTA DE LA API QUE DEVUELVE deletedAt CON VALOR: pinta la fila que se acaba de borrar, con sus 31 claves. En los otros siete endpoints es siempre null.

        NO HAY CONFIRMACIÓN Y NO SE COMPRUEBA EL ESTADO: se borra igual un viaje pending que uno in_route o uno ya finished, y también uno que una empresa ya tomó, sin avisar a nadie —no hay notificaciones—. De hecho, BORRAR Y VOLVER A CREAR ES LA ÚNICA SALIDA cuando un viaje se queda atascado: el administrador no puede asignar y no se puede desasignar.

        EL SEGUNDO DELETE RESPONDE 400 «El viaje ya fue eliminado», NO 404, y así se distingue de un id que nunca existió, que sí es 404.

        Los catálogos a los que apuntaba el viaje NO se tocan, y borrarlo NO LIBERA NADA: la guarda que impide eliminar un cliente o una naviera con viajes MIRA TAMBIÉN LOS VIAJES BORRADOS, así que borrar el viaje no habilita borrar después su cliente ni su naviera —siguen respondiendo 400 «No se puede eliminar el cliente porque tiene viajes asociados» y «No se puede eliminar la naviera porque tiene viajes asociados»—.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trips'],
        parameters: [
            new OA\Parameter(
                name: 'trip',
                description: 'Identificador numérico del viaje (trips.id). Tras el borrado el id sigue existiendo en la base pero deja de ser alcanzable: el GET responde 404 y las cinco rutas de escritura, 400.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Viaje eliminado correctamente. La respuesta pinta la fila recién borrada y es LA ÚNICA de la API con deletedAt CON VALOR, en el formato propio d-m-Y h:i:s A. El resto de campos —incluida la asignación, si la tenía— se devuelven tal como quedaron.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Viaje eliminado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Trip'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'El viaje YA ESTABA BORRADO. Mensaje literal: «El viaje ya fue eliminado». Es lo que separa el segundo DELETE de un id inexistente, que es 404. El borrado NO es idempotente, al contrario que la baja lógica de Products, Locations o Departure Points.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es administrator. El mensaje del middleware role es: No tienes permisos para acceder a este recurso. Ni el carrier que tomó el viaje ni el piloto que lo conduce pueden borrarlo.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El id no corresponde a ningún viaje, ni vivo ni borrado. Mensaje: El viaje no existe.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $trip, TripServiceInterface $tripService)
    {
        try {
            $deleted = $tripService->destroy($trip);

            return ResponseHandler::success(new TripResource($deleted), 'Viaje eliminado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/trips/{trip}/assignment',
        operationId: 'assignTrip',
        summary: 'Tomar un viaje asignándole piloto y vehículo',
        description: <<<'TEXT'
        Es el acto por el que una EMPRESA TRANSPORTISTA TOMA un viaje: escribe pilotId, vehicleId y assignedBy de una sola vez, los tres juntos —no existe un viaje con piloto y sin assignedBy—.

        ATENCIÓN — ES EXCLUSIVA DEL ROL carrier Y ADEMÁS EXIGE EMPRESA. Lleva role:carrier Y carrier.required. UN administrator RECIBE 403 AQUÍ: no puede asignar por ninguna vía, porque asignar es decidir por el transportista, y el PATCH general tampoco acepta esos campos. Un manager y un pilot también reciben 403. Y un carrier QUE TODAVÍA NO HA REGISTRADO SU EMPRESA recibe 403 del middleware carrier.required, «Debes estar vinculado a un transportista para acceder a este recurso», ANTES DE LLEGAR AL SERVICE.

        QUIÉN PUEDE TOMAR QUÉ: un viaje de la BOLSA —pending con pilotId y vehicleId en null— está libre para CUALQUIER empresa; una vez tomado, solo la empresa de su assignedBy puede volver a tocarlo, y puede hacerlo CUALQUIER USUARIO de esa empresa, no solo la persona exacta que asignó. Otra empresa recibe 403 «No puedes asignar un viaje que ya tomó otra empresa transportista».

        ATENCIÓN — REASIGNAR SOLO MIENTRAS EL VIAJE SIGA pending. En in_route o finished la respuesta es 400 «Solo se puede asignar un viaje pendiente»: cambiarle el piloto a un viaje ya arrancado dejaría un startDate puesto por alguien que ya no aparece en el registro.

        ATENCIÓN — NO EXISTE LA DESASIGNACIÓN. Los dos campos son obligatorios y null es 422: pilot_id y vehicle_id NO VUELVEN NUNCA a null y el viaje no regresa a la bolsa. CONSECUENCIA REAL: si una empresa toma un viaje por error y no lo suelta, o si nadie lo toma, NADIE PUEDE DESATASCARLO POR API —la única salida es que el administrador lo borre y lo cree de nuevo—.

        REPARTO 422 / 400: un pilotId o un vehicleId INVENTADO es 422, por el exists:; que el usuario tenga rol pilot, que tenga empresa, que el vehículo esté active y que los dos sean de LA MISMA EMPRESA son reglas de negocio y salen como 400.

        Todo el chequeo corre DENTRO DE UNA TRANSACCIÓN CON BLOQUEO DE FILA, así que dos transportistas que intenten tomar el mismo viaje libre a la vez no se pisan: solo uno gana y el otro recibe 403 o 400, nunca dos escrituras.

        Sobre un viaje BORRADO responde 400 «El viaje ya fue eliminado», no 404.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AssignTripRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Trips'],
        parameters: [
            new OA\Parameter(
                name: 'trip',
                description: 'Identificador numérico del viaje (trips.id). La ruta se declara ANTES del apiResource, o el comodín {trip} capturaría «assignment» y lo resolvería como el detalle de un viaje con ese id.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Viaje asignado correctamente. Devuelve el viaje con pilotId/pilotName, vehicleId/vehiclePlate y assignedById/assignedByName ya escritos —assignedById es el usuario autenticado, nunca lo que venga en el cuerpo—. ATENCIÓN: el status NO cambia, sigue siendo pending; quien lo mueve es el piloto con /start. A partir de aquí el viaje SALE DE LA BOLSA y deja de verlo cualquier otra empresa.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Viaje asignado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Trip'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio incumplida. Seis casos con su mensaje literal: «El viaje ya fue eliminado»; «Solo se puede asignar un viaje pendiente» —reasignar uno in_route o finished—; «El usuario seleccionado no es un piloto»; «El piloto seleccionado no pertenece a ninguna empresa transportista»; «El vehículo seleccionado no está activo» —vale tanto para inactive como para under_repair—; y «El piloto y el vehículo deben pertenecer a la misma empresa transportista».',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'Tres orígenes distintos. Uno, el rol no es carrier —UN administrator TAMBIÉN CAE AQUÍ, junto al manager y al pilot—: «No tienes permisos para acceder a este recurso». Dos, es un carrier SIN EMPRESA REGISTRADA y lo para el middleware carrier.required antes del service: «Debes estar vinculado a un transportista para acceder a este recurso». Tres, el viaje YA LO TOMÓ OTRA EMPRESA: «No puedes asignar un viaje que ya tomó otra empresa transportista».',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El id no corresponde a ningún viaje, ni vivo ni borrado. Mensaje: El viaje no existe. Un viaje borrado NO cae aquí: es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos, con el formato propio de Laravel {message, errors}. Casos: falta pilotId o vehicleId —no se puede asignar solo uno— (El piloto es obligatorio / El vehículo es obligatorio); se envía NULL en cualquiera de los dos, porque LA DESASIGNACIÓN NO EXISTE; o el id NO EXISTE en su tabla (El piloto seleccionado no existe / El vehículo seleccionado no existe). ATENCIÓN — un piloto sin rol de piloto, un piloto sin empresa, un vehículo no activo o una pareja de empresas distintas NO caen aquí: son 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function assign(AssignTripRequest $request, int $trip, TripServiceInterface $tripService)
    {
        try {
            $assigned = $tripService->assign(auth('api')->user(), $trip, $request->validated());

            return ResponseHandler::success(new TripResource($assigned), 'Viaje asignado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/trips/{trip}/start',
        operationId: 'startTrip',
        summary: 'Iniciar un viaje',
        description: <<<'TEXT'
        Marca el arranque real del viaje: escribe startDate y mueve status a in_route.

        ATENCIÓN — NO TIENE CUERPO. No lleva FormRequest y no acepta ningún campo: LA FECHA LA PONE EL now() DEL SERVIDOR y mandarla en el body NO SE USA. Es deliberado: aceptarla del cliente permitiría a un piloto declarar que arrancó tres horas antes, y como no hay bitácora nadie lo notaría. El coste asumido es que un piloto sin señal no puede registrar el arranque a la hora en que ocurrió.

        ATENCIÓN — ES EXCLUSIVA DEL ROL pilot (middleware role:pilot): un administrator, un carrier o un manager reciben 403 del middleware. Y dentro, SOLO EL PILOTO ASIGNADO la alcanza: otro piloto —aunque sea de la misma empresa— recibe 403 «No puedes iniciar un viaje que no tienes asignado». La marca de ejecución es de quien conduce, no de su empresa.

        SOBRE UN VIAJE YA INICIADO ES 400 «El viaje ya fue iniciado»: se comprueba startDate, no el status, así que un viaje que el administrador devolvió a pending conservando su startDate TAMPOCO se puede volver a iniciar.

        Sobre un viaje BORRADO responde 400 «El viaje ya fue eliminado», y esa comprobación va ANTES que la del piloto: un piloto ajeno sobre un viaje borrado ve el 400, no el 403.

        No hay ruta inversa: no existe /unstart ni forma de limpiar startDate. Lo más parecido es que el administrador mueva el status a mano con el PATCH general, pero LA FECHA SE QUEDA.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trips'],
        parameters: [
            new OA\Parameter(
                name: 'trip',
                description: 'Identificador numérico del viaje (trips.id). Tiene que ser un viaje cuyo pilotId sea el usuario autenticado. La ruta se declara ANTES del apiResource, o el comodín {trip} capturaría «start».',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Viaje iniciado correctamente. Devuelve el viaje con startDate puesto con la HORA DEL SERVIDOR, en el formato d-m-Y h:i:s A, y status en in_route. endDate sigue en null y la asignación no cambia.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Viaje iniciado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Trip'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Dos casos con su mensaje literal: «El viaje ya fue eliminado» —sobre una fila borrada, que aquí NO es 404, y se comprueba antes que el piloto— y «El viaje ya fue iniciado» —el viaje ya tiene startDate, aunque su status haya vuelto a pending—.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'Dos orígenes. Uno, el rol no es pilot —administrator, carrier y manager caen aquí—: «No tienes permisos para acceder a este recurso». Dos, es un piloto PERO NO EL ASIGNADO A ESTE VIAJE, incluido el caso de un viaje todavía en la bolsa: «No puedes iniciar un viaje que no tienes asignado».',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El id no corresponde a ningún viaje, ni vivo ni borrado. Mensaje: El viaje no existe.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function start(int $trip, TripServiceInterface $tripService)
    {
        try {
            /** Sin FormRequest: la ruta no tiene cuerpo y la hora la pone el servidor. */
            $started = $tripService->start(auth('api')->user(), $trip);

            return ResponseHandler::success(new TripResource($started), 'Viaje iniciado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/trips/{trip}/finish',
        operationId: 'finishTrip',
        summary: 'Finalizar un viaje',
        description: <<<'TEXT'
        Marca el cierre real del viaje: escribe endDate y mueve status a finished.

        ATENCIÓN — NO TIENE CUERPO, igual que /start. No lleva FormRequest y no acepta ningún campo: LA FECHA LA PONE EL now() DEL SERVIDOR y mandarla en el body NO SE USA.

        ATENCIÓN — ES EXCLUSIVA DEL ROL pilot (middleware role:pilot): un administrator, un carrier o un manager reciben 403. Y dentro, SOLO EL PILOTO ASIGNADO la alcanza: otro piloto recibe 403 «No puedes finalizar un viaje que no tienes asignado».

        TRES CIERRES IMPOSIBLES, LOS TRES CON 400: sobre un viaje YA FINALIZADO, «El viaje ya fue finalizado»; sobre un viaje SIN startDate, «El viaje no ha sido iniciado» —un viaje no se cierra antes de empezar, por mucho que el administrador le haya movido el status a mano—; y sobre un viaje BORRADO, «El viaje ya fue eliminado». Las comprobaciones miran las FECHAS, no el status: un viaje que el administrador devolvió a pending conservando su endDate tampoco se puede volver a finalizar.

        El orden de las guardas es: viaje borrado (400) → piloto asignado (403) → ya finalizado (400) → sin iniciar (400).

        No hay ruta inversa: no existe /unfinish ni forma de limpiar endDate, y el cierre no dispara ninguna notificación ni ningún cálculo de costos —este dominio no cotiza nada—.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trips'],
        parameters: [
            new OA\Parameter(
                name: 'trip',
                description: 'Identificador numérico del viaje (trips.id). Tiene que ser un viaje cuyo pilotId sea el usuario autenticado y que YA TENGA startDate. La ruta se declara ANTES del apiResource, o el comodín {trip} capturaría «finish».',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Viaje finalizado correctamente. Devuelve el viaje con endDate puesto con la HORA DEL SERVIDOR, en el formato d-m-Y h:i:s A, y status en finished. startDate y la asignación no cambian.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Viaje finalizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Trip'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Tres casos con su mensaje literal: «El viaje ya fue eliminado» —sobre una fila borrada, que aquí NO es 404—; «El viaje ya fue finalizado» —ya tiene endDate—; y «El viaje no ha sido iniciado» —no tiene startDate: no se puede cerrar un viaje que nunca arrancó—.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'Dos orígenes. Uno, el rol no es pilot —administrator, carrier y manager caen aquí—: «No tienes permisos para acceder a este recurso». Dos, es un piloto PERO NO EL ASIGNADO A ESTE VIAJE: «No puedes finalizar un viaje que no tienes asignado».',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El id no corresponde a ningún viaje, ni vivo ni borrado. Mensaje: El viaje no existe.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function finish(int $trip, TripServiceInterface $tripService)
    {
        try {
            $finished = $tripService->finish(auth('api')->user(), $trip);

            return ResponseHandler::success(new TripResource($finished), 'Viaje finalizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the ten listing filters, all of them optional and all of them tolerant.
     *
     * @return array<string, string|null>
     */
    private function filters(Request $request): array
    {
        $filters = [];

        foreach (['status', 'clientId', 'shippingLineId', 'locationId', 'pilotId', 'vehicleId', 'dateFrom', 'dateTo', 'search', 'limit'] as $key) {
            $filters[$key] = $this->queryString($request, $key);
        }

        return $filters;
    }

    /**
     * Read a query parameter as a string, ignoring anything that is not one.
     *
     * An array or a nested value comes back as null, so `?status[]=pending` is ignored
     * instead of blowing up inside the service.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
