<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\TripPosition\StoreTripPositionRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\TripPosition\TripPositionResource;
use App\Interfaces\TripPosition\TripPositionServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Trip Positions',
    description: <<<'TEXT'
    Seguimiento en vivo del viaje: el piloto asignado reporta dónde está mientras el viaje está in_route, cada punto se guarda en trip_positions y se emite por websocket a quien esté mirando el mapa. DOS ENDPOINTS Y NINGUNO MÁS, los dos con token JWT (Authorization: Bearer {token}); sin él la respuesta es 401 con «El token de sesión no es válido o ha expirado».

    ATENCIÓN — ES LA PRIMERA RUTA ANIDADA DEL PROYECTO. SPEC 14 (gastos de vehículo) y SPEC 18 (características de accesorio) evitaron anidar a propósito y hacen viajar el vínculo en un query param OBLIGATORIO; aquí el id del viaje va EN LA URL: /api/trips/{trip}/positions. No existe ningún listado global de posiciones, ni /api/trip-positions, ni un filtro tripId en query.

    PERMISOS ASIMÉTRICOS, Y ES LA TRAMPA PRINCIPAL DEL DOMINIO. POST: SOLO pilot (middleware role:pilot) y además SOLO EL PILOTO ASIGNADO al viaje —un administrator, un carrier o un manager reciben 403 «No tienes permisos para acceder a este recurso» aunque sean quienes miran el mapa—. GET: la ruta NO lleva role:, pero el service da 403 A CUALQUIER pilot, INCLUIDO EL ASIGNADO AL VIAJE, con «No tienes permisos para consultar el rastro de un viaje». EL PILOTO EMITE Y NADA MÁS: queda fuera del GET y fuera del canal de websocket. Ninguna de las dos rutas lleva carrier.required.

    EL ÁMBITO DE LECTURA ES EL DE SPEC 24 Y NO SE REESCRIBE AQUÍ: administrator y manager alcanzan el rastro de CUALQUIER viaje; un carrier, los que asignó su empresa MÁS la bolsa libre (los pending sin tripulación); fuera de ámbito es 403 «No puedes acceder a un viaje que no pertenece a tu empresa transportista», NO 404. Un viaje inexistente o borrado es 404 «El viaje no existe» en el GET.

    ATENCIÓN — EL POST RESPONDE 201 O 200, Y SON COSAS DISTINTAS. 201 = el punto se escribió y el evento se emitió. 200 = PISO DE 15 SEGUNDOS: la petición llegó a menos de 15 s del último punto del viaje, así que NO SE ESCRIBIÓ NADA, NO SE EMITIÓ NADA y se devuelve el punto anterior tal cual. Es silencio deliberado —la app del piloto reintenta cuando la red va mal y devolverle un error la empujaría a lógica defensiva—, con el precedente del archivo ignorado de SPEC 19. LA APP DEBE DISTINGUIRLOS POR EL STATUS, no comparando coordenadas: dos puntos iguales seguidos son legítimos (un camión parado sigue reportando). El piso de 15 s es el ÚNICO freno del dominio: no hay rate limiting por IP ni por token.

    CUATRO GUARDAS DEL POST EN ORDEN FIJO, y ese orden es contrato: viaje inexistente → 404 «El viaje no existe»; viaje borrado → 400 «El viaje ya fue eliminado»; quien llama no es el pilot_id del viaje → 403 «No puedes reportar la posición de un viaje que no tienes asignado»; el viaje no está in_route → 400 «El viaje no está en ruta». CONSECUENCIA: un viaje BORRADO Y AJENO devuelve el 400 del borrado, no el 403 del ajeno.

    recordedAt Y pilotId NO SE ACEPTAN EN EL CUERPO: la hora la pone el servidor con now() y el autor sale del token. Mandarlos se descarta en silencio. NO HAY VALIDACIÓN GEOGRÁFICA: latitude entre -90 y 90, longitude entre -180 y 180 y nada más —ni contra la polyline del viaje, ni contra Guatemala, ni contra un salto físicamente posible—.

    LA MITAD DE LA FUNCIONALIDAD NO ES HTTP: EL WEBSOCKET. Cada punto escrito emite el evento .trip.position.updated (con el punto inicial, sin namespace PHP) sobre el canal PRIVADO trips.{tripId}, con SEIS CLAVES en el payload: tripId, latitude, longitude, recordedAt (d-m-Y h:i:s A), pilotId y pilotName —una más y una menos que el recurso HTTP: trae tripId y pilotName, que el recurso no tiene—. La suscripción se autoriza en POST /api/broadcasting/auth, con el MISMO Authorization: Bearer del resto de la API (no hay sesión ni cookie), y el callback aplica las mismas dos reglas: cualquier pilot recibe false, y el resto solo alcanza los viajes que ya vería por HTTP. Es PrivateChannel, no de presencia: nadie sabe quién más está mirando. No hay canal de flota (ni trips global ni carriers.{id}.trips): para seguir tres viajes hay que suscribirse a tres canales. No hay client events ni whisper: la única entrada es el POST. Y NO HAY REPLAY: quien se conecta a mitad de viaje pide el rastro con el GET y desde ahí escucha.

    ATENCIÓN — SI php artisan reverb:start NO ESTÁ CORRIENDO, NO LLEGA NADA Y LA API NO AVISA. El event() va envuelto en try/catch que registra en el log y sigue: la API responde 201 a cada POST, los puntos se guardan enteros y EL MAPA SIMPLEMENTE NO SE MUEVE, sin ningún error visible. Perder el aviso en vivo nunca puede costar el dato. Si «no llega nada», el síntoma es de servidor, no de código.

    NADA SE EDITA NI SE BORRA. No hay PATCH ni DELETE de un punto, no hay purga por antigüedad, la tabla no tiene deleted_at y el DELETE (baja lógica) del viaje NO TOCA trip_positions. Una coordenada mala es historial.

    LO QUE ESTE DOMINIO NO CAMBIÓ: TripResource y TripListResource siguen exactamente igual —el viaje NO gana lastLatitude, lastLongitude ni lastPositionAt—, la tabla trips no gana ni una columna y los ocho endpoints de SPEC 24 responden como antes.
    TEXT,
)]
class TripPositionController extends Controller
{
    #[OA\Get(
        path: '/api/trips/{trip}/positions',
        operationId: 'indexTripPositions',
        summary: 'Consultar el rastro de un viaje',
        description: <<<'TEXT'
        Devuelve los puntos que el piloto ha ido reportando durante el viaje, para pintar el recorrido REAL en un mapa —no la ruta prevista, que es la polyline del detalle del viaje—.

