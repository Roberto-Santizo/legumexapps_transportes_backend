<?php

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
