<?php

use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\CarrierPilot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Every carriers endpoint as method and URI, for the middleware datasets.
 *
 * @return array<string, array{string, string}>
 */
function carrierEndpoints(): array
{
    return [
        'index' => ['GET', '/api/carriers'],
        'store' => ['POST', '/api/carriers'],
        'show' => ['GET', '/api/carriers/1'],
        'update' => ['PATCH', '/api/carriers/1'],
        'destroy' => ['DELETE', '/api/carriers/1'],
        'join' => ['POST', '/api/carriers/join'],
        'me' => ['GET', '/api/carriers/me'],
        'me/pilots' => ['GET', '/api/carriers/me/pilots'],
    ];
}

/**
 * Create a confirmed user with the given role.
 */
function userWithRole(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

/**
 * Authenticate the next request as the given user.
 *
 * The JWT singletons survive between calls of the same test, so the guard state
 * is dropped before handing the fresh token over.
 */
function asUser(User $user): TestCase
{
    resetAuthState();

    return test()->withToken(JWTAuth::fromUser($user))->withHeader('Accept', 'application/json');
}

/**
 * Read the claims of a token without leaving the parsed token behind.
 *
 * @return array<string, mixed>
 */
function claimsOf(string $token): array
{
    resetAuthState();

    $claims = JWTAuth::setToken($token)->getPayload()->toArray();

    resetAuthState();

    return $claims;
}

/**
 * A valid store payload, with the image as an uploaded file.
 *
 * @return array<string, mixed>
 */
function validCarrierPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Transportes del Norte',
        'image' => UploadedFile::fake()->image('logo.png'),
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth, role y carrier.required
|--------------------------------------------------------------------------
*/

it('rechaza con 401 cualquier endpoint de carriers sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(carrierEndpoints());

it('rechaza con 403 a un manager en cualquier endpoint de carriers', function (string $method, string $uri) {
    $manager = userWithRole(UserRole::Manager);

    asUser($manager)->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(carrierEndpoints());

it('rechaza con 403 a un piloto que intenta crear una empresa transportista', function () {
    $pilot = userWithRole(UserRole::Pilot);

    asUser($pilot)->post('/api/carriers', validCarrierPayload())
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);

    $this->assertDatabaseCount('carriers', 0);
});

it('bloquea con carrier.required a un carrier sin empresa', function () {
    $carrier = userWithRole(UserRole::Carrier);

    asUser($carrier)->getJson('/api/carriers/me')
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'Debes estar vinculado a un transportista para acceder a este recurso',
            'data' => null,
        ]);
});

it('deja pasar carrier.required a un administrador sin empresa', function () {
    Carrier::factory()->count(2)->create();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/carriers')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('no bloquea check-status con carrier.required', function () {
    $pilot = userWithRole(UserRole::Pilot);

    asUser($pilot)->getJson(route('auth.check-status'))
        ->assertOk()
        ->assertJsonPath('data.user.id', $pilot->id);
});

/*
|--------------------------------------------------------------------------
| Claims del JWT
|--------------------------------------------------------------------------
*/

it('emite el token de un carrier con empresa con los tres claims poblados', function () {
    $carrier = Carrier::factory()->create();

    $claims = claimsOf(JWTAuth::fromUser($carrier->owner));

    expect($claims['carrierId'])->toBe($carrier->id)
        ->and($claims['carrierName'])->toBe($carrier->name)
        ->and($claims['carrierCode'])->toBe($carrier->code);
});

it('emite el token de un piloto vinculado con los claims de su empresa', function () {
    $carrier = Carrier::factory()->create();
    $pilot = userWithRole(UserRole::Pilot);

    CarrierPilot::create(['carrier_id' => $carrier->id, 'user_id' => $pilot->id]);

    $claims = claimsOf(JWTAuth::fromUser($pilot->fresh()));

    expect($claims['carrierId'])->toBe($carrier->id)
        ->and($claims['carrierName'])->toBe($carrier->name)
        ->and($claims['carrierCode'])->toBe($carrier->code);
});

it('emite los tres claims de transportista en null cuando el usuario no tiene empresa', function (UserRole $role) {
    $claims = claimsOf(JWTAuth::fromUser(userWithRole($role)));

    expect($claims['carrierId'])->toBeNull()
        ->and($claims['carrierName'])->toBeNull()
        ->and($claims['carrierCode'])->toBeNull();
})->with([
    'carrier sin empresa' => UserRole::Carrier,
    'piloto sin vincular' => UserRole::Pilot,
    'administrador' => UserRole::Administrator,
    'manager' => UserRole::Manager,
]);

it('devuelve en check-status un token con los claims ya poblados tras crear la empresa', function () {
    $user = userWithRole(UserRole::Carrier);

    /** El token que el front ya tiene en la mano: se emitió antes de existir la empresa. */
    $staleToken = JWTAuth::fromUser($user);

    expect(claimsOf($staleToken)['carrierId'])->toBeNull();

    resetAuthState();

    $this->withToken($staleToken)
        ->withHeader('Accept', 'application/json')
        ->post('/api/carriers', validCarrierPayload())
        ->assertCreated();

    resetAuthState();

    $renewed = $this->withToken($staleToken)
        ->getJson(route('auth.check-status'))
        ->assertOk()
        ->json('data.token');

    $carrier = Carrier::query()->firstOrFail();
    $claims = claimsOf($renewed);

    expect($claims['carrierId'])->toBe($carrier->id)
        ->and($claims['carrierName'])->toBe($carrier->name)
        ->and($claims['carrierCode'])->toBe($carrier->code);
});

/*
|--------------------------------------------------------------------------
| POST /api/carriers
|--------------------------------------------------------------------------
*/

it('crea la empresa de un carrier y devuelve 201 con el recurso', function () {
    $user = userWithRole(UserRole::Carrier);

    $response = asUser($user)->post('/api/carriers', validCarrierPayload());

    $response->assertCreated()
        ->assertJson([
            'statusCode' => 201,
            'message' => 'Empresa transportista creada correctamente',
            'data' => [
                'name' => 'Transportes del Norte',
                'active' => true,
            ],
        ])
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => ['id', 'name', 'image', 'code', 'active'],
        ]);

    $this->assertDatabaseHas('carriers', [
        'user_id' => $user->id,
        'name' => 'Transportes del Norte',
        'active' => true,
    ]);
});

