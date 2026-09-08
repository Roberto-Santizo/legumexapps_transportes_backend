<?php

namespace App\Http\Resources\TripPosition;

use App\Models\TripPosition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin TripPosition
 */
#[OA\Schema(
    schema: 'TripPosition',
    title: 'Punto del rastro de un viaje',
    description: <<<'TEXT'
    Un punto del rastro de un viaje: dónde estuvo el camión y cuándo. CINCO CLAVES en camelCase y ninguna más, en este orden: id, latitude, longitude, recordedAt y pilotId.

    ATENCIÓN — NO TRAE tripId. Quien pide el rastro con GET /api/trips/{trip}/positions ya lo lleva en la URL, y quien recibe el evento .trip.position.updated por websocket lo trae dentro del payload. Si el frontend mezcla puntos de varios viajes en la misma estructura, tiene que anotar el viaje por su cuenta.

    ATENCIÓN — latitude Y longitude SALEN COMO STRING, NO COMO NÚMERO, con ocho decimales fijos («14.62807400», «-90.52255400»), igual que en Location desde SPEC 15: un float redondearía justo la precisión por la que la columna se ensanchó. El frontend debe convertirlas antes de pintarlas en el mapa (parseFloat), y NO comparar puntos por igualdad de cadena.

    ATENCIÓN — recordedAt ES LA HORA DEL SERVIDOR, NO LA DEL DISPOSITIVO. La pone now() al escribir la fila y no se acepta desde el body: no hay forma de mentir sobre cuándo se estuvo dónde, y el orden del rastro es siempre el de llegada. La contrapartida es que un tramo sin cobertura NO llega tarde: se pierde entero, porque no hay envío en lote.

    ESTE OBJETO ES INMUTABLE Y ETERNO: no existe PATCH ni DELETE de un punto, no hay purga por antigüedad y el DELETE (baja lógica) del viaje NO borra ninguna fila de trip_positions. Una coordenada mal reportada es historial y se queda.

    NO HAY TELEMETRÍA: nada de velocidad, rumbo, precisión del GPS, altitud ni batería. Solo dónde y cuándo.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico del punto (trip_positions.id). Sirve para deduplicar en el frontend —el mismo id puede llegar dos veces: una por la respuesta del POST y otra por el websocket— y para detectar el piso de 15 segundos comparando con el id del punto anterior. NO ES PARÁMETRO DE NINGUNA RUTA: no existe /positions/{position}.',
            type: 'integer',
            example: 4821,
        ),
        new OA\Property(
            property: 'latitude',
            description: 'Latitud reportada, SIEMPRE COMO STRING de ocho decimales (decimal(10,8) en la base). ATENCIÓN — no se valida contra nada más que el rango [-90, 90] del sistema de coordenadas: no se comprueba que caiga cerca de la polilínea del viaje, ni dentro de Guatemala, ni que el salto contra el punto anterior sea físicamente posible. Un piloto puede reportar Noruega y la API lo guarda.',
            type: 'string',
            example: '14.62807400',
        ),
        new OA\Property(
            property: 'longitude',
            description: 'Longitud reportada, SIEMPRE COMO STRING de ocho decimales (decimal(11,8) en la base). Mismo criterio que latitude: solo se comprueba el rango [-180, 180] y nada más. Dos puntos con las mismas coordenadas son legítimos y frecuentes —un camión parado sigue reportando—, así que no hay índice único ni deduplicación por posición.',
            type: 'string',
            example: '-90.52255400',
        ),
        new OA\Property(
            property: 'recordedAt',
            description: 'Momento en que el servidor recibió el punto, en el formato propio del proyecto d-m-Y h:i:s A, NO en ISO 8601. Es la clave por la que se ordena el rastro (ascendente, con desempate por id) y la que alimenta el piso de 15 segundos.',
            type: 'string',
            example: '07-09-2026 08:14:03 AM',
        ),
        new OA\Property(
            property: 'pilotId',
            description: 'Id del usuario que reportó el punto (users.id). Sale SIEMPRE del token del piloto autenticado, nunca del body. Hoy coincide con el pilotId del viaje, pero se guarda aparte a propósito: SPEC 24 permite reasignar la tripulación mientras el viaje sigue pending, y el rastro debe seguir diciendo quién conducía cuando se grabó cada punto. No viene acompañado del nombre: para eso está pilotName en el payload del websocket o el detalle del viaje.',
            type: 'integer',
            example: 12,
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripPositionListResponse',
    title: 'Rastro completo sin paginar',
    description: <<<'TEXT'
    Respuesta de GET /api/trips/{trip}/positions cuando NO se envía limit, o cuando el limit no es numérico: se devuelve EL RASTRO ENTERO del viaje y el sobre NO incluye total, currentPage ni lastPage.

    ATENCIÓN — «ENTERO» PUEDE SIGNIFICAR MILES DE ELEMENTOS. Es el primer listado del proyecto donde eso es lo normal: un viaje de seis horas reportando al ritmo del piso de 15 segundos deja unas 1 440 filas, y nada se borra nunca. Un frontend que pinte el mapa sin limit sobre un viaje largo se lo traerá todo de golpe. La paginación sigue siendo opt-in por coherencia con el resto del proyecto, no forzada por la API.

    El orden es FIJO: recorded_at ASCENDENTE —el rastro se lee de principio a fin, al revés que el resto de listados del proyecto, que van del más nuevo al más viejo— con desempate por id ascendente. NO HAY FILTROS DE NINGÚN TIPO: ni dateFrom, ni dateTo, ni pilotId, ni nada. Cualquier query param que no sea limit o page se ignora.

    Un viaje sin puntos —todavía pending, o in_route sin que el piloto haya reportado aún— devuelve 200 con data vacío, NUNCA 404.
    TEXT,
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Posiciones obtenidas correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TripPosition')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedTripPositionListResponse',
    title: 'Rastro paginado',
    description: <<<'TEXT'
    Respuesta de GET /api/trips/{trip}/positions cuando se envía un limit numérico: los metadatos de paginación salen APLANADOS EN LA RAÍZ del sobre, junto a statusCode, message y data, NO anidados bajo meta.

    ATENCIÓN — EL TAMAÑO DE PÁGINA SE ACOTA A [10, 100], a diferencia de GET /api/trips, que no tiene piso de 10 y respeta el limit tal cual. Aquí limit=1 y limit=5 devuelven páginas de 10, y limit=500 devuelve páginas de 100. Conviene no asumir que la página tendrá el tamaño pedido.

    total cuenta TODOS los puntos del viaje, no los de la página. El orden sigue siendo recorded_at ascendente, así que page=1 es el principio del viaje y la última página, el final.
    TEXT,
    allOf: [
        new OA\Schema(ref: '#/components/schemas/TripPositionListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class TripPositionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Five keys and no `tripId`: whoever asks for the track already carries it in the
     * URL, and whoever receives the event gets it inside the payload.
     *
     * Both coordinates leave as an eight decimal **string**, as in `Location` since
     * SPEC 15: a float would round away the very precision the column was widened for.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            /** La hora de llegada al servidor, con el formato del resto del proyecto. */
            'recordedAt' => $this->recorded_at?->format('d-m-Y h:i:s A'),
            'pilotId' => $this->pilot_id,
        ];
    }
}