        LA RUTA NO LLEVA role:, PERO NO LA ALCANZAN LOS CUATRO ROLES. El service rechaza con 403 A CUALQUIER pilot, INCLUIDO EL ASIGNADO AL VIAJE, con «No tienes permisos para consultar el rastro de un viaje»: el piloto emite y nada más, su app ya conoce su propia posición y no gana nada escuchando el eco. Los otros tres roles entran, acotados por el ámbito de SPEC 24: administrator y manager alcanzan cualquier viaje; un carrier, los que asignó su empresa MÁS los pending sin tripulación (la bolsa libre). Un carrier fuera de ámbito recibe 403 «No puedes acceder a un viaje que no pertenece a tu empresa transportista», NO 404: se le confirma que el viaje existe, igual que en el detalle del viaje.

        ATENCIÓN — SIN limit DEVUELVE EL RASTRO ENTERO, y es el primer listado del proyecto donde eso puede significar MILES DE ELEMENTOS: un viaje de seis horas reportando al ritmo del piso de 15 segundos deja unas 1 440 filas, y nada se borra nunca. Un frontend que pinte el mapa sin limit sobre un viaje largo se traerá todo de golpe. La paginación se mantiene opt-in por coherencia con el resto del proyecto; el aviso está aquí, no forzado por la API.

        ATENCIÓN — EL limit SE ACOTA A [10, 100], A DIFERENCIA DE GET /api/trips, que no tiene piso de 10 y respeta el tamaño pedido tal cual. Aquí limit=1 y limit=5 devuelven páginas de 10.

