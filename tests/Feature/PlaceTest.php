<?php

use App\Enums\UserRole;
use App\Interfaces\Place\PlaceServiceInterface;
use App\Models\Location;
use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Doubles\InMemoryPlaceService;
use Tests\TestCase;

/**
 * The two places endpoints whose URI is a constant, as method and URI.
 *
 * The domain has no store, update nor destroy: those routes do not exist and must
 * not exist. There is no model, no table and no factory behind them either.
 *
 * GET /api/places/directions se queda deliberadamente FUERA de este helper: su URI
 * necesita el id de un destino recién creado con la factory, así que no puede ser una
 * constante, y sus casos de error no coinciden con los del dataset (una llamada sin
 * destino válido muere en el 422 del FormRequest y no llega nunca al proveedor, así que
 * no podría compartir el caso del 503). Se cubre con tests propios más abajo.
 *
 * @return array<string, array{string, string}>
 */
function placeEndpoints(string $place = 'ChIJzona4'): array
{
    return [
        'index' => ['GET', '/api/places?search=zona'],
        'show' => ['GET', "/api/places/{$place}"],
    ];
}

/**
 * Swap the place contract for the in-memory double.
 *
 * Every test of this file goes through here: tests/Pest.php keeps
 * Http::preventStrayRequests() on, so a request reaching the real provider would
 * blow the test up instead of leaving the machine. That the whole suite passes
 * with the double bound is exactly what proves the contract is substitutable.
 *
 * @param  bool  $failing  Binds a provider that is down, the 503 path.
 * @param  bool  $routeless  Binds a provider that answers but finds no road route, the
 *                           404 path of GET /api/places/directions. Independiente de
 *                           $failing: un proveedor caído no llega a buscar camino.
 */
function usePlaceDouble(bool $failing = false, bool $routeless = false): void
{
    app()->bind(PlaceServiceInterface::class, fn (): InMemoryPlaceService => new InMemoryPlaceService(failing: $failing, routeless: $routeless));
}

if (! function_exists('userWithRole')) {
    /**
     * Create a confirmed user with the given role.
     */
    function userWithRole(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }
}

if (! function_exists('asUser')) {
    /**
     * Authenticate the next request as the given user.
     *
     * The JWT singletons survive between calls of the same test, so the guard
     * state is dropped before handing the fresh token over.
     */
    function asUser(User $user): TestCase
    {
        resetAuthState();

        return test()->withToken(JWTAuth::fromUser($user))->withHeader('Accept', 'application/json');
    }
}

/*
|--------------------------------------------------------------------------
| Middleware: jwt.auth y nada más
|--------------------------------------------------------------------------
*/

