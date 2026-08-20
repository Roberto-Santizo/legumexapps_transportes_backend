<?php

use App\Errors\NotFoundError;
use App\Errors\ServiceUnavailableError;
use App\Services\Place\GooglePlacesService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| GooglePlacesService
|--------------------------------------------------------------------------
|
| El único punto del proyecto que habla HTTP con un tercero. Se prueba entero con
| Http::fake(): la suite no sale a la red ni gasta cuota, y preventStrayRequests()
| de tests/Pest.php revienta cualquier llamada que un test no haya declarado.
|
| Lo que se comprueba aquí no es que Google conteste, sino que cualquier cosa que
| conteste —incluida ninguna— salga traducida al contrato.
|
*/

const SEARCH_URL = 'https://places.googleapis.com/v1/places:searchText';
const PLACE_ID = 'ChIJk4h8_Q6ii4ARZ4gGpXY8bJ0';
const DETAIL_URL = 'https://places.googleapis.com/v1/places/'.PLACE_ID;

const ROUTES_URL = 'https://routes.googleapis.com/directions/v2:computeRoutes';

/** Ciudad de Guatemala, el origen de todas las rutas de este archivo. */
const ORIGIN_LATITUDE = 14.6248;

const ORIGIN_LONGITUDE = -90.5152;

/** Puerto Quetzal, el destino. */
const DESTINATION_LATITUDE = 13.9276;

const DESTINATION_LONGITUDE = -90.7853;

/** Cuatro puntos codificados, los mismos que decodifica PolylineDecoderTest. */
const ENCODED_POLYLINE = '_lgxA~vmgPrIoAzmE~}A~j`Crzp@';

function googlePlaces(): GooglePlacesService
{
    config()->set('services.google_places.key', 'test-key');

    return new GooglePlacesService;
}

/**
 * Un cuerpo de búsqueda con tantos lugares como se pidan.
 *
 * @return array{places: list<array{id: string, formattedAddress: string}>}
 */
function googleSearchBody(int $places = 2): array
{
    return [
        'places' => array_map(fn (int $index): array => [
            'id' => "ChIJplace{$index}",
            'formattedAddress' => "{$index}a Avenida 12-38, Zona 4, Ciudad de Guatemala",
        ], range(1, $places)),
    ];
}

it('devuelve id y formattedAddress de cada lugar', function () {
    Http::fake([SEARCH_URL => Http::response(googleSearchBody())]);

    $places = googlePlaces()->searchPlaces('zona 4 guatemala');

    expect($places)->toBe([
        ['id' => 'ChIJplace1', 'formattedAddress' => '1a Avenida 12-38, Zona 4, Ciudad de Guatemala'],
        ['id' => 'ChIJplace2', 'formattedAddress' => '2a Avenida 12-38, Zona 4, Ciudad de Guatemala'],
    ]);
});

it('manda un solo POST con el field mask acotado y la clave en la cabecera', function () {
    Http::fake([SEARCH_URL => Http::response(googleSearchBody())]);

    googlePlaces()->searchPlaces('zona 4 guatemala');

    Http::assertSentCount(1);

    Http::assertSent(function (Request $request): bool {
        expect($request->method())->toBe('POST');
        expect($request->url())->toBe(SEARCH_URL);

        /** La clave nunca viaja en la URL: acabaría en los logs de cualquier proxy. */
        expect($request->url())->not->toContain('test-key');

        expect($request->header('X-Goog-Api-Key'))->toBe(['test-key']);
        expect($request->header('X-Goog-FieldMask'))->toBe(['places.id,places.formattedAddress']);

        expect($request->data())->toBe([
            'textQuery' => 'zona 4 guatemala',
            'languageCode' => 'es',
            'regionCode' => 'GT',
            'pageSize' => 10,
        ]);

        return true;
    });
});

it('devuelve una lista vacía cuando la respuesta no trae la clave places', function () {
    Http::fake([SEARCH_URL => Http::response([])]);

    expect(googlePlaces()->searchPlaces('asdfghjkl'))->toBe([]);
});