        EL ORDEN ES recorded_at ASCENDENTE —del principio al final del viaje, AL REVÉS que el resto de listados del proyecto, que van del más nuevo al más viejo— con desempate por id ascendente, para que dos puntos del mismo segundo salgan siempre igual. No hay sortBy ni sortDir.

        NO HAY NI UN SOLO FILTRO: no existe dateFrom, ni dateTo, ni pilotId, ni recorte por tramo. Cualquier query param que no sea limit o page se ignora. Tampoco hay forma de pedir solo el último punto: para eso está el websocket.

        Un viaje inexistente o BORRADO es 404 «El viaje no existe», indistinguibles entre sí. Un viaje sin puntos —todavía pending, o in_route sin que el piloto haya reportado aún— devuelve 200 con data vacío, NUNCA 404.

        CADA ELEMENTO TRAE CINCO CLAVES Y NO INCLUYE tripId: quien pregunta ya lo lleva en la URL. latitude y longitude salen como STRING de ocho decimales.

        USO PREVISTO: pedir el rastro una vez al abrir el mapa y, desde ahí, escuchar el evento .trip.position.updated en el canal privado trips.{tripId}. Reverb NO reenvía lo perdido, así que esa costura —rastro histórico por HTTP + puntos nuevos por websocket— la cose el frontend.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trip Positions'],
        parameters: [
            new OA\Parameter(
                name: 'trip',
                description: 'Id numérico del viaje cuyo rastro se consulta (trips.id), EN LA URL: es la primera ruta anidada del proyecto y no hay query param tripId que lo sustituya. Un id inexistente o de un viaje borrado devuelve 404 «El viaje no existe»; uno fuera del ámbito del usuario, 403.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que ACTIVA la paginación: si se omite, o si no es numérico (limit=abc), se devuelve EL RASTRO COMPLETO sin error y sin metadatos de paginación —que en un viaje largo son miles de elementos—. Si es numérico se ACOTA a [10, 100]: limit=1 y limit=5 devuelven páginas de 10, y limit=500 devuelve páginas de 100. ATENCIÓN — aquí SÍ hay piso de 10, al contrario que en GET /api/trips.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 10, example: 10),
            ),
            new OA\Parameter(
                name: 'page',
                description: 'Página solicitada. Solo tiene efecto cuando se envía un limit numérico. Como el orden es recorded_at ascendente, page=1 es el PRINCIPIO del viaje y la última página, el final.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Posiciones obtenidas correctamente. Sin limit se devuelve TripPositionListResponse con el rastro entero; con limit numérico, PaginatedTripPositionListResponse, con total, currentPage y lastPage APLANADOS EN LA RAÍZ del sobre, no bajo meta. Los puntos van ordenados por recorded_at ASCENDENTE. Un viaje sin puntos devuelve 200 con data vacío, nunca 404.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/TripPositionListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedTripPositionListResponse'),
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
                description: 'Sin permiso para leer este rastro. DOS CAUSAS DISTINTAS con mensajes distintos: cualquier pilot —INCLUIDO EL ASIGNADO AL VIAJE— recibe «No tienes permisos para consultar el rastro de un viaje»; un carrier que pide un viaje asignado por otra empresa recibe «No puedes acceder a un viaje que no pertenece a tu empresa transportista». Fuera de ámbito es 403, NO 404.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El viaje no existe o fue borrado —los dos casos son indistinguibles a propósito—. El mensaje devuelto es: El viaje no existe. ATENCIÓN — que el viaje esté borrado no significa que su rastro se haya perdido: las filas siguen en la tabla, simplemente ya no hay forma de leerlas por API.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function index(Request $request, int $trip, TripPositionServiceInterface $tripPositionService)
    {
        try {
            $positions = $tripPositionService->getPositions(
                auth('api')->user(),
                $trip,
                ['limit' => $this->queryString($request, 'limit')],
            );

            $data = $positions instanceof LengthAwarePaginator
                ? new PaginatedResource($positions, TripPositionResource::class)
                : TripPositionResource::collection($positions);

            return ResponseHandler::success($data, 'Posiciones obtenidas correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Record one point of the trip's track.
     *
     * Answers **201** when the point was written and **200** when the 15 second floor
     * discarded it and the previous point is being handed back: the pilot's app can
     * tell one from the other by the status alone, without comparing coordinates.
     */
    #[OA\Post(
        path: '/api/trips/{trip}/positions',
        operationId: 'storeTripPosition',
        summary: 'Reportar una posición del viaje',
        description: <<<'TEXT'
        Registra UN punto del rastro y lo emite por websocket a quien esté mirando el mapa. Es EXCLUSIVO del rol pilot (middleware role:pilot) y, además, SOLO DEL PILOTO ASIGNADO al viaje: un administrator, un carrier o un manager reciben 403 «No tienes permisos para acceder a este recurso» —aunque sean justo ellos quienes leen el rastro—, y otro piloto cualquiera, 403 «No puedes reportar la posición de un viaje que no tienes asignado».

        ATENCIÓN — RESPONDE 201 O 200, Y SON COSAS DISTINTAS. Es la decisión con más probabilidad de confundir a quien integre. 201 «Posición registrada correctamente» = la fila se escribió y el evento se emitió. 200 «Posición recibida correctamente» = PISO DE 15 SEGUNDOS: la petición llegó a menos de 15 s del último punto de ese viaje, así que NO SE ESCRIBIÓ NADA, NO SE EMITIÓ NADA y se devuelve EL PUNTO ANTERIOR TAL CUAL —su id y su recordedAt son los de antes—. La respuesta es indistinguible de un alta salvo por el status, y COMPARAR COORDENADAS NO SIRVE PARA DISTINGUIRLAS: dos puntos idénticos seguidos son legítimos, porque un camión parado sigue reportando. LA APP DEL PILOTO DEBE MIRAR EL STATUS. Se responde 200 y no 400 a propósito: la app reintenta cuando la red va mal y devolverle un error la empujaría a lógica defensiva propia. El piso es POR VIAJE y es el ÚNICO freno del dominio: no hay rate limiting por IP ni por token.

        CUATRO GUARDAS EN ORDEN FIJO, Y ESE ORDEN ES CONTRATO: 1) viaje inexistente → 404 «El viaje no existe»; 2) viaje borrado → 400 «El viaje ya fue eliminado»; 3) el que llama no es el pilot_id del viaje → 403 «No puedes reportar la posición de un viaje que no tienes asignado»; 4) el viaje no está in_route → 400 «El viaje no está en ruta». CONSECUENCIA REAL: un viaje BORRADO Y AJENO devuelve el 400 del borrado, no el 403 del ajeno. Y NO EXISTE «posición del piloto» suelta: sin un viaje in_route asignado no hay dónde mandar nada —un viaje pending o finished es 400—.