it('rechaza con 401 cualquier endpoint de direcciones sin token', function (string $method, string $uri) {
    usePlaceDouble();

    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(placeEndpoints());

it('deja pasar a los cuatro roles en cualquier endpoint de direcciones', function (UserRole $role, string $method, string $uri) {
    usePlaceDouble();

    asUser(userWithRole($role))->json($method, $uri)->assertOk();
})->with([
    'administrador' => [UserRole::Administrator],
    'transportista' => [UserRole::Carrier],
    'piloto' => [UserRole::Pilot],
    'encargado' => [UserRole::Manager],
])->with(placeEndpoints());

/** Ninguna ruta lleva carrier.required: esto es lo que separa el dominio de Vehicles y Pilots. */
it('deja buscar y consultar direcciones a un transportista sin empresa registrada', function () {
    usePlaceDouble();

    $carrier = userWithRole(UserRole::Carrier);

    expect($carrier->currentCarrier())->toBeNull();

    asUser($carrier)->getJson('/api/places?search=zona')->assertOk();
    asUser($carrier)->getJson('/api/places/ChIJzona4')->assertOk();
});

/*
|--------------------------------------------------------------------------
| Búsqueda: GET /api/places?search=
|--------------------------------------------------------------------------
*/

it('devuelve las direcciones que coinciden con el texto buscado', function () {
    usePlaceDouble();

    $response = asUser(userWithRole(UserRole::Pilot))->getJson('/api/places?search=Zona')
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Direcciones obtenidas correctamente')
        ->assertJsonCount(2, 'data');

    expect($response->json('data.0.formattedAddress'))->toContain('Zona 4');
});

/** El listado es una predicción pelada: sin coordenadas, que se pagan aparte en el detalle. */
it('devuelve en cada dirección exactamente las claves id y formattedAddress', function () {
    usePlaceDouble();

    $data = asUser(userWithRole(UserRole::Administrator))->getJson('/api/places?search=zona')
        ->assertOk()
        ->json('data');

    expect($data)->toBeArray()->not->toBeEmpty();

    foreach ($data as $place) {
        expect(array_keys($place))->toEqualCanonicalizing(['id', 'formattedAddress']);
    }
});

it('responde 200 con una lista vacía cuando no hay coincidencias', function () {
    usePlaceDouble();

    asUser(userWithRole(UserRole::Manager))->getJson('/api/places?search=direccion inexistente')
        ->assertOk()
        ->assertExactJson([
            'statusCode' => 200,
            'message' => 'Direcciones obtenidas correctamente',
            'data' => [],
        ]);
});

it('acepta un texto de búsqueda de exactamente 3 caracteres', function () {
    usePlaceDouble();

    asUser(userWithRole(UserRole::Carrier))->getJson('/api/places?search=zon')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('acepta un texto de búsqueda de exactamente 200 caracteres', function () {
    usePlaceDouble();

    asUser(userWithRole(UserRole::Carrier))->getJson('/api/places?search='.urlencode(str_repeat('a', 200)))
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('rechaza con 422 un texto de búsqueda inválido', function (string $query, string $message) {
    usePlaceDouble();

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/places{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['search'])
        ->assertJsonPath('errors.search.0', $message);
})->with([
    'ausente' => ['', 'El texto de búsqueda es obligatorio'],
    'cadena vacía' => ['?search=', 'El texto de búsqueda es obligatorio'],
    'un carácter' => ['?search=z', 'El texto de búsqueda debe tener al menos 3 caracteres'],
    'dos caracteres' => ['?search=zo', 'El texto de búsqueda debe tener al menos 3 caracteres'],
    '201 caracteres' => ['?search='.str_repeat('a', 201), 'El texto de búsqueda no puede superar los 200 caracteres'],
]);

/** El 422 sale con el formato de Laravel, no con el sobre de ResponseHandler. */
it('devuelve el 422 con el formato de Laravel y la clave search', function () {
    usePlaceDouble();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/places')
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['search']]);

    expect($response->json('errors.search'))->toBeArray();
});

it('ignora cualquier query param extra de la búsqueda', function () {
    usePlaceDouble();

    $esperado = asUser(userWithRole(UserRole::Pilot))->getJson('/api/places?search=zona')
        ->assertOk()
        ->json();

    asUser(userWithRole(UserRole::Pilot))
        ->getJson('/api/places?search=zona&limit=1&pageSize=1&regionCode=US')
        ->assertOk()
        ->assertExactJson($esperado);
});

/*
|--------------------------------------------------------------------------
| Detalle: GET /api/places/{place}
|--------------------------------------------------------------------------
*/

it('devuelve la dirección y sus coordenadas de un place id conocido', function () {
    usePlaceDouble();

    $place = InMemoryPlaceService::ids()[0];

    asUser(userWithRole(UserRole::Carrier))->getJson("/api/places/{$place}")
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Dirección obtenida correctamente')
        ->assertJsonPath('data.id', $place)
        ->assertJsonPath('data.formattedAddress', '5a Avenida 12-38, Zona 4, Ciudad de Guatemala')
        ->assertJsonStructure(['statusCode', 'message', 'data' => ['id', 'formattedAddress', 'latitude', 'longitude']]);
});

/** Son lo que el front pasa tal cual como lat y lng a la cotización de SPEC 09. */
it('devuelve las coordenadas como números y en la raíz del detalle', function () {
    usePlaceDouble();

    $place = InMemoryPlaceService::ids()[2];

    $data = asUser(userWithRole(UserRole::Manager))->getJson("/api/places/{$place}")
        ->assertOk()
        ->json('data');

    expect($data['latitude'])->toBeFloat()->toBe(15.4711)
        ->and($data['longitude'])->toBeFloat()->toBe(-90.3711)
        ->and(array_keys($data))->toEqualCanonicalizing(['id', 'formattedAddress', 'latitude', 'longitude'])
        ->and($data)->not->toHaveKey('geometry')
        ->and($data)->not->toHaveKey('location');
});

it('responde 404 cuando el place id no corresponde a ninguna dirección', function () {
    usePlaceDouble();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/places/ChIJinventado')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'La dirección no existe',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| Proveedor caído
|--------------------------------------------------------------------------
*/

it('responde 503 en cualquier endpoint de direcciones cuando el proveedor está caído', function (string $method, string $uri) {
    usePlaceDouble(failing: true);

    asUser(userWithRole(UserRole::Administrator))->json($method, $uri)
        ->assertServiceUnavailable()
        ->assertExactJson([
            'statusCode' => 503,
            'message' => 'El servicio de búsqueda de direcciones no está disponible en este momento. Intenta de nuevo en unos minutos.',
            'data' => null,
        ]);
})->with(placeEndpoints());

/*
|--------------------------------------------------------------------------
| Ruta: GET /api/places/directions
|--------------------------------------------------------------------------
*/

/**
 * Build the directions URI for a destination and an origin.
 *
 * El origen por defecto es la Ciudad de Guatemala, que es de donde sale la carga en
 * la práctica; los tests que necesitan otro lo pasan explícito.
 *
 * @param  array<string, scalar>  $extra  Parámetros de más, para probar que se ignoran.
 */
function directionsUri(int $locationId, float $lat = 14.6248, float $lng = -90.5152, array $extra = []): string
{
    return '/api/places/directions?'.http_build_query(['locationId' => $locationId, 'lat' => $lat, 'lng' => $lng] + $extra);
}

it('rechaza con 401 la ruta hacia un destino sin token', function () {
    usePlaceDouble();

    $location = Location::factory()->create();

    $this->getJson(directionsUri($location->id))
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
});

it('deja calcular la ruta a los cuatro roles', function (UserRole $role) {
    usePlaceDouble();

    $location = Location::factory()->create();

    asUser(userWithRole($role))->getJson(directionsUri($location->id))->assertOk();
})->with([
    'administrador' => [UserRole::Administrator],
    'transportista' => [UserRole::Carrier],
    'piloto' => [UserRole::Pilot],
    'encargado' => [UserRole::Manager],
]);

/** La ruta tampoco lleva carrier.required: es una lectura, no una operación de empresa. */
it('deja calcular la ruta a un transportista sin empresa registrada', function () {
    usePlaceDouble();

    $carrier = userWithRole(UserRole::Carrier);
    $location = Location::factory()->create();

    expect($carrier->currentCarrier())->toBeNull();

    asUser($carrier)->getJson(directionsUri($location->id))->assertOk();
});

it('devuelve la ruta hacia el destino con el sobre y el mensaje del proyecto', function () {
    usePlaceDouble();

    $location = Location::factory()->active()->create();
    $route = InMemoryPlaceService::route();

    asUser(userWithRole(UserRole::Pilot))->getJson(directionsUri($location->id))
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Ruta obtenida correctamente')
        ->assertJsonPath('data.polyline', $route['polyline'])
        ->assertJsonPath('data.points', $route['points'])
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => ['locationId', 'locationName', 'distanceKilometers', 'durationHours', 'polyline', 'points'],
        ]);
});

it('devuelve en la ruta exactamente las seis claves del recurso', function () {
    usePlaceDouble();

    $location = Location::factory()->create();

    $data = asUser(userWithRole(UserRole::Administrator))->getJson(directionsUri($location->id))
        ->assertOk()
        ->json('data');

    expect(array_keys($data))->toEqualCanonicalizing([
        'locationId', 'locationName', 'distanceKilometers', 'durationHours', 'polyline', 'points',
    ]);
});

/** No son dinero: salen como números, sin el formateo a dos decimales del resto de la API. */
it('devuelve la distancia y la duración como números y no como cadenas', function () {
    usePlaceDouble();

    $location = Location::factory()->create();
    $route = InMemoryPlaceService::route();

    $data = asUser(userWithRole(UserRole::Manager))->getJson(directionsUri($location->id))
        ->assertOk()
        ->json('data');

    expect($data['distanceKilometers'])->toBeFloat()->toBe($route['distanceKilometers'])
        ->and($data['durationHours'])->toBeFloat()->toBe($route['durationHours']);
});

/** El proveedor nunca sabe que existe un destino registrado: la identidad sale de la BD. */
it('devuelve el id y el nombre del destino guardado en la base, no del proveedor', function () {
    usePlaceDouble();

    $location = Location::factory()->create(['name' => Location::normalizeName('bodega  central  escuintla')]);

    asUser(userWithRole(UserRole::Carrier))->getJson(directionsUri($location->id))
        ->assertOk()
        ->assertJsonPath('data.locationId', $location->id)
        ->assertJsonPath('data.locationName', 'BODEGA CENTRAL ESCUINTLA');
});

it('devuelve la polilínea como cadena y los puntos como pares no vacíos', function () {
    usePlaceDouble();

    $location = Location::factory()->create();
    $route = InMemoryPlaceService::route();

    $data = asUser(userWithRole(UserRole::Pilot))->getJson(directionsUri($location->id))
        ->assertOk()
        ->json('data');

    expect($data['polyline'])->toBeString()->toBe($route['polyline'])
        ->and($data['points'])->toBeArray()->not->toBeEmpty()
        ->and($data['points'][0])->toBe($route['points'][0]);

    /** Cada punto es un par [lat, lng] en ese orden, el mismo que hablan las zonas. */
    foreach ($data['points'] as $point) {
        expect($point)->toBeArray()->toHaveCount(2)
            ->and($point[0])->toBeFloat()
            ->and($point[1])->toBeFloat();
    }
});

/** Cero es un valor, no un parámetro ausente: el golfo de Guinea es un origen válido. */
it('acepta un origen en la coordenada cero', function () {
    usePlaceDouble();

    $location = Location::factory()->create();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/places/directions?locationId='.$location->id.'&lat=0&lng=0')
        ->assertOk()
        ->assertJsonPath('message', 'Ruta obtenida correctamente');
});

/** travelMode, polylineQuality y limit son constantes del service, no elecciones del cliente. */
it('ignora cualquier query param extra de la ruta', function () {
    usePlaceDouble();

    $location = Location::factory()->create();

    $esperado = asUser(userWithRole(UserRole::Pilot))->getJson(directionsUri($location->id))
        ->assertOk()
        ->json();

    asUser(userWithRole(UserRole::Pilot))
        ->getJson(directionsUri($location->id, extra: ['travelMode' => 'WALK', 'polylineQuality' => 'HIGH_QUALITY', 'limit' => 1]))
        ->assertOk()
        ->assertExactJson($esperado);
});

/*
|--------------------------------------------------------------------------
| Ruta: validación de los tres parámetros
|--------------------------------------------------------------------------
*/

it('rechaza con 422 una ruta con parámetros inválidos', function (array $params, string $campo, string $mensaje) {
    usePlaceDouble();

    $location = Location::factory()->create();

    /** El centinela se sustituye por el id real, que solo se conoce dentro del test. */
    if (($params['locationId'] ?? null) === 'valido') {
        $params['locationId'] = $location->id;
    }

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/places/directions?'.http_build_query($params))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$campo])
        ->assertJsonPath("errors.{$campo}.0", $mensaje);
})->with([
    'destino ausente' => [
        ['lat' => 14.6248, 'lng' => -90.5152], 'locationId', 'El destino es obligatorio',
    ],
    'destino no numérico' => [
        ['locationId' => 'abc', 'lat' => 14.6248, 'lng' => -90.5152], 'locationId', 'El destino debe ser un identificador numérico',
    ],
    /** Un id inexistente lo atrapa el exists: del FormRequest, así que es 422 y no 404. */
    'destino inexistente' => [
        ['locationId' => 999999, 'lat' => 14.6248, 'lng' => -90.5152], 'locationId', 'El destino seleccionado no existe',
    ],
    'latitud ausente' => [
        ['locationId' => 'valido', 'lng' => -90.5152], 'lat', 'La latitud de origen es obligatoria',
    ],
    'latitud no numérica' => [
        ['locationId' => 'valido', 'lat' => 'norte', 'lng' => -90.5152], 'lat', 'La latitud de origen debe ser numérica',
    ],
    'latitud por encima de 90' => [
        ['locationId' => 'valido', 'lat' => 90.1, 'lng' => -90.5152], 'lat', 'La latitud de origen debe estar entre -90 y 90',
    ],
    'latitud por debajo de -90' => [
        ['locationId' => 'valido', 'lat' => -90.1, 'lng' => -90.5152], 'lat', 'La latitud de origen debe estar entre -90 y 90',
    ],
    'longitud ausente' => [
        ['locationId' => 'valido', 'lat' => 14.6248], 'lng', 'La longitud de origen es obligatoria',
    ],
    'longitud no numérica' => [
        ['locationId' => 'valido', 'lat' => 14.6248, 'lng' => 'oeste'], 'lng', 'La longitud de origen debe ser numérica',
    ],
    'longitud por encima de 180' => [
        ['locationId' => 'valido', 'lat' => 14.6248, 'lng' => 180.1], 'lng', 'La longitud de origen debe estar entre -180 y 180',
    ],
    'longitud por debajo de -180' => [
        ['locationId' => 'valido', 'lat' => 14.6248, 'lng' => -180.1], 'lng', 'La longitud de origen debe estar entre -180 y 180',
    ],
]);

it('devuelve los tres errores a la vez cuando la ruta llega sin ningún parámetro', function () {
    usePlaceDouble();

    asUser(userWithRole(UserRole::Pilot))->getJson('/api/places/directions')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['locationId', 'lat', 'lng'])
        ->assertJsonPath('errors.locationId.0', 'El destino es obligatorio')
        ->assertJsonPath('errors.lat.0', 'La latitud de origen es obligatoria')
        ->assertJsonPath('errors.lng.0', 'La longitud de origen es obligatoria');
});

/** El 422 sale con el formato de Laravel, no con el sobre de ResponseHandler. */
it('devuelve el 422 de la ruta con el formato de Laravel', function () {
    usePlaceDouble();

    $response = asUser(userWithRole(UserRole::Manager))->getJson('/api/places/directions')
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['locationId', 'lat', 'lng']])
        ->assertJsonMissingPath('statusCode')
        ->assertJsonMissingPath('data');

    expect($response->json('errors.locationId'))->toBeArray();
});

/*
|--------------------------------------------------------------------------
| Ruta: los tres fallos, en su orden
|--------------------------------------------------------------------------
*/

/** Fila viva, uso prohibido: 400, no 404. Y el proveedor no llega a facturar la llamada. */
it('responde 400 cuando el destino está inactivo', function () {
    usePlaceDouble();

    $location = Location::factory()->inactive()->create();

    asUser(userWithRole(UserRole::Administrator))->getJson(directionsUri($location->id))
        ->assertBadRequest()
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El destino seleccionado no está activo',
            'data' => null,
        ]);
});

