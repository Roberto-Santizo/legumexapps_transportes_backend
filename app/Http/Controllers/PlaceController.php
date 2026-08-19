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
    description: 'Búsqueda de direcciones para elegir el destino de un flete. Es el único dominio de la API que NO PERSISTE NADA: no hay tabla, ni modelo, ni migración —es un proxy de LECTURA sobre un proveedor de direcciones externo—, y por eso solo existen DOS endpoints, los dos GET. No hay alta, ni edición, ni borrado: POST, PUT, PATCH y DELETE sobre /api/places no existen y responden 405. El flujo son DOS LLAMADAS ENCADENADAS: GET /api/places?search= devuelve hasta 10 direcciones con su id y su texto, el usuario elige una, y GET /api/places/{place} resuelve las coordenadas de esa. El listado NO trae coordenadas a propósito: resolver las diez factura un nivel más caro por nueve posiciones que nadie usa. Esas latitude y longitude se pasan tal cual como lat y lng a GET /api/freight-rates/quote, pero ese encadenado lo hace el cliente: ESTA API NO COTIZA NADA. Permisos: los dos endpoints exigen solo token JWT (Authorization: Bearer {token}) y están abiertos a los CUATRO roles —administrator, carrier, pilot y manager—, incluido un carrier que todavía no ha registrado su empresa: ninguna ruta lleva role: ni carrier.required. ATENCIÓN — los dos endpoints pueden responder 503, que significa que EL SERVICIO EXTERNO NO RESPONDIÓ, no que el cliente se equivocara: ante un 503 se reintenta más tarde, no se corrige el formulario.',
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
