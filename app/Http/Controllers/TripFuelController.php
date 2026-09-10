<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\TripFuel\StoreTripFuelRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\TripFuel\TripFuelResource;
use App\Interfaces\TripFuel\TripFuelServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Trip Fuels',
    description: <<<'TEXT'
    Cargas de combustible del viaje: la empresa transportista registra los galones que asigna al camión y el PILOTO ASIGNADO las confirma. TRES ENDPOINTS Y NINGUNO MÁS, los tres con token JWT (Authorization: Bearer {token}); sin él la respuesta es 401 con «El token de sesión no es válido o ha expirado».

    LAS TRES RUTAS, con sus roles: POST /api/trips/{trip}/fuels (role:carrier) registra una carga SIN CONFIRMAR; GET /api/trips/{trip}/fuels (jwt.auth a secas) lista las del viaje con el ámbito de SPEC 24; y PATCH /api/trip-fuels/{tripFuel}/confirm (role:pilot) escribe la fecha de confirmación. Ninguna de las tres lleva carrier.required: el ámbito lo resuelve el service.

    ATENCIÓN — LAS DOS PRIMERAS ESTÁN ANIDADAS BAJO {trip} y la tercera NO. La confirmación vive en /api/trip-fuels/{tripFuel}/confirm porque el id de la carga ya identifica el viaje, así que {trip} sería un parámetro de adorno. NO EXISTE UN LISTADO GLOBAL: no hay GET /api/trip-fuels, ni filtro tripId en query, ni endpoint de detalle GET /api/trip-fuels/{tripFuel}. El índice va siempre viaje → cargas.

    ATENCIÓN — NI administrator NI manager ALCANZAN LAS DOS RUTAS DE ESCRITURA. Registrar es del carrier que tomó el viaje y confirmar es del pilot asignado; los otros roles reciben 403 «No tienes permisos para acceder a este recurso». Un administrador NO puede cargar, confirmar ni desbloquear nada: el PATCH general de un viaje no toca trip_fuels. En la LECTURA entran los cuatro roles, y AQUÍ EL PILOTO ASIGNADO SÍ LEE —a diferencia de GET /api/trips/{trip}/positions (SPEC 26), donde cualquier pilot recibe 403—: aquí el dato es sobre él.

    CUATRO GUARDAS DEL POST EN ORDEN FIJO, y ese orden es contrato: viaje inexistente → 404 «El viaje no existe»; viaje borrado → 400 «El viaje ya fue eliminado»; el viaje NO lo tomó la empresa de quien llama, INCLUIDO EL CASO DE UN VIAJE SIN ASIGNAR → 403 «No puedes registrar combustible en un viaje que no tomó tu empresa transportista»; el viaje está finished → 400 «El viaje ya fue finalizado». CONSECUENCIA: un viaje BORRADO Y AJENO devuelve el 400 del borrado, no el 403. Se carga en pending Y en in_route —una recarga en carretera es el caso real—, nunca después. Un carrier sin empresa registrada recibe 403 «No perteneces a ninguna empresa transportista» desde el service, no desde un middleware.

    ATENCIÓN — LA BOLSA LIBRE SE LEE PERO NO SE CARGA. Un viaje pending sin asignar entra en el ámbito de lectura de SPEC 24, así que el GET responde 200 con data vacío y totalGallons "0.00", mientras el POST sobre ese mismo viaje responde 403: una carga sin piloto que la confirme nacería atascada.

    ATENCIÓN — ESTE DOMINIO CAMBIÓ DOS ENDPOINTS DE SPEC 24. Uno: PATCH /api/trips/{trip}/assignment PASA DE DOS CAMPOS A CUATRO —pilotId, vehicleId, fuelGallons y fuelType—, CAMBIO INCOMPATIBLE SIN PERIODO DE GRACIA, y crea la primera carga dentro de su propia transacción. Dos: PATCH /api/trips/{trip}/start gana un cuarto 400, «Debes confirmar al menos una carga de combustible antes de iniciar el viaje». /finish NO comprueba nada de combustible. Y TripResource pasa de 34 a 35 claves con totalFuelGallons.

    ATENCIÓN — LOS VIAJES ASIGNADOS ANTES DE ESTA SPEC NO PUEDEN ARRANCAR. Tienen cero cargas, totalFuelGallons en "0.00" y /start les responde 400. NO HUBO BACKFILL a propósito: la única salida es que su empresa haga el POST —que acepta pending y también in_route— y que el piloto confirme.

    NADA SE EDITA, NADA SE BORRA Y NADA SE DESCONFIRMA. La tabla es APPEND-ONLY, como trip_positions: no hay PATCH de galones, no hay DELETE de una carga, loaded_at no vuelve nunca a null y el DELETE (baja lógica) del viaje no toca ninguna fila. Una carga mal tecleada es permanente y no se puede compensar, porque los galones no admiten negativos.

    ESTE DOMINIO NO ES CONTABILIDAD: no se guarda precio, ni costo, ni moneda, ni proveedor, ni factura. fuelType NO se comprueba contra fuel_prices (SPEC 06) —es una etiqueta, no una llave foránea— y tampoco se cruza con vehicles.kilometersPerGallon ni con la distancia del viaje. Tampoco hay galones realmente recibidos: el piloto confirma o no confirma, sin cantidad propia ni observación.

    NO SE EMITE NADA. Registrar o confirmar una carga NO manda correo y NO emite por Reverb: el canal privado trips.{tripId} sigue llevando solo posiciones. Y TripListResource sigue en 15 claves, GET /api/trips/current no cambia de forma y GET /api/trips no gana ningún filtro de combustible.
    TEXT,
)]
class TripFuelController extends Controller
{
    #[OA\Get(
        path: '/api/trips/{trip}/fuels',
        operationId: 'indexTripFuels',
        summary: 'Listar las cargas de combustible de un viaje',
        description: <<<'TEXT'
        Devuelve las cargas de combustible del viaje —confirmadas y pendientes, mezcladas— más el acumulado de las CONFIRMADAS en la raíz del sobre.