it('devuelve un código de 6 caracteres alfanuméricos en mayúsculas', function () {
    $response = asUser(userWithRole(UserRole::Carrier))->post('/api/carriers', validCarrierPayload());

    expect($response->json('data.code'))->toMatch('/^[A-Z0-9]{6}$/');
});

it('nunca repite el código entre dos empresas creadas seguidas', function () {
    $primero = asUser(userWithRole(UserRole::Carrier))
        ->post('/api/carriers', validCarrierPayload())
        ->assertCreated()
        ->json('data.code');

    $segundo = asUser(userWithRole(UserRole::Carrier))
        ->post('/api/carriers', validCarrierPayload())
        ->assertCreated()
        ->json('data.code');

    expect($segundo)->not->toBe($primero);
});

it('guarda un uuid con la extensión del archivo y no escribe nada en storage', function () {
    Storage::fake('local');
    Storage::fake('public');

    $response = asUser(userWithRole(UserRole::Carrier))
        ->post('/api/carriers', validCarrierPayload(['image' => UploadedFile::fake()->image('logo.png')]));

    $image = $response->assertCreated()->json('data.image');

    expect($image)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.png$/')
        ->and(Storage::disk('local')->allFiles())->toBeEmpty()
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();

    $this->assertDatabaseHas('carriers', ['image' => $image]);
});