it('devuelve como mucho diez lugares aunque el proveedor mande más', function () {
    Http::fake([SEARCH_URL => Http::response(googleSearchBody(20))]);

    expect(googlePlaces()->searchPlaces('guatemala'))->toHaveCount(10);
});

it('invalida la respuesta entera cuando a un lugar le falta un campo', function (array $place) {
    Http::fake([SEARCH_URL => Http::response(['places' => [
        ['id' => 'ChIJplace1', 'formattedAddress' => 'Zona 4, Ciudad de Guatemala'],
        $place,
    ]])]);

    googlePlaces()->searchPlaces('zona 4 guatemala');
})->with([
    'sin id' => [['formattedAddress' => 'Zona 4, Ciudad de Guatemala']],
    'sin formattedAddress' => [['id' => 'ChIJplace2']],
    'id vacío' => [['id' => '', 'formattedAddress' => 'Zona 4, Ciudad de Guatemala']],
    'id numérico' => [['id' => 42, 'formattedAddress' => 'Zona 4, Ciudad de Guatemala']],
])->throws(ServiceUnavailableError::class);

it('traduce cualquier fallo del proveedor a ServiceUnavailableError', function (callable $fake) {
    Http::fake([SEARCH_URL => $fake()]);

    googlePlaces()->searchPlaces('zona 4 guatemala');
})->with([
    'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
    'error de conexión' => [fn () => fn () => throw new ConnectionException('cURL error 6: Could not resolve host')],
    'clave ausente (401)' => [fn () => Http::response(['error' => ['message' => 'API key not valid']], 401)],
    'clave rechazada (403)' => [fn () => Http::response(['error' => ['message' => 'PERMISSION_DENIED']], 403)],
    'cuota agotada (429)' => [fn () => Http::response(['error' => ['message' => 'RESOURCE_EXHAUSTED']], 429)],
    'error de Google (500)' => [fn () => Http::response('', 500)],
    'cuerpo que no es JSON' => [fn () => Http::response('<html>502 Bad Gateway</html>')],
    'places que no es lista' => [fn () => Http::response(['places' => 'ninguno'])],
])->throws(ServiceUnavailableError::class);

it('no reintenta: un fallo genera exactamente una petición saliente', function () {
    Http::fake([SEARCH_URL => Http::response('', 500)]);

    expect(fn () => googlePlaces()->searchPlaces('zona 4 guatemala'))
        ->toThrow(ServiceUnavailableError::class);

    Http::assertSentCount(1);
});

/*
|--------------------------------------------------------------------------
| Detalle del lugar
|--------------------------------------------------------------------------
*/

/**
 * El cuerpo del detalle, con la location anidada tal como la manda el proveedor.
 *
 * @return array<string, mixed>
 */
function googleDetailBody(): array
{
    return [
        'id' => PLACE_ID,
        'formattedAddress' => '5a Avenida 12-38, Zona 4, Ciudad de Guatemala',
        'location' => ['latitude' => 14.6248, 'longitude' => -90.5152],
    ];
}

it('aplana la location y devuelve las coordenadas como float', function () {
    Http::fake([DETAIL_URL => Http::response(googleDetailBody())]);

    $place = googlePlaces()->getPlaceById(PLACE_ID);

    expect($place)->toBe([
        'id' => PLACE_ID,
        'formattedAddress' => '5a Avenida 12-38, Zona 4, Ciudad de Guatemala',
        'latitude' => 14.6248,
        'longitude' => -90.5152,
    ]);

    expect($place['latitude'])->toBeFloat();
    expect($place['longitude'])->toBeFloat();
});

it('manda un solo GET con el field mask del detalle', function () {
    Http::fake([DETAIL_URL => Http::response(googleDetailBody())]);

    googlePlaces()->getPlaceById(PLACE_ID);

    Http::assertSentCount(1);

    Http::assertSent(function (Request $request): bool {
        expect($request->method())->toBe('GET');
        expect($request->url())->toBe(DETAIL_URL);
        expect($request->url())->not->toContain('test-key');
        expect($request->header('X-Goog-Api-Key'))->toBe(['test-key']);
        expect($request->header('X-Goog-FieldMask'))->toBe(['id,formattedAddress,location']);

        return true;
    });
});

