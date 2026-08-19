<?php

namespace App\Http\Resources\Place;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * The road route towards a registered destination.
 *
 * Wraps an array, not a model, like PlaceResource and FreightQuoteResource: the route
 * comes from the provider and is never persisted.
 *
 * The destination identity does not come from the provider, which never learns that a
 * registered destination exists: locationId and locationName are read from the Location
 * the controller resolved.
 *
 * Nothing here is money and nothing here is a date, so neither the two-decimal money
 * formatting nor the d-m-Y h:i:s A format of the rest of the project applies.
 */
#[OA\Schema(
    schema: 'Directions',
    title: 'Ruta por carretera hacia un destino',
    description: <<<'TEXT'
    Respuesta de GET /api/places/directions: la ruta POR CARRETERA desde un punto suelto (lat, lng) hasta un destino ya registrado en POST /api/locations. Trae exactamente SEIS claves y ninguna más.

    ATENCIÓN — ESTA RESPUESTA NO COTIZA NADA. No consulta freight_rates, no lee el precio vigente del combustible, no devuelve ningún importe en quetzales y no crea ningún viaje ni ninguna carga. Aquí no hay pricePerPound, ni total, ni pounds: quien quiera el precio llama aparte a GET /api/freight-rates/quote, y ese encadenado lo hace el cliente. La distancia de esta ruta TAMPOCO influye en la tarifa: el precio depende del destino elegido, no de los kilómetros recorridos para llegar.

    NO PERSISTE NADA: como el resto del dominio, no hay tabla, ni modelo, ni migración, y tampoco hay caché —cada llamada sale al proveedor de direcciones externo, que factura una ruta más cara que una búsqueda de texto—. Pedir la ruta no guarda el trayecto ni deja historial.

    NINGÚN CAMPO ES FECHA, así que el formato d-m-Y h:i:s A del resto del proyecto no aplica aquí, y ningún campo es dinero, así que tampoco se formatea con dos decimales de moneda.

    UNA SOLA RUTA POR LLAMADA: no hay rutas alternativas, ni waypoints, ni instrucciones paso a paso, ni matriz de distancias. Y un solo sentido: el origen son coordenadas sueltas y el destino un locationId, nunca al revés.
    TEXT,
    properties: [
        new OA\Property(
            property: 'locationId',
            description: 'Identificador del destino (locations.id), el mismo que se envió en la query string, devuelto para que el cliente confirme qué destino se resolvió. NO viene del proveedor de direcciones externo, que nunca llega a saber que existe un destino registrado: se lee de la fila de locations.',
            type: 'integer',
            example: 7,
        ),
        new OA\Property(
            property: 'locationName',
            description: 'Nombre del destino registrado, SIEMPRE EN MAYÚSCULAS y con los espacios colapsados, tal y como lo normalizó el alta del destino. Es el nombre guardado en locations, NO la dirección formateada que compone el proveedor de direcciones externo: quien quiera ese texto lo pide a GET /api/places/{place}.',
            type: 'string',
            example: 'PUERTO QUETZAL',
        ),
        new OA\Property(
            property: 'distanceKilometers',
            description: 'Longitud de la ruta POR CARRETERA en KILÓMETROS, como NÚMERO redondeado a dos decimales. No es la distancia en línea recta entre los dos puntos, así que siempre es mayor que ella. No es dinero y no interviene en ningún cálculo de precio: la tarifa depende del destino elegido, no de los kilómetros, y mostrar las dos cosas juntas es decisión del cliente.',
            type: 'number',
            format: 'float',
            example: 104.32,
        ),
        new OA\Property(
            property: 'durationHours',
            description: <<<'TEXT'
            Duración estimada del trayecto en HORAS DECIMALES (1.75 es una hora y cuarenta y cinco minutos), como número redondeado a dos decimales.

            ATENCIÓN — ES UNA ESTIMACIÓN SIN TRÁFICO Y NO ES UN ETA. Se calcula sobre los LÍMITES DE VELOCIDAD de las vías: no contempla atascos, ni retenciones, ni paradas, ni descansos del piloto, ni tiempos de carga y descarga, ni la diferencia entre un camión cargado y un coche. En carretera real se queda corta SISTEMÁTICAMENTE.

            La misma consulta devuelve EXACTAMENTE LO MISMO a las 3 de la mañana y en hora pico: no hay parámetro de hora de salida y la respuesta no depende del momento en que se pida. El cliente debería etiquetarla como estimación y nunca presentarla como hora de llegada.
            TEXT,
            type: 'number',
            format: 'float',
            example: 1.75,
        ),
        new OA\Property(
            property: 'polyline',
            description: 'La línea de la ruta como CADENA CODIFICADA, lista para las librerías de mapa que la consumen directa sin decodificarla. Es EXACTAMENTE LA MISMA LÍNEA que points, solo que en otro formato: no son dos rutas, ni dos niveles de detalle. Es una cadena opaca —no se parsea a mano ni se recorta— y nunca viene vacía en un 200.',
            type: 'string',
            example: '_lgxA~vmgPrIoAzmE',
        ),
        new OA\Property(
            property: 'points',
            description: <<<'TEXT'
            La MISMA LÍNEA de polyline, ya decodificada como lista de pares [latitud, longitud], para dibujarla a mano o medirla sin arrastrar un decodificador. La duplicación es CONSCIENTE: polyline y points no son dos rutas distintas ni dos niveles de detalle, son un único trazado en dos formatos, y el cliente elige el que le sirva.

            ATENCIÓN — el orden es LATITUD PRIMERO, igual que el area de Zones y al revés que GeoJSON, Mapbox y Leaflet en su forma [lng, lat]. Cada par tiene exactamente dos números.

            NUNCA VIENE VACÍO en un 200: una ruta sin puntos se considera una respuesta rota del proveedor de direcciones externo y sale como 503, no como una lista vacía que el cliente tendría que dibujar.
            TEXT,
            type: 'array',
            items: new OA\Items(
                description: 'Par [latitud, longitud]: exactamente dos números, la latitud primero.',
                type: 'array',
                items: new OA\Items(type: 'number', format: 'float'),
                maxItems: 2,
                minItems: 2,
            ),
            example: [[14.6248, -90.5152], [14.6231, -90.5148]],
        ),
    ],
    type: 'object',
)]
class DirectionsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Location $location */
        $location = $this->resource['location'];

        /** @var array{distanceKilometers: float, durationHours: float, polyline: string, points: list<array{0: float, 1: float}>} $directions */
        $directions = $this->resource['directions'];

        return [
            'locationId' => $location->id,
            'locationName' => $location->name,
            /** Números, no cadenas: no son dinero y no hay cast decimal de por medio. */
            'distanceKilometers' => $directions['distanceKilometers'],
            /** Estimación sin tráfico, calculada sobre límites de velocidad. No es un ETA. */
            'durationHours' => $directions['durationHours'],
            /**
             * La misma línea dos veces, a propósito: la cadena para las librerías de mapa
             * que la consumen directa, los pares para dibujar o medir sin decodificador.
             */
            'polyline' => $directions['polyline'],
            'points' => $directions['points'],
        ];
    }
}