        ATENCIÓN — recordedAt Y pilotId NO SE ACEPTAN Y MANDARLOS SE DESCARTA EN SILENCIO, sin 422. La hora la pone el servidor con now() —aceptar la del dispositivo la haría falsificable y desordenaría el rastro— y el autor sale del token. UN PUNTO POR PETICIÓN: no hay envío en lote, así que un tramo sin cobertura se PIERDE ENTERO y el mapa saltará en recta entre dos puntos lejanos, sin que nada indique que faltó señal.

        NINGUNA VALIDACIÓN GEOGRÁFICA: solo los rangos del sistema de coordenadas. No se comprueba que el punto caiga cerca de la polyline del viaje, ni dentro de Guatemala, ni que el salto contra el punto anterior sea físicamente posible. UN PILOTO PUEDE REPORTAR NORUEGA Y LA API LO GUARDA CON 201.

        EFECTO WEBSOCKET (solo en el 201): se emite .trip.position.updated sobre el canal PRIVADO trips.{tripId}, con seis claves —tripId, latitude, longitude, recordedAt, pilotId y pilotName—. La suscripción se autoriza en POST /api/broadcasting/auth con el mismo Authorization: Bearer, y ningún pilot es admitido en el canal, ni siquiera el que acaba de emitir. ATENCIÓN — SI php artisan reverb:start NO ESTÁ CORRIENDO NO LLEGA NADA Y ESTA RESPUESTA SIGUE SIENDO 201: el fallo del broadcast se registra en el log y la petición continúa, porque perder el aviso en vivo nunca puede costar el dato. El mapa se queda quieto sin ningún error visible.