it('devuelve NotFoundError tanto si el id no existe como si está mal formado', function (int $status) {
    Http::fake([DETAIL_URL => Http::response(['error' => ['message' => 'no importa']], $status)]);

    try {
        googlePlaces()->getPlaceById(PLACE_ID);
    } catch (NotFoundError $error) {
        expect($error->getMessage())->toBe('La dirección no existe');

        return;
    }

    $this->fail('Se esperaba un NotFoundError.');
})->with([
    'id inexistente (404 de Google)' => [404],
    'id mal formado (400 de Google)' => [400],
]);

it('devuelve 503 y no coordenadas nulas cuando la respuesta viene incompleta', function (array $body) {
    Http::fake([DETAIL_URL => Http::response($body)]);

    googlePlaces()->getPlaceById(PLACE_ID);
})->with([
    'sin location' => [fn () => ['id' => PLACE_ID, 'formattedAddress' => 'Zona 4']],
    'location vacía' => [fn () => ['id' => PLACE_ID, 'formattedAddress' => 'Zona 4', 'location' => []]],
    'sin latitude' => [fn () => ['id' => PLACE_ID, 'formattedAddress' => 'Zona 4', 'location' => ['longitude' => -90.5152]]],
    'latitude no numérica' => [fn () => ['id' => PLACE_ID, 'formattedAddress' => 'Zona 4', 'location' => ['latitude' => 'norte', 'longitude' => -90.5152]]],
    'sin formattedAddress' => [fn () => ['id' => PLACE_ID, 'location' => ['latitude' => 14.6248, 'longitude' => -90.5152]]],
])->throws(ServiceUnavailableError::class);

it('traduce a 503 los fallos del proveedor también en el detalle', function (callable $fake) {
    Http::fake([DETAIL_URL => $fake()]);

    googlePlaces()->getPlaceById(PLACE_ID);
})->with([
    'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
    'clave rechazada (403)' => [fn () => Http::response(['error' => ['message' => 'PERMISSION_DENIED']], 403)],
    'cuota agotada (429)' => [fn () => Http::response('', 429)],
    'error de Google (500)' => [fn () => Http::response('', 500)],
    'cuerpo que no es JSON' => [fn () => Http::response('<html>502 Bad Gateway</html>')],
])->throws(ServiceUnavailableError::class);

it('no filtra al cliente el error del proveedor, ni la URL, ni la clave', function () {
    Http::fake([SEARCH_URL => Http::response(['error' => ['message' => 'API key not valid. Please pass a valid API key.']], 403)]);

    try {
        googlePlaces()->searchPlaces('zona 4 guatemala');
    } catch (ServiceUnavailableError $error) {
        expect($error->getMessage())
            ->toBe('El servicio de búsqueda de direcciones no está disponible en este momento. Intenta de nuevo en unos minutos.')
            ->not->toContain('API key')
            ->not->toContain('test-key')
            ->not->toContain('googleapis');
    }
});

/*
|--------------------------------------------------------------------------
| Ruta por carretera
|--------------------------------------------------------------------------
|
| La segunda API de Google del proyecto, con su propia URL, su propio field mask y su
| propia facturación, pero la misma credencial, el mismo timeout y el mismo 503
| genérico. Lo que cambia es la rama nueva: sin camino por carretera el proveedor
| contestó bien y la respuesta es 404, no 503.
|
*/

/**
 * Un cuerpo de ruta con la forma que devuelve el field mask pedido.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{routes: list<array<string, mixed>>}
 */
function googleRouteBody(array $overrides = []): array
{
    return [
        'routes' => [
            array_merge([
                'distanceMeters' => 104321,
                'duration' => '6300s',
                'polyline' => ['encodedPolyline' => ENCODED_POLYLINE],
            ], $overrides),
        ],
    ];
}

function googleDirections(): array
{
    return googlePlaces()->getDirections(
        ORIGIN_LATITUDE,
        ORIGIN_LONGITUDE,
        DESTINATION_LATITUDE,
        DESTINATION_LONGITUDE,
    );
}