it('rechaza con 422 una imagen que no es jpg, jpeg ni png', function () {
    asUser(userWithRole(UserRole::Carrier))
        ->post('/api/carriers', validCarrierPayload([
            'image' => UploadedFile::fake()->create('documento.pdf', 10, 'application/pdf'),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image']);

    $this->assertDatabaseCount('carriers', 0);
});

it('valida los campos obligatorios al crear la empresa', function (array $payload, string $field) {
    asUser(userWithRole(UserRole::Carrier))
        ->post('/api/carriers', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'sin nombre' => [['image' => null], 'name'],
    'sin imagen' => [['name' => 'Transportes del Norte'], 'image'],
]);

it('rechaza con 400 a un carrier que ya tiene empresa registrada', function () {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)->post('/api/carriers', validCarrierPayload())
        ->assertBadRequest()
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'Ya tienes una empresa transportista registrada',
            'data' => null,
        ]);

    $this->assertDatabaseCount('carriers', 1);
});

it('permite que dos empresas distintas se llamen igual', function () {
    asUser(userWithRole(UserRole::Carrier))
        ->post('/api/carriers', validCarrierPayload(['name' => 'Transportes Iguales']))
        ->assertCreated();

    asUser(userWithRole(UserRole::Carrier))
        ->post('/api/carriers', validCarrierPayload(['name' => 'Transportes Iguales']))
        ->assertCreated();

    expect(Carrier::query()->where('name', '=', 'Transportes Iguales')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| POST /api/carriers/join
|--------------------------------------------------------------------------
*/

it('vincula al piloto con la empresa dueña del código enviado', function () {
    $carrier = Carrier::factory()->create(['code' => 'A7K2QX']);
    $pilot = userWithRole(UserRole::Pilot);

    asUser($pilot)->postJson('/api/carriers/join', ['code' => 'A7K2QX'])
        ->assertOk()
        ->assertExactJson([
            'statusCode' => 200,
            'message' => 'Te has vinculado a la empresa transportista correctamente',
            'data' => null,
        ]);

    $this->assertDatabaseHas('carrier_pilots', [
        'carrier_id' => $carrier->id,
        'user_id' => $pilot->id,
    ]);
});

it('vincula igual cuando el código llega en minúsculas', function () {
    $carrier = Carrier::factory()->create(['code' => 'A7K2QX']);
    $pilot = userWithRole(UserRole::Pilot);

    asUser($pilot)->postJson('/api/carriers/join', ['code' => 'a7k2qx'])
        ->assertOk();

    $this->assertDatabaseHas('carrier_pilots', [
        'carrier_id' => $carrier->id,
        'user_id' => $pilot->id,
    ]);
});

it('devuelve 404 cuando el código no pertenece a ninguna empresa', function () {
    Carrier::factory()->create(['code' => 'A7K2QX']);

    asUser(userWithRole(UserRole::Pilot))
        ->postJson('/api/carriers/join', ['code' => 'ZZZZZZ'])
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El código no pertenece a ninguna empresa transportista',
            'data' => null,
        ]);

    $this->assertDatabaseCount('carrier_pilots', 0);
});

it('rechaza con 400 a un piloto que ya pertenece a una empresa', function () {
    $original = Carrier::factory()->create(['code' => 'A7K2QX']);
    $otra = Carrier::factory()->create(['code' => 'B8L3RY']);
    $pilot = userWithRole(UserRole::Pilot);

    CarrierPilot::create(['carrier_id' => $original->id, 'user_id' => $pilot->id]);

    asUser($pilot)->postJson('/api/carriers/join', ['code' => 'B8L3RY'])
        ->assertBadRequest()
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'Ya perteneces a una empresa transportista',
            'data' => null,
        ]);

    $this->assertDatabaseCount('carrier_pilots', 1);
    $this->assertDatabaseHas('carrier_pilots', [
        'carrier_id' => $original->id,
        'user_id' => $pilot->id,
    ]);
    $this->assertDatabaseMissing('carrier_pilots', ['carrier_id' => $otra->id]);
});

it('valida el código enviado al unirse a una empresa', function (array $payload) {
    asUser(userWithRole(UserRole::Pilot))
        ->postJson('/api/carriers/join', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
})->with([
    'sin código' => [[]],
    'código corto' => [['code' => 'A7K2']],
    'código largo' => [['code' => 'A7K2QXZ']],
]);

/*
|--------------------------------------------------------------------------
| Lecturas
|--------------------------------------------------------------------------
*/

it('devuelve la empresa del carrier autenticado con su código', function () {
    $carrier = Carrier::factory()->create(['name' => 'Transportes del Norte', 'code' => 'A7K2QX']);

    asUser($carrier->owner)->getJson('/api/carriers/me')
        ->assertOk()
        ->assertExactJson([
            'statusCode' => 200,
            'message' => 'Empresa transportista obtenida correctamente',
            'data' => [
                'id' => $carrier->id,
                'name' => 'Transportes del Norte',
                'image' => null,
                'code' => 'A7K2QX',
                'active' => true,
            ],
        ]);
});

it('devuelve solo los pilotos de la empresa del carrier autenticado', function () {
    $carrier = Carrier::factory()->create();
    $otra = Carrier::factory()->create();

    $propios = User::factory()->count(2)->create(['role' => UserRole::Pilot]);
    $ajeno = userWithRole(UserRole::Pilot);

    $carrier->pilots()->attach($propios->pluck('id'));
    $otra->pilots()->attach($ajeno->id);

    $response = asUser($carrier->owner)->getJson('/api/carriers/me/pilots');

    $response->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Pilotos obtenidos correctamente',
        ])
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => [['id', 'name', 'email', 'joinedAt']],
        ]);

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toEqualCanonicalizing($propios->pluck('id')->all())
        ->and($response->json('data.0.joinedAt'))->not->toBeNull();
});

