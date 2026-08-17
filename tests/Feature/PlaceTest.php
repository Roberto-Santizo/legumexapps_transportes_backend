<?php

use App\Enums\UserRole;
use App\Interfaces\Place\PlaceServiceInterface;
use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Doubles\InMemoryPlaceService;
use Tests\TestCase;

/**
 * The two — and only two — places endpoints, as method and URI.
 *
 * The domain has no store, update nor destroy: those routes do not exist and must
 * not exist. There is no model, no table and no factory behind them either.
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
 */
function usePlaceDouble(bool $failing = false): void
{
    app()->bind(PlaceServiceInterface::class, fn (): InMemoryPlaceService => new InMemoryPlaceService(failing: $failing));
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
