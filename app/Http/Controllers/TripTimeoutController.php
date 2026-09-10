<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\TripTimeout\TripTimeoutResource;
use App\Interfaces\TripTimeout\TripTimeoutServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Trip Timeouts',
    description: <<<'TEXT'
    Paradas del camión durante el viaje: dónde se quedó quieto, desde cuándo y hasta cuándo, derivadas automáticamente del rastro de SPEC 26. UN SOLO ENDPOINT Y NINGUNO MÁS: GET /api/trips/{trip}/timeouts, con token JWT (Authorization: Bearer {token}); sin él la respuesta es 401 con «El token de sesión no es válido o ha expirado».

    ATENCIÓN — NADIE CREA UNA PARADA, Y ES LA TRAMPA PRINCIPAL DEL DOMINIO. NO HAY POST, NI PATCH, NI DELETE: no existe /api/trip-timeouts, ni un cuerpo que mandar, ni forma de corregir una parada mal detectada. Las paradas NACEN COMO EFECTO LATERAL del POST /api/trips/{trip}/positions —el endpoint del piloto de SPEC 26—, y SOLO EN LA RAMA DEL 201: una petición descartada por el piso de 15 segundos (el 200) no abre, no cierra y no toca ninguna fila. Una parada mal detectada es historial y se queda, igual que una coordenada mala en SPEC 26.

    CÓMO NACE Y CÓMO MUERE, QUE ES LO QUE HAY QUE ENTENDER PARA LEER LA RESPUESTA. Al escribir cada punto se evalúa una sola regla, en este orden: 1) si el viaje NO tiene parada abierta, se mide la distancia contra el PUNTO ANTERIOR del rastro, y a menos de 5 metros se ABRE una parada anclada EN ESE PUNTO ANTERIOR —su hora, su id y sus coordenadas—, no en el punto que la detectó; 2) si el viaje YA tiene parada abierta, se mide contra el ANCLA de esa parada, y a 5 metros o MÁS se CIERRA con el recordedAt y el id del punto nuevo. Por debajo del umbral no se toca ni una columna. El primer punto de un viaje no abre nada: no hay contra qué medir. Y un punto NUNCA cierra y abre en la misma petición, porque el punto que cierra una parada es, por definición, un punto en movimiento.

    LA SEGUNDA VÍA DE CIERRE ES EL PATCH /api/trips/{trip}/finish: al finalizar el viaje, la parada que siguiera abierta se cierra con ended_at = now() y endPositionId QUEDA EN NULL. ESE NULL ES LA ÚNICA FORMA DE DISTINGUIR «cerrada porque el camión se movió» de «cerrada porque el viaje terminó»: no hay closeReason, ni type, ni enum. Ninguna otra escritura del viaje toca las paradas: ni el PATCH general del administrador, ni /assignment, ni /start, ni el DELETE (baja lógica), que conserva las paradas igual que conserva el rastro.

    ATENCIÓN — EL UMBRAL SON 5 METROS Y NO SE CONFIGURA. Se calcula con Haversine sobre las dos coordenadas, sin PostGIS, y vive en una constante del código: no hay query param, ni ajuste por viaje, ni por empresa. Consecuencia asumida con el ruido real del GPS: un receptor parado que derive puede cerrar y reabrir paradas cortas en cadena, y un camión en marcha muy lenta puede no abrir ninguna.

    NO HAY UMBRAL MÍNIMO DE DURACIÓN: SE REGISTRA TODA PARADA, semáforos incluidos —la resolución mínima es el propio piso de 15 segundos—, así que un recorrido urbano puede dejar DECENAS DE FILAS y el GET sin limit las devuelve todas. La API no decide qué parada importa: filtrar por durationMinutes es del frontend.

    PERMISOS: la ruta lleva jwt.auth A SECAS —sin role: y sin carrier.required—, pero NO la alcanzan los cuatro roles. El service da 403 A CUALQUIER pilot, INCLUIDO EL ASIGNADO AL VIAJE, con «No tienes permisos para consultar las paradas de un viaje»: EL PILOTO EMITE Y NADA MÁS, exactamente como en el rastro y en el canal de websocket de SPEC 26. Los otros tres roles entran acotados por el ÁMBITO DE SPEC 24, que no se reescribe aquí: administrator y manager alcanzan cualquier viaje; un carrier, los que asignó su empresa MÁS la bolsa libre (los pending sin tripulación); fuera de ámbito es 403 «No puedes acceder a un viaje que no pertenece a tu empresa transportista», NO 404. Un viaje inexistente o borrado es 404 «El viaje no existe».

    NO HAY WEBSOCKET PARA LAS PARADAS. No se emite nada al abrir ni al cerrar una: no hay evento ni canal nuevos, y el payload de .trip.position.updated SIGUE CON SUS SEIS CLAVES intactas (tripId, latitude, longitude, recordedAt, pilotId, pilotName). Quien mira el mapa se entera de la parada al ver que el punto no se mueve, o pidiendo este GET.

    LO QUE ESTE DOMINIO NO CAMBIÓ: TripResource sigue con 34 claves y TripListResource con 15 —el viaje NO gana openTimeoutId, isStopped ni totalStoppedMinutes—, la tabla trips no gana ni una columna, GET /api/trips/{trip}/positions responde exactamente igual que antes y el POST de posiciones conserva su cuerpo de dos campos, sus cuatro guardas en el mismo orden y su piso de 15 segundos.

    SIN BACKFILL: los viajes anteriores a SPEC 27 no ganan paradas —nadie recorre su rastro hacia atrás—, y un viaje que ya estaba in_route al desplegar empieza a detectarlas desde su siguiente punto. Un listado vacío puede significar «no paró», «no reportó» o «es anterior a la spec», y el frontend no puede distinguirlos.
    TEXT,
)]
class TripTimeoutController extends Controller
{
    /**
     * List the stops detected on the trip's track.
     *
     * The only method of the controller: nothing creates, edits or deletes a stop by
     * request. They are born as a side effect of `POST /api/trips/{trip}/positions` and
     * closed either by the point that proves the truck moved or by the trip's finish.
     */
    #[OA\Get(
        path: '/api/trips/{trip}/timeouts',
        operationId: 'indexTripTimeouts',
        summary: 'Consultar las paradas de un viaje',
        description: <<<'TEXT'
        Devuelve las paradas detectadas durante el viaje —los tramos en que el camión estuvo quieto—, para marcarlas en el mapa junto al rastro o para explicar por qué un viaje tardó lo que tardó. NO LAS CREA NADIE: se derivan solas al escribir cada punto del rastro, así que este GET es el único endpoint del dominio.

