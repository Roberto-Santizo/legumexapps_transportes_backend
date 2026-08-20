<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Place\GetDirectionsRequest;
use App\Http\Requests\Place\SearchPlacesRequest;
use App\Http\Resources\Place\DirectionsResource;
use App\Http\Resources\Place\PlacePredictionResource;
use App\Http\Resources\Place\PlaceResource;
use App\Interfaces\Location\LocationServiceInterface;
use App\Interfaces\Place\PlaceServiceInterface;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Places',
    description: 'Búsqueda de direcciones para elegir el destino de un flete y cálculo de la ruta por carretera hasta un destino ya registrado. Es el único dominio de la API que NO PERSISTE NADA: no hay tabla, ni modelo, ni migración —es un proxy de LECTURA sobre un proveedor de direcciones externo—, y por eso solo existen TRES endpoints, los tres GET. No hay alta, ni edición, ni borrado: POST, PUT, PATCH y DELETE sobre /api/places no existen y responden 405. El flujo de la búsqueda son DOS LLAMADAS ENCADENADAS: GET /api/places?search= devuelve hasta 10 direcciones con su id y su texto, el usuario elige una, y GET /api/places/{place} resuelve las coordenadas de esa. El listado NO trae coordenadas a propósito: resolver las diez factura un nivel más caro por nueve posiciones que nadie usa. El TERCER endpoint es independiente de esos dos: GET /api/places/directions?locationId=&lat=&lng= calcula la ruta POR CARRETERA desde un punto suelto hasta un destino registrado en POST /api/locations, y devuelve distancia en kilómetros, duración estimada y la línea del trayecto en dos formatos —cadena codificada y pares [latitud, longitud]—. ESTA API NO COTIZA NADA: ni la dirección elegida ni la ruta calculada consultan tarifas, precio de combustible ni importes, y ninguna crea un viaje; cotizar es otra llamada, GET /api/freight-rates/quote, y la hace el cliente. Además, la duración de la ruta es una ESTIMACIÓN SIN TRÁFICO sobre límites de velocidad, NO UN ETA. Permisos: los tres endpoints exigen solo token JWT (Authorization: Bearer {token}) y están abiertos a los CUATRO roles —administrator, carrier, pilot y manager—, incluido un carrier que todavía no ha registrado su empresa: ninguna ruta lleva role: ni carrier.required. ATENCIÓN — los tres endpoints pueden responder 503, que significa que EL SERVICIO EXTERNO NO RESPONDIÓ, no que el cliente se equivocara: ante un 503 se reintenta más tarde, no se corrige el formulario.',
)]
class PlaceController extends Controller
{
    #[OA\Get(
        path: '/api/places',
        operationId: 'indexPlaces',
        summary: 'Buscar direcciones por texto',
        description: <<<'TEXT'
        Devuelve hasta 10 direcciones que coinciden con el texto buscado, cada una con su id y su dirección formateada. Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier sin empresa registrada, porque ninguna ruta de este dominio lleva role: ni carrier.required.

        PRIMER PASO DE UN FLUJO DE DOS LLAMADAS. Este listado NO TRAE COORDENADAS a propósito: se muestra al usuario, se elige una dirección y con su id se llama a GET /api/places/{place}, que sí las devuelve. Resolver la posición de los diez resultados factura un nivel más caro por nueve posiciones que nadie va a usar. El cliente es responsable de guardar el id elegido entre una llamada y la otra.

        UNA BÚSQUEDA SIN COINCIDENCIAS ES 200 CON data VACÍO, NUNCA 404. Que el usuario todavía no haya terminado de escribir es el caso más normal del mundo y no debe tratarse como excepción.

        ATENCIÓN — un search inválido devuelve 422 y NO LLEGA AL PROVEEDOR DE DIRECCIONES, que factura cada llamada. Ese 422 sale con el formato de Laravel { message, errors }, no con el sobre { statusCode, message, data } del resto de la API.

        NO PAGINA NUNCA y no admite ningún otro parámetro: son diez como mucho y no hay página siguiente. limit, page, pageSize y regionCode se ignoran por completo —enviarlos devuelve exactamente la misma respuesta— y el sobre nunca trae total, currentPage ni lastPage.

        NO PERSISTE NADA: buscar no guarda la dirección, no crea un destino y no deja historial. Tampoco hay caché: cada llamada sale al proveedor externo. No hay límite de búsquedas por usuario ni throttle, pero cada llamada cuesta dinero, así que el cliente debería aplicar un debounce antes de disparar la petición.