        EL PUNTO ES INMUTABLE Y ETERNO: no hay PATCH ni DELETE de una posición, y el DELETE (baja lógica) del viaje no borra ninguna fila. Reportar tampoco toca el viaje: no cambia su status ni sus fechas, y TripResource sigue sin lastLatitude, lastLongitude ni lastPositionAt.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreTripPositionRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Trip Positions'],
        parameters: [
            new OA\Parameter(
                name: 'trip',
                description: 'Id numérico del viaje sobre el que se reporta (trips.id), EN LA URL: no viaja en el cuerpo ni en un query param. Debe ser un viaje EXISTENTE, NO BORRADO, ASIGNADO AL PILOTO QUE LLAMA y EN ESTADO in_route; cada incumplimiento tiene su propio código en ese orden (404, 400, 403, 400).',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Posición registrada correctamente: la fila QUEDÓ ESCRITA en trip_positions y el evento .trip.position.updated se emitió al canal privado trips.{tripId} —salvo que Reverb esté caído, en cuyo caso la respuesta sigue siendo 201 y solo queda constancia en el log—. recordedAt es la hora del SERVIDOR y pilotId, el del token, no lo que viniera en el cuerpo. Las coordenadas vuelven como STRING de ocho decimales, así que no son idénticamente las enviadas.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Posición registrada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/TripPosition'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 200,
                description: 'PISO DE 15 SEGUNDOS — NO SE GUARDÓ NADA. La petición llegó a menos de 15 s del último punto del viaje, así que NO se escribió ninguna fila y NO se emitió ningún evento: lo que vuelve en data es EL PUNTO ANTERIOR tal cual, con su id y su recordedAt de antes. Silencio deliberado, no un error: la app reintenta cuando la red va mal. ATENCIÓN — hay que distinguirlo del 201 POR EL STATUS, no comparando coordenadas, porque dos puntos iguales seguidos son legítimos.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Posición recibida correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/TripPosition'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio incumplida, con DOS mensajes posibles: «El viaje ya fue eliminado» —se comprueba ANTES que la asignación, así que un viaje borrado y ajeno da este 400 y no el 403— y «El viaje no está en ruta», cuando el viaje sigue pending o ya está finished. NO existe la posición suelta: sin un viaje in_route asignado no hay dónde reportar.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'Sin permiso para reportar. DOS CAUSAS DISTINTAS: el middleware role:pilot rechaza a administrator, carrier y manager con «No tienes permisos para acceder a este recurso»; y un pilot que no es el asignado al viaje recibe del service «No puedes reportar la posición de un viaje que no tienes asignado».',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El viaje no existe. El mensaje devuelto es: El viaje no existe. Es la PRIMERA de las cuatro guardas del service, pero solo se llega a ella con un cuerpo VÁLIDO: un id inventado con un cuerpo inválido devuelve 422, no 404, porque el FormRequest se resuelve antes que el controlador.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Coordenadas ausentes o fuera del sistema de referencia. Mensajes literales: La latitud es obligatoria / La latitud debe ser un número / La latitud debe estar entre -90 y 90 / La longitud es obligatoria / La longitud debe ser un número / La longitud debe estar entre -180 y 180. Un cuerpo vacío señala las dos. ATENCIÓN — es lo PRIMERO en fallar después del middleware de rol: el FormRequest se resuelve antes que el controlador, así que un cuerpo inválido devuelve 422 aunque el viaje no exista, esté borrado, sea ajeno o no esté en ruta.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreTripPositionRequest $request, int $trip, TripPositionServiceInterface $tripPositionService)
    {
        try {
            $user = auth('api')->user();

            $position = $tripPositionService->create($user, $trip, $request->validated());

            /** Un punto recién escrito no puede haber sido creado antes de esta petición. */
            $wasRecorded = $position->wasRecentlyCreated;

            return ResponseHandler::success(
                new TripPositionResource($position),
                $wasRecorded ? 'Posición registrada correctamente' : 'Posición recibida correctamente',
                $wasRecorded ? 201 : 200,
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