        LA RUTA NO LLEVA role: Y LA ALCANZAN LOS CUATRO ROLES, acotados por el ámbito de SPEC 24, que este dominio no reescribe: administrator y manager alcanzan cualquier viaje; un carrier, los que asignó su empresa MÁS la bolsa libre (los pending sin tripulación); y un pilot, SOLO aquellos donde él es el pilotId. ATENCIÓN — AQUÍ EL PILOTO ASIGNADO SÍ LEE, al contrario que en GET /api/trips/{trip}/positions, donde cualquier pilot recibe 403: allí él es el emisor y leerse a sí mismo no aporta, aquí el dato es sobre él y lo necesita. Fuera de ámbito es 403 y no 404 —se confirma que el viaje existe—, con dos mensajes distintos según el rol.

        Un viaje SIN NINGUNA CARGA devuelve 200 con data vacío y totalGallons "0.00", NUNCA 404: es el estado de todo viaje pending sin asignar y de todos los viajes asignados antes de SPEC 27. Un viaje BORRADO o inexistente es 404 «El viaje no existe», indistinguibles entre sí.

        ATENCIÓN — totalGallons NO ES total. totalGallons es la SUMA de los galones de las cargas CONFIRMADAS, como cadena de dos decimales, calculada sobre la consulta clonada y ANTES de paginar; total es el CONTEO de cargas que aporta el paginador y solo aparece al paginar. Con 25 cargas y ?limit=10 la respuesta trae 10 elementos en data, total en 25 y totalGallons con el total del viaje, no el de la página. SOLO CUENTAN LAS CONFIRMADAS: un viaje recién asignado devuelve "0.00" teniendo ya una carga en data con isConfirmed en false, y ese cero es explicable, no un error. Es el mismo número que el totalFuelGallons del detalle del viaje.

        NO HAY NI UN SOLO FILTRO: no existe fuelType, ni isConfirmed, ni dateFrom, ni dateTo, ni pilotId. Cualquier query param que no sea limit o page se ignora, y separar confirmadas de pendientes es trabajo del frontend con la clave isConfirmed.

        EL ORDEN ES FIJO id ASCENDENTE —la cronología real de registro, de la primera carga a la última—, sin sortBy ni order. ATENCIÓN — es al revés que el rastro de SPEC 26, que ordena por recorded_at: aquí loadedAt es nullable y no sirve para ordenar, y created_at empataría entre dos cargas del mismo segundo.