it('devuelve la empresa pedida por id a un administrador', function () {
    $carrier = Carrier::factory()->create();

    asUser(userWithRole(UserRole::Administrator))
        ->getJson("/api/carriers/{$carrier->id}")
        ->assertOk()
        ->assertExactJson([
            'statusCode' => 200,
            'message' => 'Transportista obtenido correctamente',
            'data' => [
                'id' => $carrier->id,
                'name' => $carrier->name,
                'image' => null,
                'code' => $carrier->code,
                'active' => true,
            ],
        ]);
});

it('devuelve 404 al pedir una empresa que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->getJson('/api/carriers/99999')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El transportista no existe',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| Paginación
|--------------------------------------------------------------------------
*/

it('devuelve todos los transportistas y ninguna clave de paginación sin limit', function () {
    Carrier::factory()->count(12)->create();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/carriers');

    $response->assertOk()
        ->assertJsonCount(12, 'data');

    expect($response->json())->not->toHaveKeys(['total', 'currentPage', 'lastPage']);
});

it('devuelve el sobre paginado con limit numérico', function () {
    Carrier::factory()->count(12)->create();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/carriers?limit=10');

    $response->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJson([
            'statusCode' => 200,
            'total' => 12,
            'currentPage' => 1,
            'lastPage' => 2,
        ])
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => [['id', 'name', 'image', 'code', 'active']],
            'total',
            'currentPage',
            'lastPage',
        ]);
});

it('devuelve todos los transportistas sin error cuando limit no es numérico', function () {
    Carrier::factory()->count(12)->create();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/carriers?limit=abc');

    $response->assertOk()->assertJsonCount(12, 'data');

    expect($response->json())->not->toHaveKey('total');
});

it('acota el limit inferior de transportistas a 10 por página', function () {
    Carrier::factory()->count(12)->create();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/carriers?limit=3')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('lastPage', 2);
});

it('acota el limit superior de transportistas a 100 por página', function () {
    Carrier::factory()->count(101)->create();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/carriers?limit=500')
        ->assertOk()
        ->assertJsonCount(100, 'data')
        ->assertJsonPath('total', 101)
        ->assertJsonPath('lastPage', 2);
});

it('devuelve todos los pilotos y ninguna clave de paginación sin limit', function () {
    $carrier = Carrier::factory()->create();
    $carrier->pilots()->attach(User::factory()->count(12)->create(['role' => UserRole::Pilot])->pluck('id'));

    $response = asUser($carrier->owner)->getJson('/api/carriers/me/pilots');

    $response->assertOk()->assertJsonCount(12, 'data');

    expect($response->json())->not->toHaveKeys(['total', 'currentPage', 'lastPage']);
});

it('devuelve el sobre paginado de pilotos con limit numérico', function () {
    $carrier = Carrier::factory()->create();
    $carrier->pilots()->attach(User::factory()->count(12)->create(['role' => UserRole::Pilot])->pluck('id'));

    asUser($carrier->owner)->getJson('/api/carriers/me/pilots?limit=10')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJson([
            'total' => 12,
            'currentPage' => 1,
            'lastPage' => 2,
        ])
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => [['id', 'name', 'email', 'joinedAt']],
            'total',
            'currentPage',
            'lastPage',
        ]);
});

it('devuelve todos los pilotos sin error cuando limit no es numérico', function () {
    $carrier = Carrier::factory()->create();
    $carrier->pilots()->attach(User::factory()->count(12)->create(['role' => UserRole::Pilot])->pluck('id'));

    $response = asUser($carrier->owner)->getJson('/api/carriers/me/pilots?limit=abc');

    $response->assertOk()->assertJsonCount(12, 'data');

    expect($response->json())->not->toHaveKey('total');
});