        LA RUTA NO LLEVA role:, PERO NO LA ALCANZAN LOS CUATRO ROLES. El service rechaza con 403 A CUALQUIER pilot, INCLUIDO EL ASIGNADO AL VIAJE, con «No tienes permisos para consultar las paradas de un viaje»: el piloto emite y nada más, igual que con el rastro y con el canal de websocket de SPEC 26. Los otros tres entran acotados por el ámbito de SPEC 24: administrator y manager alcanzan cualquier viaje; un carrier, los que asignó su empresa MÁS los pending sin tripulación (la bolsa libre). Un carrier fuera de ámbito recibe 403 «No puedes acceder a un viaje que no pertenece a tu empresa transportista», NO 404: se le confirma que el viaje existe, igual que en el detalle del viaje y en el rastro.

        EL ORDEN ES started_at ASCENDENTE —de la primera parada del viaje a la última, AL REVÉS que la mayoría de listados del proyecto, que van del más nuevo al más viejo, e igual que el rastro de SPEC 26— con desempate por id ascendente, para que dos paradas que arrancasen en el mismo segundo salgan siempre en el mismo orden. No hay sortBy ni sortDir.

        NO HAY NI UN SOLO FILTRO: no existe open, ni dateFrom, ni dateTo, ni minDurationMinutes, ni pilotId. Cualquier query param que no sea limit o page SE IGNORA EN SILENCIO —?open=true&dateFrom=2026-01-01&sortDir=desc devuelve el listado completo con 200, nunca 422—. Tampoco se puede pedir solo la parada abierta: hay que buscar en el listado la que trae endedAt en null, y como mucho hay una por viaje.

        ATENCIÓN — SIN limit SE DEVUELVEN TODAS LAS PARADAS. SPEC 27 no puso umbral mínimo de duración: se registra toda parada, semáforos incluidos, así que un recorrido urbano puede dejar decenas de filas de 15 a 30 segundos y nada se borra jamás. La paginación se mantiene opt-in por coherencia con el resto del proyecto; el filtrado por durationMinutes es del frontend.