        ATENCIÓN — EL limit SE ACOTA A [10, 100], a diferencia de GET /api/trips, que no tiene piso de 10 y respeta el tamaño pedido tal cual.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trip Fuels'],
        parameters: [
            new OA\Parameter(
                name: 'trip',
                description: 'Id numérico del viaje cuyas cargas se consultan (trips.id), EN LA URL: no hay query param tripId que lo sustituya ni listado global de cargas. Un id inexistente o de un viaje borrado devuelve 404 «El viaje no existe»; uno fuera del ámbito del usuario, 403.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que ACTIVA la paginación: si se omite, o si no es numérico (limit=abc), se devuelven TODAS las cargas del viaje sin error y sin metadatos de paginación. Si es numérico se ACOTA a [10, 100]: limit=1 y limit=5 devuelven páginas de 10, y limit=500 devuelve páginas de 100. Paginar NO cambia totalGallons, que se calcula antes de paginar sobre todas las cargas confirmadas del viaje.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 10, example: 10),
            ),
            new OA\Parameter(
                name: 'page',
                description: 'Página solicitada. Solo tiene efecto cuando se envía un limit numérico. Como el orden es id ascendente, page=1 son las cargas más antiguas del viaje y la última página, las más recientes.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cargas de combustible obtenidas correctamente. Sin limit se devuelve TripFuelListResponse con todas las cargas del viaje; con limit numérico, PaginatedTripFuelListResponse, con total, currentPage y lastPage APLANADOS EN LA RAÍZ del sobre, no bajo meta. LAS DOS FORMAS INCLUYEN totalGallons en la raíz. Un viaje sin cargas devuelve data vacío y totalGallons "0.00", nunca 404.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/TripFuelListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedTripFuelListResponse'),
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
                description: 'El viaje existe pero queda fuera del ámbito de quien pregunta, con DOS mensajes según el rol: un pilot que no es el asignado recibe «No puedes acceder a un viaje que no tienes asignado» —el asignado SÍ entra, a diferencia del rastro de SPEC 26—; y un carrier que pide un viaje tomado por otra empresa recibe «No puedes acceder a un viaje que no pertenece a tu empresa transportista». Fuera de ámbito es 403, NO 404. ATENCIÓN — la bolsa libre (un pending sin asignar) NO cae aquí: es 200 con data vacío, aunque el POST sobre ese mismo viaje sí sea 403.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El viaje no existe o fue borrado —los dos casos son indistinguibles a propósito—. El mensaje devuelto es: El viaje no existe. ATENCIÓN — que el viaje esté borrado no significa que sus cargas se hayan perdido: las filas siguen en la tabla, simplemente ya no hay forma de leerlas por API.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function index(Request $request, int $trip, TripFuelServiceInterface $tripFuelService)
    {
        try {
            $result = $tripFuelService->getTripFuels(
                auth('api')->user(),
                $trip,
                ['limit' => $this->queryString($request, 'limit')],
            );

            $fuels = $result['fuels'];

            $data = $fuels instanceof LengthAwarePaginator
                ? (new PaginatedResource($fuels, TripFuelResource::class))->resolve()
                : ['data' => TripFuelResource::collection($fuels)->resolve()];

            /** El acumulado viaja en la raíz del sobre junto a la metadata de paginación, y también sin ella: es dato de negocio, no del paginador. */
            $data['totalGallons'] = $result['totalGallons'];

            return ResponseHandler::success($data, 'Cargas de combustible obtenidas correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/trips/{trip}/fuels',
        operationId: 'storeTripFuel',
        summary: 'Registrar una carga de combustible en un viaje',
        description: <<<'TEXT'
        Registra UNA carga de combustible sobre un viaje que la empresa transportista ya tomó. Es la segunda vía de alta del dominio: la PRIMERA carga de todo viaje la crea PATCH /api/trips/{trip}/assignment, y este endpoint sirve para las siguientes —una recarga en carretera es el caso real—.

        ATENCIÓN — ES EXCLUSIVO DEL ROL carrier (middleware role:carrier): un administrator, un manager o un pilot reciben 403 «No tienes permisos para acceder a este recurso». EL ADMINISTRADOR NO PUEDE CARGAR COMBUSTIBLE POR NINGUNA VÍA, y su PATCH general de un viaje no escribe nada en trip_fuels. La ruta NO lleva carrier.required: un carrier sin empresa registrada lo para el SERVICE con 403 «No perteneces a ninguna empresa transportista».

        ATENCIÓN — SOLO LA EMPRESA QUE TOMÓ EL VIAJE. La comparación cae sobre la EMPRESA de assignedBy, no sobre la persona: cualquier compañero de esa empresa puede registrar cargas, no solo quien asignó. Y un viaje SIN ASIGNAR NO ES LIBRE AQUÍ, al contrario que en la lectura: la bolsa se lee con 200 pero cargarla es 403, porque una carga sin piloto que la confirme nacería atascada.

        CUATRO GUARDAS EN ORDEN FIJO, Y ESE ORDEN ES CONTRATO: 1) viaje inexistente → 404 «El viaje no existe»; 2) viaje borrado → 400 «El viaje ya fue eliminado»; 3) el viaje no lo tomó tu empresa, o no está asignado → 403 «No puedes registrar combustible en un viaje que no tomó tu empresa transportista»; 4) el viaje está finished → 400 «El viaje ya fue finalizado». CONSECUENCIA REAL: un viaje BORRADO Y AJENO devuelve el 400 del borrado, no el 403 del ajeno.