it('acota el limit inferior de pilotos a 10 por página', function () {
    $carrier = Carrier::factory()->create();
    $carrier->pilots()->attach(User::factory()->count(12)->create(['role' => UserRole::Pilot])->pluck('id'));

    asUser($carrier->owner)->getJson('/api/carriers/me/pilots?limit=3')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('lastPage', 2);
});

it('acota el limit superior de pilotos a 100 por página', function () {
    $carrier = Carrier::factory()->create();
    $carrier->pilots()->attach(User::factory()->count(101)->create(['role' => UserRole::Pilot])->pluck('id'));

    asUser($carrier->owner)->getJson('/api/carriers/me/pilots?limit=500')
        ->assertOk()
        ->assertJsonCount(100, 'data')
        ->assertJsonPath('total', 101)
        ->assertJsonPath('lastPage', 2);
});

/*
|--------------------------------------------------------------------------
| PUT|PATCH /api/carriers/{carrier}
|--------------------------------------------------------------------------
*/

it('actualiza nombre, imagen y estado de la empresa propia', function () {
    $carrier = Carrier::factory()->create(['name' => 'Nombre antiguo', 'active' => true]);

    $response = asUser($carrier->owner)->patch("/api/carriers/{$carrier->id}", [
        'name' => 'Transportes del Sur',
        'image' => UploadedFile::fake()->image('nuevo.jpg'),
        'active' => false,
    ]);

    $response->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Transportista actualizado correctamente',
            'data' => [
                'id' => $carrier->id,
                'name' => 'Transportes del Sur',
                'active' => false,
            ],
        ]);

    expect($response->json('data.image'))->toEndWith('.jpg');

    $this->assertDatabaseHas('carriers', [
        'id' => $carrier->id,
        'name' => 'Transportes del Sur',
        'active' => false,
    ]);
});

it('rechaza con 403 a un carrier que actualiza una empresa ajena', function () {
    $propia = Carrier::factory()->create();
    $ajena = Carrier::factory()->create(['name' => 'Intacta']);

    asUser($propia->owner)->patchJson("/api/carriers/{$ajena->id}", ['name' => 'Secuestrada'])
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No puedes actualizar una empresa transportista que no te pertenece',
            'data' => null,
        ]);

    $this->assertDatabaseHas('carriers', ['id' => $ajena->id, 'name' => 'Intacta']);
});

it('permite a un administrador actualizar cualquier empresa', function () {
    $carrier = Carrier::factory()->create(['name' => 'Nombre antiguo']);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/carriers/{$carrier->id}", ['name' => 'Nombre corregido'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Nombre corregido');

    $this->assertDatabaseHas('carriers', ['id' => $carrier->id, 'name' => 'Nombre corregido']);
});

it('devuelve 404 al actualizar una empresa que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->patchJson('/api/carriers/99999', ['name' => 'Fantasma'])
        ->assertNotFound()
        ->assertJson(['message' => 'El transportista no existe']);
});

it('valida los campos enviados al actualizar la empresa', function (array $payload, string $field) {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)->patchJson("/api/carriers/{$carrier->id}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'nombre vacío' => [['name' => ''], 'name'],
    'estado no booleano' => [['active' => 'quizá'], 'active'],
    'imagen que no es archivo' => [['image' => 'logo.png'], 'image'],
]);

/*
|--------------------------------------------------------------------------
| DELETE /api/carriers/{carrier}
|--------------------------------------------------------------------------
*/

it('responde 200 al eliminar una empresa y no borra la fila', function () {
    $carrier = Carrier::factory()->create();

    asUser(userWithRole(UserRole::Administrator))
        ->deleteJson("/api/carriers/{$carrier->id}")
        ->assertOk()
        ->assertExactJson([
            'statusCode' => 200,
            'message' => 'Transportista eliminado correctamente',
            'data' => null,
        ]);

    $this->assertDatabaseHas('carriers', ['id' => $carrier->id]);
    $this->assertDatabaseCount('carriers', 1);
});

it('devuelve 404 al eliminar una empresa que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->deleteJson('/api/carriers/99999')
        ->assertNotFound()
        ->assertJson(['message' => 'El transportista no existe']);
});