/** El destino se resuelve PRIMERO: un proveedor caído no cambia el 400 del destino inactivo. */
it('responde 400 por el destino inactivo antes de tocar un proveedor caído', function () {
    usePlaceDouble(failing: true);

    $location = Location::factory()->inactive()->create();

    asUser(userWithRole(UserRole::Carrier))->getJson(directionsUri($location->id))
        ->assertBadRequest()
        ->assertJsonPath('message', 'El destino seleccionado no está activo');
});

/** El proveedor respondió y no hay camino por carretera: eso es 404, no 503. */
it('responde 404 cuando no existe una ruta hacia el destino', function () {
    usePlaceDouble(routeless: true);

    $location = Location::factory()->create();

    asUser(userWithRole(UserRole::Pilot))->getJson(directionsUri($location->id))
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'No se encontró una ruta hacia el destino',
            'data' => null,
        ]);
});

it('responde 503 en la ruta cuando el proveedor está caído', function () {
    usePlaceDouble(failing: true);

    $location = Location::factory()->active()->create();

    asUser(userWithRole(UserRole::Manager))->getJson(directionsUri($location->id))
        ->assertServiceUnavailable()
        ->assertExactJson([
            'statusCode' => 503,
            'message' => 'El servicio de búsqueda de direcciones no está disponible en este momento. Intenta de nuevo en unos minutos.',
            'data' => null,
        ]);
});