        SE CARGA EN pending Y EN in_route, NUNCA DESPUÉS. Un viaje ya finalizado no recibe nada más, y no hay ninguna otra restricción de estado: no hace falta que el piloto haya confirmado la carga anterior para registrar otra.

        ATENCIÓN — LA CARGA NACE SIN CONFIRMAR Y NO SUMA TODAVÍA. La respuesta trae isConfirmed en false, loadedAt en null y confirmedByName en null, y estos galones NO entran ni en el totalGallons del listado ni en el totalFuelGallons del viaje hasta que el piloto asignado pase por PATCH /api/trip-fuels/{tripFuel}/confirm. Registrar no es confirmar, y quien registra NO puede confirmar: son roles distintos.

        ATENCIÓN — SIN VALIDACIÓN CRUZADA Y SIN VUELTA ATRÁS. gallons solo se valida numérico y mayor que 0: no se compara contra vehicles.kilometersPerGallon, ni contra la distancia del viaje, ni contra un techo, ni contra lo ya cargado. Y la tabla es APPEND-ONLY —no hay PATCH ni DELETE de una carga—, así que un 450 tecleado en vez de un 45 se queda para siempre y no se puede compensar, porque los galones no admiten negativos. EL FRONTEND DEBE CONFIRMAR LA CANTIDAD ANTES DE MANDAR ESTA PETICIÓN.

        Cada carga lleva SU PROPIO fuelType: dos cargas del mismo viaje pueden no coincidir y ambas se guardan. Tampoco hay control de duplicados: dos cargas idénticas seguidas son legítimas —dos camionadas iguales— y nada las serializa, porque sumar es conmutativo.