it('devuelve distancia, duración, polilínea y puntos de la ruta', function () {
    Http::fake([ROUTES_URL => Http::response(googleRouteBody())]);

    $directions = googleDirections();

    expect(array_keys($directions))->toBe(['distanceKilometers', 'durationHours', 'polyline', 'points'])
        ->and($directions['distanceKilometers'])->toBe(104.32)
        ->and($directions['durationHours'])->toBe(1.75)
        ->and($directions['polyline'])->toBe(ENCODED_POLYLINE)
        ->and($directions['points'])->not->toBeEmpty()
        ->and($directions['points'][0])->toBe([14.6248, -90.5152]);
});

it('devuelve la distancia y la duración como números, no como cadenas', function () {
    Http::fake([ROUTES_URL => Http::response(googleRouteBody())]);

    $directions = googleDirections();

    expect($directions['distanceKilometers'])->toBeFloat()
        ->and($directions['durationHours'])->toBeFloat();
});

it('redondea a dos decimales y solo al final', function (int $meters, string $duration, float $kilometers, float $hours) {
    Http::fake([ROUTES_URL => Http::response(googleRouteBody(['distanceMeters' => $meters, 'duration' => $duration]))]);

    $directions = googleDirections();

    expect($directions['distanceKilometers'])->toBe($kilometers)
        ->and($directions['durationHours'])->toBe($hours);
})->with([
    'el ejemplo de la spec' => [104321, '6300s', 104.32, 1.75],
    'redondea hacia arriba' => [1999, '3599s', 2.0, 1.0],
    'una ruta corta' => [850, '120s', 0.85, 0.03],
    'segundos fraccionados, que protobuf admite' => [1000, '90.5s', 1.0, 0.03],
]);

it('manda una sola petición POST a la Routes API con el cuerpo y las cabeceras acordados', function () {
    Http::fake([ROUTES_URL => Http::response(googleRouteBody())]);

    googleDirections();

    Http::assertSentCount(1);

    Http::assertSent(function (Request $request): bool {
        expect($request->method())->toBe('POST');
        expect($request->url())->toBe(ROUTES_URL);

        /** La clave viaja en la cabecera y nunca en la query string, que acabaría en los logs. */
        expect($request->url())->not->toContain('test-key')
            ->and($request->url())->not->toContain('key=');

        expect($request->header('X-Goog-Api-Key'))->toBe(['test-key']);
        expect($request->header('X-Goog-FieldMask'))
            ->toBe(['routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline']);

        expect($request->data())->toBe([
            'origin' => ['location' => ['latLng' => ['latitude' => ORIGIN_LATITUDE, 'longitude' => ORIGIN_LONGITUDE]]],
            'destination' => ['location' => ['latLng' => ['latitude' => DESTINATION_LATITUDE, 'longitude' => DESTINATION_LONGITUDE]]],
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_UNAWARE',
            'polylineQuality' => 'OVERVIEW',
            'computeAlternativeRoutes' => false,
            'units' => 'METRIC',
            'languageCode' => 'es',
            'regionCode' => 'GT',
        ]);

        return true;
    });
});

it('no pide el field mask completo, que factura al tramo más caro', function () {
    Http::fake([ROUTES_URL => Http::response(googleRouteBody())]);

    googleDirections();

    Http::assertSent(function (Request $request): bool {
        expect($request->header('X-Goog-FieldMask')[0])->not->toBe('*')
            ->not->toBe('routes.*');

        return true;
    });
});

it('manda el destino como destino y el origen como origen, no al revés', function () {
    Http::fake([ROUTES_URL => Http::response(googleRouteBody())]);

    googleDirections();

    Http::assertSent(function (Request $request): bool {
        expect($request->data()['destination']['location']['latLng']['latitude'])->toBe(DESTINATION_LATITUDE)
            ->and($request->data()['origin']['location']['latLng']['latitude'])->toBe(ORIGIN_LATITUDE);

        return true;
    });
});

it('devuelve 404 cuando el proveedor contestó bien pero no hay camino', function (array $body) {
    Http::fake([ROUTES_URL => Http::response($body)]);

    googleDirections();
})->with([
    'sin la clave routes' => [fn (): array => []],
    'con routes vacío' => [fn (): array => ['routes' => []]],
])->throws(NotFoundError::class, 'No se encontró una ruta hacia el destino');