        ATENCIÓN — EL limit SE ACOTA A [10, 100], igual que en el rastro y a diferencia de GET /api/trips, que no tiene piso de 10 y respeta el tamaño pedido tal cual. Aquí limit=1 y limit=3 devuelven páginas de 10, y limit=500 devuelve páginas de 100.

        Un viaje inexistente o BORRADO es 404 «El viaje no existe», indistinguibles entre sí —el viaje borrado conserva sus paradas en la tabla, simplemente ya no hay forma de leerlas por API—. Un viaje SIN PARADAS —todavía pending, in_route sin reposos detectados, o anterior a SPEC 27, porque no hubo backfill— devuelve 200 con data vacío, NUNCA 404.

        CÓMO LEER CADA ELEMENTO, en nueve claves y sin tripId: latitude y longitude son las del ANCLA (el primer punto del reposo, no el que detectó la parada) y salen como STRING de ocho decimales; startedAt y endedAt van en d-m-Y h:i:s A y NO en ISO 8601; endedAt y durationMinutes son NULL mientras la parada siga abierta; y endPositionId nulo CON endedAt puesto significa que la cerró el PATCH /api/trips/{trip}/finish, que es la única forma de distinguir las dos causas de cierre.

        USO PREVISTO: pedir el rastro con GET /api/trips/{trip}/positions para pintar la línea y este listado para marcar los pines de las paradas. NO HAY EVENTO DE WEBSOCKET para las paradas: quien mire el mapa en vivo verá que el punto no se mueve, y este GET es la única forma de saber desde cuándo.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trip Timeouts'],
        parameters: [
            new OA\Parameter(
                name: 'trip',
                description: 'Id numérico del viaje cuyas paradas se consultan (trips.id), EN LA URL: la ruta es anidada, como la del rastro, y no existe ningún listado global de paradas ni un query param tripId que lo sustituya. Un id inexistente o de un viaje borrado devuelve 404 «El viaje no existe»; uno fuera del ámbito del usuario, 403.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que ACTIVA la paginación: si se omite, o si no es numérico (limit=abc), se devuelven TODAS las paradas del viaje sin error y sin metadatos de paginación. Si es numérico se ACOTA a [10, 100]: limit=1 y limit=3 devuelven páginas de 10, y limit=500 devuelve páginas de 100. ATENCIÓN — aquí SÍ hay piso de 10, al contrario que en GET /api/trips.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 10, example: 10),
            ),
            new OA\Parameter(
                name: 'page',
                description: 'Página solicitada. Solo tiene efecto cuando se envía un limit numérico. Como el orden es started_at ascendente, page=1 trae las primeras paradas del viaje y la última página, las más recientes.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paradas obtenidas correctamente. Sin limit se devuelve TripTimeoutListResponse con todas las paradas del viaje; con limit numérico, PaginatedTripTimeoutListResponse, con total, currentPage y lastPage APLANADOS EN LA RAÍZ del sobre, no bajo meta. Van ordenadas por started_at ASCENDENTE con desempate por id. Un viaje sin paradas devuelve 200 con data vacío, nunca 404.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/TripTimeoutListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedTripTimeoutListResponse'),
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
                description: 'Sin permiso para leer estas paradas. DOS CAUSAS DISTINTAS con mensajes distintos: cualquier pilot —INCLUIDO EL ASIGNADO AL VIAJE— recibe «No tienes permisos para consultar las paradas de un viaje», porque el piloto emite y nada más; un carrier que pide un viaje asignado por otra empresa recibe «No puedes acceder a un viaje que no pertenece a tu empresa transportista». Fuera de ámbito es 403, NO 404.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El viaje no existe o fue borrado —los dos casos son indistinguibles a propósito—. El mensaje devuelto es: El viaje no existe. ATENCIÓN — que el viaje esté borrado no significa que sus paradas se hayan perdido: las filas siguen en la tabla, igual que su rastro, simplemente ya no hay forma de leerlas por API. Y un viaje que existe pero no tiene paradas NO da 404: da 200 con data vacío.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function index(Request $request, int $trip, TripTimeoutServiceInterface $tripTimeoutService)
    {
        try {
            $timeouts = $tripTimeoutService->getTimeouts(
                auth('api')->user(),
                $trip,
                ['limit' => $this->queryString($request, 'limit')],
            );

            $data = $timeouts instanceof LengthAwarePaginator
                ? new PaginatedResource($timeouts, TripTimeoutResource::class)
                : TripTimeoutResource::collection($timeouts);

            return ResponseHandler::success($data, 'Paradas obtenidas correctamente', 200);
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