        Los resultados están SESGADOS a Guatemala y en español —se priorizan, no se restringen—: una dirección fronteriza legítima de otro país también puede aparecer.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Places'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/placesSearchQuery'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Direcciones obtenidas correctamente. data trae de 0 a 10 elementos, cada uno con exactamente id y formattedAddress y SIN coordenadas. Una búsqueda sin coincidencias devuelve este mismo 200 con data vacío, nunca 404. El sobre no incluye metadatos de paginación porque este listado no pagina.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Direcciones obtenidas correctamente'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlacePrediction')),
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
                response: 422,
                description: 'El search es inválido: ausente, cadena vacía, de menos de 3 caracteres o de más de 200 (mensajes: El texto de búsqueda es obligatorio / El texto de búsqueda debe tener al menos 3 caracteres / El texto de búsqueda no puede superar los 200 caracteres). En los cuatro casos la petición NO llega al proveedor de direcciones. La respuesta usa el formato de Laravel { message, errors: { search: [...] } }, no el sobre { statusCode, message, data }.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
            new OA\Response(
                response: 503,
                description: 'ATENCIÓN — EL CLIENTE NO SE EQUIVOCÓ: el proveedor de direcciones no respondió. El search era válido y la petición estaba bien formada; lo que falló es un tercero. Se produce por timeout (el backend espera 10 segundos y no reintenta), error de red o DNS, credencial ausente o rechazada, cuota agotada, error 5xx del servicio externo o una respuesta con forma inesperada —si a una sola de las diez direcciones le falta un campo, se invalida la respuesta entera en vez de devolver nueve—. Los seis casos salen con el MISMO mensaje genérico, sin filtrar nada del error original: El servicio de búsqueda de direcciones no está disponible en este momento. Intenta de nuevo en unos minutos. Un cliente que recibe 503 REINTENTA MÁS TARDE; no corrige el formulario ni marca el campo en rojo. No hay caché ni proveedor alternativo del que tirar mientras dura.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function index(SearchPlacesRequest $request, PlaceServiceInterface $placeService)
    {
        try {
            $places = $placeService->searchPlaces($request->validated('search'));

            /** Este listado no pagina nunca: el proveedor devuelve diez y se acabó. */
            $data = PlacePredictionResource::collection($places);

            return ResponseHandler::success($data, 'Direcciones obtenidas correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/places/{place}',
        operationId: 'showPlace',
        summary: 'Obtener una dirección con sus coordenadas',
        description: <<<'TEXT'
        Devuelve la dirección de un place id concreto junto con su latitude y su longitude. Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier sin empresa registrada. No hay ámbito por empresa ni recurso ajeno, así que en este endpoint NO existe el 403.

        SEGUNDO PASO DEL FLUJO DE DOS LLAMADAS. El {place} de la ruta es el id que devolvió GET /api/places?search=: no se puede construir, ni adivinar, ni derivar de la dirección escrita. Sin haber buscado antes no hay nada válido que poner aquí.

        ATENCIÓN — latitude y longitude son NÚMEROS y salen PLANOS EN LA RAÍZ de data, no anidados bajo geometry ni location, y no se formatean con dos decimales como los importes del resto de la API. Se pasan tal cual como lat y lng a GET /api/freight-rates/quote; encadenar las dos llamadas es trabajo del cliente, esta API no cotiza nada.

        NO SE COMPRUEBA que el punto caiga dentro de una zona registrada: una dirección de cualquier parte del mundo se devuelve igual con 200. Quien avisa es la cotización, con su propio 404 «El punto indicado no pertenece a ninguna zona registrada».

        NO PERSISTE NADA y no hay caché: consultar una dirección no la guarda ni crea ningún destino, y cada llamada sale al proveedor externo.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Places'],
        parameters: [
            new OA\Parameter(
                name: 'place',
                description: 'Identificador OPACO de la dirección, tal y como lo devolvió GET /api/places?search= en el campo id. ATENCIÓN — NO ES UN ENTERO AUTOINCREMENTAL como el {product} o el {vehicle} del resto de la API: es una CADENA del proveedor externo, de forma no garantizada, que esta API NO VALIDA en absoluto y que no hay manera de adivinar. La única forma de conseguirlo es buscar primero. Enviar un id inexistente y enviar uno con forma inválida producen exactamente el mismo 404.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', example: 'ChIJk4h8_Q6ii4ARZ4gGpXY8bJ0'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dirección obtenida correctamente. data trae exactamente id, formattedAddress, latitude y longitude, las dos coordenadas como números y en la raíz, nunca en null. No se ha creado ni guardado nada.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Dirección obtenida correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Place'),
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
                description: 'No hay ninguna dirección con ese id. Un id INEXISTENTE y un id MAL FORMADO devuelven EL MISMO 404 con el mismo mensaje: para esta API los dos casos son "no hay tal lugar", y distinguirlos obligaría a explicarle al cliente la taxonomía de errores del servicio externo. El mensaje devuelto es: La dirección no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 503,
                description: 'ATENCIÓN — EL CLIENTE NO SE EQUIVOCÓ: el proveedor de direcciones no respondió. El id podía ser perfectamente válido; lo que falló es un tercero. Se produce por timeout (10 segundos de espera y ningún reintento), error de red o DNS, credencial ausente o rechazada, cuota agotada, error 5xx del servicio externo, o una respuesta con forma inesperada —incluida una respuesta correcta a la que le falten las coordenadas, que sale como 503 y NO como un latitude en null—. Todos los casos comparten el mismo mensaje genérico: El servicio de búsqueda de direcciones no está disponible en este momento. Intenta de nuevo en unos minutos. Un cliente que recibe 503 REINTENTA MÁS TARDE; no corrige nada ni vuelve al formulario. OJO — no confundirlo con el 404: 503 no significa que la dirección no exista.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(string $place, PlaceServiceInterface $placeService)
    {
        try {
            $found = $placeService->getPlaceById($place);

            return ResponseHandler::success(new PlaceResource($found), 'Dirección obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Two contracts by method parameter, chained.
     *
     * Resolving the destination, delegating the route and answering is orchestration,
     * not business logic, which is why it still fits in a controller of this project.
     * The order is the contract: the destination is resolved FIRST, so an inactive one
     * answers 400 without ever reaching —or billing— the provider.
     */
    #[OA\Get(
        path: '/api/places/directions',
        operationId: 'directionsPlaces',
        summary: 'Calcular la ruta por carretera hacia un destino',
        description: <<<'TEXT'
        Devuelve la ruta POR CARRETERA desde un punto suelto (lat, lng) hasta un destino ya registrado en POST /api/locations, con su distancia en kilómetros, su duración estimada y la línea del trayecto. Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier sin empresa registrada, porque ninguna ruta de este dominio lleva role: ni carrier.required. No hay ámbito por empresa, así que en este endpoint NO existe el 403.

        ATENCIÓN — ESTE ENDPOINT NO COTIZA. No toca freight_rates, no lee el precio vigente del combustible, no devuelve ningún importe y no crea ningún viaje ni ninguna carga: la respuesta no trae pricePerPound, ni total, ni pounds. Para cotizar está GET /api/freight-rates/quote, que es OTRA LLAMADA y la hace el cliente, no esta API. La distancia devuelta tampoco influye en la tarifa: el precio depende del destino elegido, no de los kilómetros.

        ATENCIÓN — durationHours ES UNA ESTIMACIÓN SIN TRÁFICO, calculada sobre los límites de velocidad de las vías: sin atascos, sin paradas, sin descansos y sin la diferencia entre un camión cargado y un coche. NO ES UN ETA y en carretera real se va a quedar corta SISTEMÁTICAMENTE. La misma consulta devuelve lo mismo a las 3 de la mañana y en hora pico —no hay hora de salida que la altere—, así que el cliente debería etiquetarla como estimación y nunca presentarla como hora de llegada.

        ATENCIÓN — polyline y points son LA MISMA LÍNEA EN DOS FORMATOS, una duplicación consciente: la cadena codificada para las librerías de mapa que la consumen directa, los pares [latitud, longitud] para dibujar a mano o medir sin arrastrar un decodificador. NO SON DOS RUTAS ni dos niveles de detalle; el cliente elige uno y descarta el otro. Los pares van en orden LATITUD PRIMERO, igual que el area de Zones. points NUNCA VIENE VACÍO en un 200: una ruta sin puntos sale como 503.

        EL ORIGEN SON COORDENADAS SUELTAS y NUNCA un locationId: no hay ruta inversa, ni viaje redondo, ni ruta entre dos destinos registrados. El destino, al revés, es siempre un locationId: sus coordenadas se leen de la fila registrada y no se aceptan por ningún nombre.

        UNA SOLA RUTA POR LLAMADA: no hay rutas alternativas, ni waypoints, ni instrucciones paso a paso, ni matriz de distancias. travelMode, polylineQuality y limit se IGNORAN POR COMPLETO —enviarlos devuelve exactamente la misma respuesta—: son constantes del servidor, no opciones del cliente. Este endpoint tampoco pagina, así que el sobre nunca trae total, currentPage ni lastPage.

        EL ORDEN DE LOS ERRORES ES CONTRATO: primero la validación (422, con el formato de Laravel { message, errors }, incluido un locationId inexistente), después el destino (400 si está inactivo) y solo entonces la llamada al proveedor de direcciones externo (404 si no hay camino, 503 si el proveedor falló). Los dos primeros se resuelven ANTES de salir fuera, así que NO FACTURAN NADA.

        NO PERSISTE NADA y no hay caché: pedir la ruta no la guarda, no deja historial y cada llamada sale al proveedor externo, que cobra una ruta más cara que una búsqueda de texto. Ningún campo de la respuesta es fecha, así que el formato d-m-Y h:i:s A del resto del proyecto no aplica aquí.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Places'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/directionsLocationIdQuery'),
            new OA\Parameter(ref: '#/components/parameters/directionsLatQuery'),
            new OA\Parameter(ref: '#/components/parameters/directionsLngQuery'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Ruta obtenida correctamente. data trae exactamente locationId, locationName, distanceKilometers, durationHours, polyline y points, y ninguna clave más: NO hay importes, NO hay tarifa y NO se ha creado ningún viaje. points nunca viene vacío y polyline nunca viene como cadena vacía. Recordar que durationHours es una estimación sin tráfico, no un ETA.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Ruta obtenida correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Directions'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'El destino existe pero está dado de baja: su status es false. El mensaje devuelto es: El destino seleccionado no está activo. OJO — es 400 y no 404 porque la fila sigue viva: un destino inactivo aparece en GET /api/locations, conserva sus tarifas y se reactiva con PATCH /api/locations/{location}/toggle-status. Se comprueba ANTES de llamar al proveedor de direcciones externo, así que este caso NO FACTURA NADA. No confundirlo con el 422 de un locationId inexistente.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'EL PROVEEDOR FUNCIONÓ y no hay camino POR CARRETERA entre el origen y el destino: una isla, un punto en el mar, un tramo sin vía conectada. El mensaje devuelto es: No se encontró una ruta hacia el destino. Es un resultado definitivo, no un fallo temporal: REINTENTAR DA LO MISMO. Lo que se corrige es el origen, no la espera. OJO — no confundirlo con el 503 (el proveedor no contestó) ni con el 400 (el destino está inactivo).',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Alguno de los TRES parámetros obligatorios falta o es inválido: locationId (El destino es obligatorio / El destino debe ser un identificador numérico / El destino seleccionado no existe), lat (La latitud de origen es obligatoria / La latitud de origen debe ser numérica / La latitud de origen debe estar entre -90 y 90) y lng (La longitud de origen es obligatoria / La longitud de origen debe ser numérica / La longitud de origen debe estar entre -180 y 180). Un locationId INEXISTENTE cae aquí, con 422, no con 404. En todos los casos la petición NO llega al proveedor de direcciones externo, que factura cada ruta. La respuesta usa el formato de Laravel { message, errors: { locationId: [...] } }, no el sobre { statusCode, message, data }.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
            new OA\Response(
                response: 503,
                description: 'ATENCIÓN — EL CLIENTE NO SE EQUIVOCÓ: el proveedor de direcciones externo no respondió. Los tres parámetros eran válidos y el destino estaba activo; lo que falló es un tercero. Se produce por timeout (10 segundos de espera y ningún reintento), error de red o DNS, credencial ausente o rechazada, cuota agotada, error 5xx del servicio externo, o una respuesta con forma inesperada —incluida una ruta sin distancia, sin duración legible, sin línea codificada o con la línea vacía de puntos, que salen como 503 y NO como una ruta con campos en cero o con points vacío—. Todos los casos comparten el mismo mensaje genérico, sin filtrar nada del error original: El servicio de búsqueda de direcciones no está disponible en este momento. Intenta de nuevo en unos minutos. Un cliente que recibe 503 REINTENTA MÁS TARDE; no corrige el formulario ni marca el campo en rojo. OJO — no confundirlo con el 404: 503 no significa que no haya camino.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function directions(
        GetDirectionsRequest $request,
        PlaceServiceInterface $placeService,
        LocationServiceInterface $locationService,
    ) {
        try {
            $location = $locationService->getActiveLocationById((int) $request->validated('locationId'));

            $directions = $placeService->getDirections(
                (float) $request->validated('lat'),
                (float) $request->validated('lng'),
                (float) $location->latitude,
                (float) $location->longitude,
            );

            return ResponseHandler::success(
                new DirectionsResource(['location' => $location, 'directions' => $directions]),
                'Ruta obtenida correctamente',
                200,
            );
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }
}
