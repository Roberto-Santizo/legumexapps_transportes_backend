<?php

namespace App\Http\Resources\TripTimeout;

use App\Models\TripTimeout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin TripTimeout
 */
#[OA\Schema(
    schema: 'TripTimeout',
    title: 'Parada detectada en el rastro de un viaje',
    description: <<<'TEXT'
    Una parada del camión: dónde se quedó quieto, desde cuándo y hasta cuándo. NUEVE CLAVES en camelCase y ninguna más, en este orden: id, latitude, longitude, startedAt, endedAt, durationMinutes, pilotId, startPositionId y endPositionId.

    ATENCIÓN — NADIE CREA ESTE OBJETO. No hay POST, ni PATCH, ni DELETE de una parada: nace como efecto lateral del POST /api/trips/{trip}/positions —solo en la rama del 201, nunca en el 200 del piso de 15 segundos— cuando un punto llega a menos de 5 metros del anterior. Una parada mal detectada es historial y se queda.

    ATENCIÓN — latitude Y longitude SON LAS DEL ANCLA, NO LAS DEL PUNTO QUE DETECTÓ LA PARADA. El ancla es el PUNTO ANTERIOR, el primero del reposo: «está parado desde las 08:14» es el dato útil, y anclar la parada en el punto que la detectó perdería el primer tramo. Salen como STRING de ocho decimales («14.62820000», «-90.52290000»), igual que en TripPosition y en Location: el frontend debe convertirlas antes de pintarlas (parseFloat) y no comparar puntos por igualdad de cadena. Van copiadas en la fila a propósito, para que el mapa pinte el pin sin un segundo viaje a la base.

    ATENCIÓN — NO TRAE tripId. Quien pide las paradas con GET /api/trips/{trip}/timeouts ya lo lleva en la URL, exactamente como en TripPosition. Si el frontend mezcla paradas de varios viajes en la misma estructura, tiene que anotar el viaje por su cuenta.

    DOS ESTADOS Y NINGÚN ENUM: lo dice endedAt. Con endedAt en null la parada SIGUE ABIERTA —el camión seguía quieto la última vez que reportó— y durationMinutes también es null. Con endedAt puesto, la parada está cerrada.

    ATENCIÓN — endPositionId NULO CON endedAt PUESTO SIGNIFICA QUE LA CERRÓ EL FIN DEL VIAJE, no que el camión se moviera. Es la ÚNICA forma de distinguir las dos causas de cierre: no hay closeReason, ni type, ni enum. Con endPositionId puesto, la cerró el punto cuyo id es ese, a 5 metros o más del ancla.

    NO HAY MOTIVO NI TELEMETRÍA: sin reason, sin notes, sin catálogo de causas, sin velocidad, rumbo ni precisión del GPS. La parada se deduce SOLO de la distancia entre dos puntos, y ni el piloto ni nadie explica por qué se detuvo. Tampoco hay umbral mínimo de duración: SE REGISTRA TODA PARADA, semáforos incluidos —un recorrido urbano puede dejar decenas de filas de 15 a 30 segundos—, y filtrar por durationMinutes es del consumidor.

    NO HAY WEBSOCKET PARA LAS PARADAS: no se emite nada al abrirlas ni al cerrarlas, y el payload de .trip.position.updated sigue con sus SEIS claves intactas. Quien mira el mapa se entera de la parada al ver que el punto no se mueve, o pidiendo este GET.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico de la parada (trip_timeouts.id). NO ES PARÁMETRO DE NINGUNA RUTA: no existe /timeouts/{timeout}, ni detalle, ni edición, ni borrado. Sirve para deduplicar en el frontend y como desempate del orden del listado entre dos paradas que arrancaron en el mismo segundo.',
            type: 'integer',
            example: 12,
        ),
        new OA\Property(
            property: 'latitude',
            description: 'Latitud del ANCLA de la parada —el primer punto del reposo, no el punto que la detectó—, SIEMPRE COMO STRING de ocho decimales (decimal(10,8) en la base). Es una copia de la latitude del trip_position cuyo id es startPositionId, guardada en la fila para que el mapa pinte el pin sin un segundo viaje a la base; la copia no puede desincronizarse porque una posición es inmutable desde SPEC 26.',
            type: 'string',
            example: '14.62820000',
        ),
        new OA\Property(
            property: 'longitude',
            description: 'Longitud del ANCLA de la parada, SIEMPRE COMO STRING de ocho decimales (decimal(11,8) en la base). Mismo criterio que latitude: copia del punto donde arrancó el reposo. La distancia contra estas dos coordenadas es la que decide el cierre —a 5 metros o más se considera que el camión se movió—, calculada con Haversine en PHP y sin PostGIS.',
            type: 'string',
            example: '-90.52290000',
        ),
        new OA\Property(
            property: 'startedAt',
            description: 'Momento en que empezó el reposo: el recordedAt del ANCLA, no el de la petición que detectó la parada. En el formato propio del proyecto d-m-Y h:i:s A, NO en ISO 8601. Es la clave por la que se ordena el listado (ascendente, con desempate por id) y nunca es null.',
            type: 'string',
            example: '10-09-2026 08:14:00 AM',
        ),
        new OA\Property(
            property: 'endedAt',
            description: 'Momento en que la parada se cerró, en el formato d-m-Y h:i:s A y NUNCA en ISO 8601. NULL MIENTRAS LA PARADA SIGA ABIERTA —el camión seguía quieto en su último punto reportado, o dejó de reportar sin que nadie finalizara el viaje: no hay job de cierre por inactividad—. Cuando la cerró un punto en movimiento es el recordedAt de ese punto; cuando la cerró el PATCH /api/trips/{trip}/finish es el now() del servidor en ese instante, coherente con el endDate que el viaje escribe a la vez.',
            type: 'string',
            nullable: true,
            example: '10-09-2026 08:41:30 AM',
        ),
        new OA\Property(
            property: 'durationMinutes',
            description: 'Cuánto duró la parada, en minutos con dos decimales: 27 minutos y 30 segundos salen como 27.5, y 5 segundos como 0.08. ES CALCULADO EN LECTURA —round(segundos / 60, 2) sobre las dos horas—, sin columna, sin job y sin caché, con el precedente de currentValue en SPEC 17: NO SE PUEDE FILTRAR NI ORDENAR POR ÉL, porque la base no lo conoce. ATENCIÓN — NULL MIENTRAS LA PARADA ESTÉ ABIERTA, a propósito: medirlo contra now() daría un valor distinto en cada lectura, no comparable entre dos peticiones y engañoso en una captura de pantalla. Tampoco existe el total parado del viaje: sumar es del consumidor.',
            type: 'number',
            format: 'float',
            nullable: true,
            example: 27.5,
        ),
        new OA\Property(
            property: 'pilotId',
            description: 'Id del usuario que conducía cuando se abrió la parada (users.id), copiado del punto ancla y nunca de un cuerpo: aquí no hay registeredBy porque el autor es el piloto. Hoy coincide con el pilotId del viaje, pero se guarda aparte por el mismo motivo que en TripPosition: SPEC 24 permite reasignar la tripulación mientras el viaje sigue pending, y la parada debe seguir diciendo quién estaba al volante. No viene acompañado del nombre.',
            type: 'integer',
            example: 7,
        ),
        new OA\Property(
            property: 'startPositionId',
            description: 'Id del punto del rastro que ancla la parada (trip_positions.id): el ÚLTIMO punto antes de que el camión dejara de moverse, no el que la detectó. Nunca es null. Sus coordenadas y su hora son justo las que salen aquí como latitude, longitude y startedAt, así que cruzarlo con GET /api/trips/{trip}/positions es opcional.',
            type: 'integer',
            example: 340,
        ),
        new OA\Property(
            property: 'endPositionId',
            description: 'Id del punto del rastro que cerró la parada (trip_positions.id): el primero que se midió a 5 metros o MÁS del ancla. ATENCIÓN — SU NULL NO SIGNIFICA SIEMPRE LO MISMO: con endedAt también en null, la parada sigue abierta; con endedAt PUESTO y este campo en null, la cerró el PATCH /api/trips/{trip}/finish, y ese null es la ÚNICA forma de distinguir «cerrada porque el camión se movió» de «cerrada porque el viaje terminó» —no hay columna closeReason ni enum—.',
            type: 'integer',
            nullable: true,
            example: 451,
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripTimeoutListResponse',
    title: 'Paradas del viaje sin paginar',
    description: <<<'TEXT'
    Respuesta de GET /api/trips/{trip}/timeouts cuando NO se envía limit, o cuando el limit no es numérico (limit=abc): se devuelven TODAS las paradas del viaje y el sobre NO incluye total, currentPage ni lastPage.

    ATENCIÓN — «TODAS» PUEDE SER MUCHAS. SPEC 27 decidió no poner umbral mínimo de duración: se registra toda parada, semáforos incluidos, así que un recorrido urbano puede dejar decenas de filas de 15 a 30 segundos y nada se borra nunca. La paginación sigue siendo opt-in por coherencia con el resto del proyecto; filtrar por durationMinutes es del frontend.

    El orden es FIJO: started_at ASCENDENTE —de la primera parada del viaje a la última, AL REVÉS que la mayoría de listados del proyecto, que van del más nuevo al más viejo, e igual que el rastro de SPEC 26— con desempate por id ascendente. NO HAY FILTROS DE NINGÚN TIPO: no existe open, ni dateFrom, ni dateTo, ni minDurationMinutes, ni sortDir. Cualquier query param que no sea limit o page SE IGNORA sin error, nunca 422.

    Un viaje sin paradas —todavía pending, in_route sin reposos detectados, o anterior a SPEC 27, porque no hubo backfill del rastro histórico— devuelve 200 con data vacío, NUNCA 404.
    TEXT,
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Paradas obtenidas correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TripTimeout')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedTripTimeoutListResponse',
    title: 'Paradas del viaje paginadas',
    description: <<<'TEXT'
    Respuesta de GET /api/trips/{trip}/timeouts cuando se envía un limit numérico: los metadatos de paginación salen APLANADOS EN LA RAÍZ del sobre, junto a statusCode, message y data, NO anidados bajo meta.

    ATENCIÓN — EL TAMAÑO DE PÁGINA SE ACOTA A [10, 100], igual que en el rastro de SPEC 26 y a diferencia de GET /api/trips, que no tiene piso de 10 y respeta el limit tal cual. Aquí limit=1 y limit=3 devuelven páginas de 10, y limit=500 devuelve páginas de 100. Conviene no asumir que la página tendrá el tamaño pedido.

    total cuenta TODAS las paradas del viaje, no las de la página. El orden sigue siendo started_at ascendente, así que page=1 trae las primeras paradas del viaje y la última página, las más recientes.
    TEXT,
    allOf: [
        new OA\Schema(ref: '#/components/schemas/TripTimeoutListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class TripTimeoutResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Nine keys and no `tripId`: whoever asks for the stops already carries it in the
     * URL, exactly as in `TripPositionResource`.
     *
     * Both coordinates —the anchor's— leave as an eight decimal **string**, as in
     * `TripPosition` (SPEC 26) and `Location` (SPEC 15): a float would round away the
     * very precision the column was widened for.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            /** Las dos horas con el formato del resto del proyecto, nunca ISO 8601. */
            'startedAt' => $this->started_at?->format('d-m-Y h:i:s A'),
            'endedAt' => $this->ended_at?->format('d-m-Y h:i:s A'),
            'durationMinutes' => $this->resolveDurationMinutes(),
            'pilotId' => $this->pilot_id,
            'startPositionId' => $this->start_position_id,
            'endPositionId' => $this->end_position_id,
        ];
    }

    /**
     * How long the stop lasted, in minutes with two decimals.
     *
     * Computed on read and stored nowhere, with the precedent of `currentValue` in
     * SPEC 17: a value derived from two timestamps needs neither a column nor a job
     * keeping it in sync.
     *
     * `null` while the stop is still open, on purpose: measuring it against `now()`
     * would give a different number on every read, not comparable between two requests
     * and plainly misleading in a screenshot.
     *
     * The difference is taken in absolute value —same criterion as `secondsSince()` in
     * SPEC 26— so a server clock drifting backwards cannot produce a negative duration.
     */
    private function resolveDurationMinutes(): ?float
    {
        if ($this->started_at === null || $this->ended_at === null) {
            return null;
        }

        return round(abs($this->started_at->diffInSeconds($this->ended_at)) / 60, 2);
    }
}