it('hace una sola petición también cuando no hay ruta', function () {
    Http::fake([ROUTES_URL => Http::response(['routes' => []])]);

    try {
        googleDirections();
    } catch (NotFoundError) {
        // Lo que se mide aquí es que no hubo reintento.
    }

    Http::assertSentCount(1);
});

it('devuelve 503 y no una ruta a medias cuando la respuesta viene rota', function (array $body) {
    Http::fake([ROUTES_URL => Http::response($body)]);

    googleDirections();
})->with([
    'routes que no es lista' => [fn (): array => ['routes' => 'ninguna']],
    'ruta que no es objeto' => [fn (): array => ['routes' => ['ninguna']]],
    'sin distanceMeters' => [fn (): array => ['routes' => [['duration' => '6300s', 'polyline' => ['encodedPolyline' => ENCODED_POLYLINE]]]]],
    'distanceMeters no numérico' => [fn (): array => googleRouteBody(['distanceMeters' => 'lejos'])],
    'sin duration' => [fn (): array => ['routes' => [['distanceMeters' => 104321, 'polyline' => ['encodedPolyline' => ENCODED_POLYLINE]]]]],
    'duration sin el sufijo s' => [fn (): array => googleRouteBody(['duration' => '6300'])],
    'duration no numérica' => [fn (): array => googleRouteBody(['duration' => 'seis mil s'])],
    'duration como entero pelado' => [fn (): array => googleRouteBody(['duration' => 6300])],
    'duration como objeto' => [fn (): array => googleRouteBody(['duration' => ['seconds' => 6300]])],
    'sin polyline' => [fn (): array => ['routes' => [['distanceMeters' => 104321, 'duration' => '6300s']]]],
    'polyline sin encodedPolyline' => [fn (): array => googleRouteBody(['polyline' => []])],
    'polyline que no es objeto' => [fn (): array => googleRouteBody(['polyline' => ENCODED_POLYLINE])],
    'encodedPolyline vacía' => [fn (): array => googleRouteBody(['polyline' => ['encodedPolyline' => '']])],
    'encodedPolyline que no decodifica a ningún punto' => [fn (): array => googleRouteBody(['polyline' => ['encodedPolyline' => '_']])],
])->throws(ServiceUnavailableError::class);

it('traduce a 503 los fallos del proveedor también en la ruta', function (callable $fake) {
    Http::fake([ROUTES_URL => $fake()]);

    googleDirections();
})->with([
    'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
    'error de conexión' => [fn () => fn () => throw new ConnectionException('cURL error 6: Could not resolve host')],
    'clave rechazada (401)' => [fn () => Http::response(['error' => ['message' => 'UNAUTHENTICATED']], 401)],
    'clave rechazada (403)' => [fn () => Http::response(['error' => ['message' => 'PERMISSION_DENIED']], 403)],
    'cuota agotada (429)' => [fn () => Http::response('', 429)],
    'error de Google (500)' => [fn () => Http::response('', 500)],
    'cuerpo que no es JSON' => [fn () => Http::response('<html>502 Bad Gateway</html>')],
])->throws(ServiceUnavailableError::class);

it('no reintenta cuando la ruta falla: una llamada, una petición', function () {
    Http::fake([ROUTES_URL => Http::response('', 500)]);

    try {
        googleDirections();
    } catch (ServiceUnavailableError) {
        // Cada llamada se paga: un fallo no puede convertirse en tres.
    }

    Http::assertSentCount(1);
});

it('no filtra el error del proveedor, ni la URL, ni la clave al fallar la ruta', function () {
    Http::fake([ROUTES_URL => Http::response(['error' => ['message' => 'API key not valid. Please pass a valid API key.']], 403)]);

    try {
        googleDirections();
    } catch (ServiceUnavailableError $error) {
        expect($error->getMessage())
            ->toBe('El servicio de búsqueda de direcciones no está disponible en este momento. Intenta de nuevo en unos minutos.')
            ->not->toContain('API key')
            ->not->toContain('test-key')
            ->not->toContain('googleapis')
            ->not->toContain('computeRoutes');
    }
});