        NO SE EMITE NADA: registrar una carga no manda correo y no emite por Reverb. El canal privado trips.{tripId} sigue llevando solo posiciones. Y el viaje no cambia: registrar no toca su status ni sus fechas.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreTripFuelRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Trip Fuels'],
        parameters: [
            new OA\Parameter(
                name: 'trip',
                description: 'Id numérico del viaje sobre el que se registra la carga (trips.id), EN LA URL: no viaja en el cuerpo. Debe ser un viaje EXISTENTE, NO BORRADO, TOMADO POR LA EMPRESA DE QUIEN LLAMA y NO FINALIZADO; cada incumplimiento tiene su propio código en ese orden (404, 400, 403, 400).',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Carga de combustible registrada correctamente. La fila quedó escrita en trip_fuels y nace SIN CONFIRMAR: isConfirmed en false, loadedAt en null y confirmedByName en null. registeredByName es el usuario autenticado, nunca lo que venga en el cuerpo, y gallons vuelve como STRING de dos decimales, así que no es idénticamente el valor enviado. Estos galones no suman en ningún total hasta que el piloto confirme.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Carga de combustible registrada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/TripFuel'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio incumplida, con DOS mensajes posibles: «El viaje ya fue eliminado» —se comprueba ANTES que la empresa, así que un viaje borrado y ajeno da este 400 y no el 403— y «El viaje ya fue finalizado», que es la ÚLTIMA de las cuatro guardas. Un viaje pending o in_route pasa las dos.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'Sin permiso para registrar. TRES CAUSAS DISTINTAS con mensajes distintos: el middleware role:carrier rechaza a administrator, manager y pilot con «No tienes permisos para acceder a este recurso»; un carrier que todavía no ha registrado su empresa recibe del service «No perteneces a ninguna empresa transportista»; y un carrier cuya empresa no tomó el viaje —INCLUIDO EL CASO DE UN VIAJE SIN ASIGNAR, que sí se puede leer— recibe «No puedes registrar combustible en un viaje que no tomó tu empresa transportista».',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El viaje no existe. El mensaje devuelto es: El viaje no existe. Es la PRIMERA de las cuatro guardas del service, pero solo se llega a ella con un cuerpo VÁLIDO: un id inventado con un cuerpo inválido devuelve 422, no 404, porque el FormRequest se resuelve antes que el controlador. Un viaje BORRADO no cae aquí: es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Galones o tipo de combustible ausentes o inválidos, con el formato propio de Laravel {message, errors}. Mensajes literales: Los galones son obligatorios / Los galones deben ser un número / Los galones deben ser mayores a 0 / El tipo de combustible es obligatorio / El tipo de combustible seleccionado no es válido. Un cuerpo vacío señala los dos campos, y 0 o un negativo en gallons caen aquí. ATENCIÓN — es lo PRIMERO en fallar después del middleware de rol: el FormRequest se resuelve antes que el controlador, así que un cuerpo inválido devuelve 422 aunque el viaje no exista, esté borrado, sea ajeno o ya esté finalizado.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreTripFuelRequest $request, int $trip, TripFuelServiceInterface $tripFuelService)
    {
        try {
            $fuel = $tripFuelService->create(auth('api')->user(), $trip, $request->validated());

            return ResponseHandler::success(
                new TripFuelResource($fuel),
                'Carga de combustible registrada correctamente',
                201,
            );
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Confirm one fuel load on behalf of the assigned pilot.
     *
     * Always **200**, whether the date was just written or it was already there: the
     * confirmation is a fact that happened once, and a retry from a phone on a bad
     * network must not see an error.
     */
    #[OA\Patch(
        path: '/api/trip-fuels/{tripFuel}/confirm',
        operationId: 'confirmTripFuel',
        summary: 'Confirmar una carga de combustible',
        description: <<<'TEXT'
        El piloto asignado da fe de haber recibido la carga: escribe loadedAt con el now() del SERVIDOR y confirmedBy con su propio id. Es lo que convierte los galones en combustible que cuenta: HASTA QUE SE CONFIRMA, la carga NO suma en el totalGallons del listado ni en el totalFuelGallons del viaje, y el viaje NO PUEDE ARRANCAR —PATCH /api/trips/{trip}/start responde 400 «Debes confirmar al menos una carga de combustible antes de iniciar el viaje»—.

        ATENCIÓN — NO TIENE CUERPO. No lleva FormRequest y no acepta ningún campo: mandar loadedAt, gallons o cualquier otra cosa NO CAMBIA NADA y no da 422, igual que en /start y /finish de SPEC 24. Confirmar es dar fe de lo asignado, no reportar una cantidad distinta: si mandara un número propio dejaría de ser una confirmación y habría dos cifras que cuadrar.

        ATENCIÓN — LA RUTA NO ESTÁ ANIDADA, a diferencia de las otras dos del dominio: es /api/trip-fuels/{tripFuel}/confirm y el parámetro es el id de LA CARGA, no del viaje —el id de la carga ya identifica el viaje—. No existe ningún GET /api/trip-fuels/{tripFuel} de detalle: el id se obtiene del listado del viaje.

        ATENCIÓN — ES EXCLUSIVA DEL ROL pilot (middleware role:pilot) Y ADEMÁS SOLO DEL PILOTO ASIGNADO al viaje de esa carga: un administrator, un carrier o un manager reciben 403 del middleware —ni siquiera la empresa que registró la carga puede confirmarla—, y cualquier otro piloto recibe 403 «No puedes confirmar la carga de un viaje que no tienes asignado».

        ATENCIÓN — RECONFIRMAR ES 200 SIN ESCRIBIR NADA. Si loadedAt ya tiene valor se devuelve la carga TAL CUAL, con su fecha ORIGINAL y su confirmedByName original, sin tocar la fila. Es silencio deliberado y no un 400: loadedAt es un hecho que ya ocurrió, y un móvil con mala señal reintenta y no debe ver un error —el precedente es el piso de 15 segundos de SPEC 26—. El mensaje es el mismo en los dos casos, así que LA RESPUESTA NO DISTINGUE una primera confirmación de una repetida: si hace falta saberlo, hay que comparar loadedAt con la hora de la llamada.

        ATENCIÓN — NO SE MIRA EL status DEL VIAJE. Se puede confirmar la carga de un viaje ya finished —papeleo atrasado— y también de uno pending o in_route: prohibirlo solo crearía filas imposibles de cerrar. Lo único que se comprueba es que el viaje de la carga tenga a quien llama como pilot_id.

        ATENCIÓN — LA CARGA DE UN VIAJE BORRADO NO SE PUEDE CONFIRMAR, Y EL CÓDIGO ES 403, NO 400. Este endpoint no tiene una guarda propia de borrado como las otras rutas del proyecto: el viaje se resuelve por la relación, que no ve las filas con borrado lógico, así que la carga queda «sin viaje» y hasta su propio piloto recibe 403 «No puedes confirmar la carga de un viaje que no tienes asignado». Es indistinguible de una carga ajena.

        NO SE PUEDE DESCONFIRMAR: loadedAt no vuelve nunca a null por ninguna vía, y tampoco hay forma de rechazar una carga ni de anotar una discrepancia. Confirmar no toca el viaje: no cambia su status ni sus fechas, no manda correo y no emite por Reverb.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trip Fuels'],
        parameters: [
            new OA\Parameter(
                name: 'tripFuel',
                description: 'Id numérico de LA CARGA de combustible (trip_fuels.id), no del viaje: esta ruta es la única del dominio que no está anidada bajo {trip}. Se obtiene del listado GET /api/trips/{trip}/fuels, porque no hay endpoint de detalle. Un id inexistente devuelve 404 «La carga de combustible no existe»; una carga de un viaje que no es del piloto autenticado, 403.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 37),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Carga de combustible confirmada correctamente. MISMO STATUS Y MISMO MENSAJE en los dos casos: si la carga estaba sin confirmar, loadedAt trae el now() del servidor y confirmedByName al piloto autenticado; si YA ESTABA CONFIRMADA, se devuelve la carga tal cual con su loadedAt ORIGINAL y no se escribió nada. A partir de aquí estos galones SÍ suman en totalGallons y en totalFuelGallons.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Carga de combustible confirmada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/TripFuel'),
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
                description: 'Sin permiso para confirmar. DOS ORÍGENES: el middleware role:pilot rechaza a administrator, carrier y manager con «No tienes permisos para acceder a este recurso» —la empresa que registró la carga TAMPOCO puede confirmarla—; y un pilot que no es el pilot_id del viaje de esa carga recibe del service «No puedes confirmar la carga de un viaje que no tienes asignado». ESE MISMO 403 CUBRE DOS CASOS MÁS, indistinguibles del anterior: una carga cuyo viaje todavía no tiene piloto asignado, y una carga cuyo viaje FUE BORRADO —no hay 400 de borrado en este endpoint—.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El id no corresponde a ninguna carga. El mensaje devuelto es: La carga de combustible no existe. No hay más 404 en este endpoint: nada se borra nunca de trip_fuels, así que un id que existió sigue existiendo.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function confirm(int $tripFuel, TripFuelServiceInterface $tripFuelService)
    {
        try {
            $fuel = $tripFuelService->confirm(auth('api')->user(), $tripFuel);

            return ResponseHandler::success(
                new TripFuelResource($fuel),
                'Carga de combustible confirmada correctamente',
                200,
            );
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Read a query parameter as a string, ignoring anything that is not one.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
