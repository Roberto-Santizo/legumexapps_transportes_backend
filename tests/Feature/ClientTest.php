<?php

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Every clients endpoint as method and URI, for the middleware datasets.
 *
 * There are only five: this domain has no fixed route before the apiResource —
 * no /toggle-status and no /restore.
 *
 * @return array<string, array{string, string}>
 */
function clientEndpoints(): array
{
    return [
        'index' => ['GET', '/api/clients'],
        'store' => ['POST', '/api/clients'],
        'show' => ['GET', '/api/clients/1'],
        'update' => ['PATCH', '/api/clients/1'],
        'destroy' => ['DELETE', '/api/clients/1'],
    ];
}

/**
 * The three endpoints restricted to the administrator.
 *
 * @return array<string, array{string, string}>
 */
function clientWriteEndpoints(): array
{
    return [
        'store' => ['POST', '/api/clients'],
        'update' => ['PATCH', '/api/clients/1'],
        'destroy' => ['DELETE', '/api/clients/1'],
    ];
}

/**
 * The roles that may read the clients but never write them.
 *
 * @return array<string, UserRole>
 */
function clientNonAdminRoles(): array
{
    return [
        'carrier' => UserRole::Carrier,
        'pilot' => UserRole::Pilot,
        'manager' => UserRole::Manager,
    ];
}

/**
 * Every role of the project: all four read the clients.
 *
 * @return array<string, UserRole>
 */
function clientReaderRoles(): array
{
    return clientNonAdminRoles() + ['administrator' => UserRole::Administrator];
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

/**
 * The seven keys ClientResource promises, in the order the resource declares them.
 *
 * @return array<int, string>
 */
function clientResourceKeys(): array
{
    return ['id', 'code', 'name', 'registeredByName', 'createdAt', 'updatedAt', 'deletedAt'];
}

/**
 * A valid store payload, with the two mandatory fields already filled in.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function clientPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'cli-001',
        'name' => 'agroexportadora del sur',
    ], $overrides);
}

/**
 * La expresión del formato de fecha `d-m-Y h:i:s A` que promete el ClientResource.
 */
function clientDatePattern(): string
{
    return '/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} (AM|PM)$/';
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth y role
|--------------------------------------------------------------------------
*/

it('rechaza con 401 cualquier endpoint de clientes sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(clientEndpoints());

it('rechaza con 403 a quien no es administrador en los endpoints de escritura', function (string $method, string $uri, UserRole $role) {
    asUser(userWithRole($role))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(clientWriteEndpoints())->with(clientNonAdminRoles());

it('no crea, modifica ni borra nada cuando un no administrador intenta escribir', function (UserRole $role) {
    $client = Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGROEXPORTADORA DEL SUR']);
    $user = userWithRole($role);

    asUser($user)->postJson('/api/clients', clientPayload(['code' => 'cli-002', 'name' => 'otro cliente']))->assertForbidden();
    asUser($user)->patchJson("/api/clients/{$client->id}", ['name' => 'otro nombre'])->assertForbidden();
    asUser($user)->deleteJson("/api/clients/{$client->id}")->assertForbidden();

    expect(Client::query()->count())->toBe(1)
        ->and($client->fresh()->code)->toBe('CLI-001')
        ->and($client->fresh()->name)->toBe('AGROEXPORTADORA DEL SUR')
        ->and($client->fresh()->deleted_at)->toBeNull();
})->with(clientNonAdminRoles());

it('deja leer los clientes a cualquier rol, incluido uno sin empresa', function (UserRole $role) {
    $client = Client::factory()->create();
    $user = userWithRole($role);

    /** Ninguna ruta lleva carrier.required: el catálogo de clientes es nacional. */
    expect($user->currentCarrier())->toBeNull();

    asUser($user)->getJson('/api/clients')
        ->assertOk()
        ->assertJsonPath('message', 'Clientes obtenidos correctamente');

    asUser($user)->getJson("/api/clients/{$client->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Cliente obtenido correctamente');
})->with(clientReaderRoles());

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('registra un cliente con sus dos campos y devuelve 201', function () {
    $admin = userWithRole(UserRole::Administrator);

    $response = asUser($admin)->postJson('/api/clients', clientPayload())
        ->assertCreated()
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Cliente registrado correctamente')
        ->assertJsonPath('data.code', 'CLI-001')
        ->assertJsonPath('data.name', 'AGROEXPORTADORA DEL SUR')
        ->assertJsonPath('data.registeredByName', $admin->name)
        ->assertJsonPath('data.deletedAt', null);

    expect(array_keys($response->json('data')))->toBe(clientResourceKeys());

    $this->assertDatabaseHas('clients', [
        'id' => $response->json('data.id'),
        'code' => 'CLI-001',
        'name' => 'AGROEXPORTADORA DEL SUR',
        'registered_by' => $admin->id,
        'deleted_at' => null,
    ]);
});

it('guarda el código trimado y en mayúsculas', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/clients', clientPayload(['code' => ' cli-001 ']))
        ->assertCreated()
        ->assertJsonPath('data.code', 'CLI-001');

    $this->assertDatabaseHas('clients', ['code' => 'CLI-001']);
});

it('guarda el nombre en mayúsculas y con los espacios internos colapsados', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/clients', clientPayload(['name' => '  agro   del sur ']))
        ->assertCreated()
        ->assertJsonPath('data.name', 'AGRO DEL SUR');

    $this->assertDatabaseHas('clients', ['name' => 'AGRO DEL SUR']);
});

it('rechaza con 422 un código con cualquier espacio, en vez de colapsarlo', function (string $code) {
    $response = asUser(userWithRole(UserRole::Administrator))->postJson('/api/clients', clientPayload(['code' => $code]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code'])
        ->assertJsonFragment(['El código no puede contener espacios']);

    /** El 422 sale con el formato de Laravel, no con el sobre del ResponseHandler. */
    expect(array_keys($response->json()))->toBe(['message', 'errors'])
        ->and(Client::withTrashed()->count())->toBe(0);
})->with([
    'un espacio en medio' => 'CLI 001',
    'varios espacios en medio' => 'CLI   001',
    'un tabulador en medio' => "CLI\t001",
]);

it('rechaza con 422 un código de más de quince caracteres', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/clients', clientPayload(['code' => str_repeat('A', 16)]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code'])
        ->assertJsonFragment(['El código del cliente no puede superar los 15 caracteres']);
});

it('acepta un código de exactamente quince caracteres', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/clients', clientPayload(['code' => str_repeat('A', 15)]))
        ->assertCreated()
        ->assertJsonPath('data.code', str_repeat('A', 15));
});

it('rechaza con 422 un nombre de más de 255 caracteres', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/clients', clientPayload(['name' => str_repeat('a', 256)]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name'])
        ->assertJsonFragment(['El nombre del cliente no puede superar los 255 caracteres']);
});

it('rechaza con 422 el alta sin alguno de los dos campos obligatorios', function (string $campo) {
    $payload = clientPayload();

    unset($payload[$campo]);

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/clients', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo]);
})->with(['code', 'name']);

it('rechaza con 422 un campo obligatorio que solo trae espacios', function (string $campo) {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/clients', clientPayload([$campo => '   ']))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo]);
})->with(['code', 'name']);

it('rechaza con 400 desde el service, y nunca con 422, un código ya registrado', function () {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/clients', clientPayload())->assertCreated();

    /** Sin regla unique en el FormRequest: los duplicados salen siempre por el 400 del service. */
    asUser($admin)->postJson('/api/clients', clientPayload(['code' => 'CLI-001', 'name' => 'otro cliente']))
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'Ya existe un cliente con ese código, que puede haber sido eliminado',
            'data' => null,
        ]);

    expect(Client::withTrashed()->count())->toBe(1);
});

it('rechaza con 400 desde el service, y nunca con 422, un nombre ya registrado', function () {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/clients', clientPayload())->assertCreated();

    asUser($admin)->postJson('/api/clients', clientPayload(['code' => 'cli-002']))
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'Ya existe un cliente con ese nombre, que puede haber sido eliminado',
            'data' => null,
        ]);

    expect(Client::withTrashed()->count())->toBe(1);
});

it('caza el duplicado aunque llegue en otra caja o con espacios de sobra', function () {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/clients', clientPayload(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR']))->assertCreated();

    asUser($admin)->postJson('/api/clients', clientPayload(['code' => 'cli-001', 'name' => 'otro cliente']))
        ->assertStatus(400)
        ->assertJsonPath('message', 'Ya existe un cliente con ese código, que puede haber sido eliminado');

    asUser($admin)->postJson('/api/clients', clientPayload(['code' => 'cli-002', 'name' => '  agro   del   sur ']))
        ->assertStatus(400)
        ->assertJsonPath('message', 'Ya existe un cliente con ese nombre, que puede haber sido eliminado');
});

it('sigue reservando el código de un cliente borrado', function () {
    Client::factory()->trashed()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR']);

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/clients', clientPayload(['code' => 'cli-001', 'name' => 'cliente nuevo']))
        ->assertStatus(400)
        ->assertJsonPath('message', 'Ya existe un cliente con ese código, que puede haber sido eliminado');

    expect(Client::withTrashed()->count())->toBe(1);
});

it('sigue reservando el nombre de un cliente borrado', function () {
    Client::factory()->trashed()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR']);

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/clients', clientPayload(['code' => 'cli-999', 'name' => '  agro del sur ']))
        ->assertStatus(400)
        ->assertJsonPath('message', 'Ya existe un cliente con ese nombre, que puede haber sido eliminado');

    expect(Client::withTrashed()->count())->toBe(1);
});

it('registra al usuario autenticado aunque el body mande otro responsable', function () {
    $admin = userWithRole(UserRole::Administrator);
    $otro = userWithRole(UserRole::Administrator);

    $response = asUser($admin)->postJson('/api/clients', clientPayload([
        'registeredBy' => $otro->id,
        'registered_by' => $otro->id,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.registeredByName', $admin->name);

    $this->assertDatabaseHas('clients', [
        'id' => $response->json('data.id'),
        'registered_by' => $admin->id,
    ]);
});

/*
|--------------------------------------------------------------------------
| Detalle
|--------------------------------------------------------------------------
*/

it('devuelve el cliente por id con las siete claves y las fechas formateadas', function () {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create([
        'registered_by' => $admin->id,
        'created_at' => Carbon::parse('2026-08-26 20:45:12'),
        'updated_at' => Carbon::parse('2026-08-26 20:51:40'),
    ]);

    $detalle = asUser($admin)->getJson("/api/clients/{$client->id}")
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Cliente obtenido correctamente')
        ->assertJsonPath('data.createdAt', '26-08-2026 08:45:12 PM')
        ->assertJsonPath('data.updatedAt', '26-08-2026 08:51:40 PM')
        ->json('data');

    expect(array_keys($detalle))->toBe(clientResourceKeys())
        ->and($detalle['id'])->toBe($client->id)
        ->and($detalle['registeredByName'])->toBe($admin->name)
        ->and($detalle['deletedAt'])->toBeNull()
        ->and($detalle['createdAt'])->toMatch(clientDatePattern());
});

it('responde 404 con el mismo mensaje ante un id inexistente y ante uno borrado', function (bool $borrado) {
    $admin = userWithRole(UserRole::Administrator);

    $id = $borrado
        ? Client::factory()->trashed()->create(['registered_by' => $admin->id])->id
        : 9999;

    asUser($admin)->getJson("/api/clients/{$id}")
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El cliente no existe',
            'data' => null,
        ]);
})->with([
    'id inexistente' => false,
    'cliente borrado' => true,
]);

/*
|--------------------------------------------------------------------------
| Edición
|--------------------------------------------------------------------------
*/

it('edita el código y el nombre normalizándolos', function () {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR', 'registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/clients/{$client->id}", ['code' => ' cli-002 ', 'name' => '  agro   del norte '])
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Cliente actualizado correctamente')
        ->assertJsonPath('data.id', $client->id)
        ->assertJsonPath('data.code', 'CLI-002')
        ->assertJsonPath('data.name', 'AGRO DEL NORTE');

    $this->assertDatabaseHas('clients', [
        'id' => $client->id,
        'code' => 'CLI-002',
        'name' => 'AGRO DEL NORTE',
    ]);
});

it('no toca el otro campo cuando el PATCH solo manda uno', function () {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR', 'registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/clients/{$client->id}", ['name' => 'agro del norte'])
        ->assertOk()
        ->assertJsonPath('data.code', 'CLI-001')
        ->assertJsonPath('data.name', 'AGRO DEL NORTE');
});

it('acepta un PATCH con el cuerpo vacío como no-op', function () {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/clients/{$client->id}", [])
        ->assertOk()
        ->assertJsonPath('data.code', $client->code)
        ->assertJsonPath('data.name', $client->name);

    $this->assertDatabaseHas('clients', ['id' => $client->id, 'code' => $client->code, 'name' => $client->name]);
});

it('acepta que un cliente reenvíe su propio código y su propio nombre', function () {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR', 'registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/clients/{$client->id}", ['code' => 'cli-001', 'name' => '  agro   del sur '])
        ->assertOk()
        ->assertJsonPath('data.code', 'CLI-001')
        ->assertJsonPath('data.name', 'AGRO DEL SUR');
});

it('rechaza con 400 en la edición el código o el nombre de otro cliente', function (array $payload, string $mensaje) {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR', 'registered_by' => $admin->id]);
    Client::factory()->create(['code' => 'CLI-002', 'name' => 'AGRO DEL NORTE', 'registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/clients/{$client->id}", $payload)
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => $mensaje,
            'data' => null,
        ]);

    expect($client->fresh()->code)->toBe('CLI-001')
        ->and($client->fresh()->name)->toBe('AGRO DEL SUR');
})->with([
    'código de otro' => [['code' => 'cli-002'], 'Ya existe un cliente con ese código, que puede haber sido eliminado'],
    'nombre de otro' => [['name' => 'agro del norte'], 'Ya existe un cliente con ese nombre, que puede haber sido eliminado'],
]);

it('rechaza con 400 en la edición el código o el nombre de un cliente borrado', function (array $payload, string $mensaje) {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR', 'registered_by' => $admin->id]);
    Client::factory()->trashed()->create(['code' => 'CLI-002', 'name' => 'AGRO DEL NORTE', 'registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/clients/{$client->id}", $payload)
        ->assertStatus(400)
        ->assertJsonPath('message', $mensaje);
})->with([
    'código de un borrado' => [['code' => 'cli-002'], 'Ya existe un cliente con ese código, que puede haber sido eliminado'],
    'nombre de un borrado' => [['name' => 'agro del norte'], 'Ya existe un cliente con ese nombre, que puede haber sido eliminado'],
]);

it('rechaza con 422 en la edición un código con espacios o demasiado largo', function (array $payload) {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['code' => 'CLI-001', 'registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/clients/{$client->id}", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    expect($client->fresh()->code)->toBe('CLI-001');
})->with([
    'con espacios' => [['code' => 'CLI 002']],
    'de dieciséis caracteres' => [['code' => 'ABCDEFGHIJKLMNOP']],
    'vacío' => [['code' => '']],
]);

it('no reescribe al responsable del alta aunque el PATCH lo mande', function () {
    $admin = userWithRole(UserRole::Administrator);
    $otro = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['registered_by' => $admin->id]);

    asUser($otro)->patchJson("/api/clients/{$client->id}", [
        'name' => 'agro del norte',
        'registeredBy' => $otro->id,
        'registered_by' => $otro->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.registeredByName', $admin->name);

    $this->assertDatabaseHas('clients', ['id' => $client->id, 'registered_by' => $admin->id]);
});

it('responde 404 al editar un id inexistente', function () {
    asUser(userWithRole(UserRole::Administrator))->patchJson('/api/clients/9999', ['name' => 'agro del sur'])
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El cliente no existe',
            'data' => null,
        ]);
});

it('responde 400 al editar un cliente ya borrado', function () {
    $client = Client::factory()->trashed()->create();

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/clients/{$client->id}", ['name' => 'agro del norte'])
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El cliente ya fue eliminado',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| Borrado lógico
|--------------------------------------------------------------------------
*/

it('borra el cliente dejando la fila con deleted_at', function () {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->deleteJson("/api/clients/{$client->id}")
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Cliente eliminado correctamente')
        ->assertJsonPath('data.id', $client->id);

    $this->assertSoftDeleted('clients', ['id' => $client->id]);

    expect(Client::query()->find($client->id))->toBeNull()
        ->and(Client::withTrashed()->find($client->id))->not->toBeNull();
});

it('responde 400 en el segundo DELETE y 404 en un id inexistente', function () {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->deleteJson("/api/clients/{$client->id}")->assertOk();

    /** El segundo intento no es idempotente: distingue "ya no está" de "nunca existió". */
    asUser($admin)->deleteJson("/api/clients/{$client->id}")
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El cliente ya fue eliminado',
            'data' => null,
        ]);

    asUser($admin)->deleteJson('/api/clients/9999')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El cliente no existe',
            'data' => null,
        ]);
});

it('deja de listar y de mostrar el cliente borrado', function () {
    $admin = userWithRole(UserRole::Administrator);
    $borrado = Client::factory()->create(['registered_by' => $admin->id]);
    Client::factory()->count(2)->create(['registered_by' => $admin->id]);

    asUser($admin)->deleteJson("/api/clients/{$borrado->id}")->assertOk();

    $data = asUser($admin)->getJson('/api/clients')->assertOk()->json('data');

    expect($data)->toHaveCount(2)
        ->and(collect($data)->pluck('id')->all())->not->toContain($borrado->id);

    asUser($admin)->getJson("/api/clients/{$borrado->id}")->assertNotFound();
});

it('no libera el código ni el nombre del cliente borrado por la API', function () {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR', 'registered_by' => $admin->id]);

    asUser($admin)->deleteJson("/api/clients/{$client->id}")->assertOk();

    asUser($admin)->postJson('/api/clients', clientPayload(['code' => 'cli-001', 'name' => 'cliente nuevo']))
        ->assertStatus(400)
        ->assertJsonPath('message', 'Ya existe un cliente con ese código, que puede haber sido eliminado');

    asUser($admin)->postJson('/api/clients', clientPayload(['code' => 'cli-999', 'name' => 'agro del sur']))
        ->assertStatus(400)
        ->assertJsonPath('message', 'Ya existe un cliente con ese nombre, que puede haber sido eliminado');

    expect(Client::withTrashed()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Listado, búsqueda y paginación
|--------------------------------------------------------------------------
*/

it('devuelve la colección completa y ninguna clave de paginación sin limit', function () {
    $admin = userWithRole(UserRole::Administrator);
    Client::factory()->count(12)->create(['registered_by' => $admin->id]);

    $response = asUser($admin)->getJson('/api/clients')->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12)
        ->and($response->json('message'))->toBe('Clientes obtenidos correctamente');
});

it('devuelve 200 con el listado vacío cuando el catálogo está vacío', function () {
    expect(asUser(userWithRole(UserRole::Administrator))->getJson('/api/clients')->assertOk()->json('data'))->toBe([]);
});

it('devuelve los metadatos de paginación en la raíz del sobre', function () {
    $admin = userWithRole(UserRole::Administrator);
    Client::factory()->count(3)->create(['registered_by' => $admin->id]);

    $response = asUser($admin)->getJson('/api/clients?limit=10')->assertOk();

    expect($response->json())->toHaveKeys(['statusCode', 'message', 'data', 'total', 'currentPage', 'lastPage'])
        ->and($response->json('total'))->toBe(3)
        ->and($response->json('currentPage'))->toBe(1)
        ->and($response->json('lastPage'))->toBe(1)
        ->and($response->json('meta'))->toBeNull();
});

it('acota el tamaño de página a [10, 100] también por HTTP', function () {
    $admin = userWithRole(UserRole::Administrator);
    Client::factory()->count(101)->create(['registered_by' => $admin->id]);

    $porDebajo = asUser($admin)->getJson('/api/clients?limit=1')->assertOk();
    $porEncima = asUser($admin)->getJson('/api/clients?limit=500')->assertOk();

    expect($porDebajo->json('data'))->toHaveCount(10)
        ->and($porDebajo->json('lastPage'))->toBe(11)
        ->and($porEncima->json('data'))->toHaveCount(100)
        ->and($porEncima->json('lastPage'))->toBe(2)
        ->and($porEncima->json('total'))->toBe(101);
});

it('no pagina cuando el limit no es numérico', function () {
    $admin = userWithRole(UserRole::Administrator);
    Client::factory()->count(12)->create(['registered_by' => $admin->id]);

    $response = asUser($admin)->getJson('/api/clients?limit=abc')->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12);
});

it('no cuenta los borrados en el total de la paginación', function () {
    $admin = userWithRole(UserRole::Administrator);
    Client::factory()->count(3)->create(['registered_by' => $admin->id]);
    Client::factory()->trashed()->count(2)->create(['registered_by' => $admin->id]);

    expect(asUser($admin)->getJson('/api/clients?limit=10')->assertOk()->json('total'))->toBe(3);
});

it('busca indistintamente por código y por nombre, sin distinguir mayúsculas', function (string $search) {
    $admin = userWithRole(UserRole::Administrator);
    Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGROEXPORTADORA DEL SUR', 'registered_by' => $admin->id]);
    Client::factory()->create(['code' => 'PRO-002', 'name' => 'COMERCIALIZADORA LA CEIBA', 'registered_by' => $admin->id]);

    $data = asUser(userWithRole(UserRole::Pilot))->getJson('/api/clients?search='.urlencode($search))
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['code'])->toBe('CLI-001');
})->with([
    'código en minúsculas' => 'cli-001',
    'código en mayúsculas' => 'CLI-001',
    'trozo del código' => 'cli-',
    'nombre en minúsculas' => 'agroexportadora',
    'nombre capitalizado' => 'Del Sur',
    'nombre con espacios de sobra' => 'agroexportadora   del   sur',
]);

it('no devuelve los clientes borrados en una búsqueda que los alcanzaría', function () {
    $admin = userWithRole(UserRole::Administrator);
    Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR', 'registered_by' => $admin->id]);
    Client::factory()->trashed()->create(['code' => 'CLI-002', 'name' => 'AGRO DEL NORTE', 'registered_by' => $admin->id]);

    $data = asUser($admin)->getJson('/api/clients?search=agro')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['code'])->toBe('CLI-001');
});

it('devuelve el listado completo cuando el término de búsqueda viene vacío o en blanco', function (string $query) {
    $admin = userWithRole(UserRole::Administrator);
    Client::factory()->count(2)->create(['registered_by' => $admin->id]);

    expect(asUser($admin)->getJson('/api/clients?search='.$query)->assertOk()->json('data'))->toHaveCount(2);
})->with([
    'vacío' => '',
    'solo espacios' => '%20%20',
]);

it('devuelve el listado vacío, y nunca 422, cuando la búsqueda no encuentra nada', function () {
    $admin = userWithRole(UserRole::Administrator);
    Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR', 'registered_by' => $admin->id]);

    expect(asUser($admin)->getJson('/api/clients?search=inexistente')->assertOk()->json('data'))->toBe([]);
});

it('devuelve los clientes ordenados por id ascendente', function () {
    $admin = userWithRole(UserRole::Administrator);
    $clients = Client::factory()->count(5)->create(['registered_by' => $admin->id]);

    $ids = $clients->pluck('id')->sort()->values()->all();

    expect(collect(asUser($admin)->getJson('/api/clients')->assertOk()->json('data'))->pluck('id')->all())->toBe($ids);
});

it('no dispara N+1 al listar clientes de muchos registradores', function () {
    Client::factory()->count(20)->create();

    /** El token se emite antes de escuchar: sus claims consultan la empresa del usuario. */
    $token = JWTAuth::fromUser(userWithRole(UserRole::Administrator));

    resetAuthState();

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withToken($token)->getJson('/api/clients')->assertOk()->assertJsonCount(20, 'data');

    $sobreClientes = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "clients"'));
    $sobreUsuarios = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "users"'));

    /** Una consulta por el listado y otra por la relación: la del usuario autenticado es aparte. */
    expect($sobreClientes)->toHaveCount(1)
        ->and($sobreUsuarios->count())->toBeLessThanOrEqual(2);
});

/*
|--------------------------------------------------------------------------
| Forma de la respuesta
|--------------------------------------------------------------------------
*/

it('devuelve las siete claves en camelCase en el listado y en el detalle', function () {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['registered_by' => $admin->id]);

    $delListado = asUser($admin)->getJson('/api/clients')->assertOk()->json('data.0');
    $detalle = asUser($admin)->getJson("/api/clients/{$client->id}")->assertOk()->json('data');

    expect(array_keys($delListado))->toBe(clientResourceKeys())
        ->and(array_keys($detalle))->toBe(clientResourceKeys())
        ->and($delListado['updatedAt'])->toMatch(clientDatePattern());
});

it('devuelve deletedAt en null en las cuatro respuestas que entregan un cliente vivo', function () {
    $admin = userWithRole(UserRole::Administrator);

    $id = asUser($admin)->postJson('/api/clients', clientPayload())->assertCreated()->assertJsonPath('data.deletedAt', null)->json('data.id');

    expect(asUser($admin)->getJson('/api/clients')->assertOk()->json('data.0.deletedAt'))->toBeNull()
        ->and(asUser($admin)->getJson("/api/clients/{$id}")->assertOk()->json('data.deletedAt'))->toBeNull()
        ->and(asUser($admin)->patchJson("/api/clients/{$id}", ['name' => 'otro nombre'])->assertOk()->json('data.deletedAt'))->toBeNull();
});

it('devuelve el cliente ya con su deletedAt en la respuesta del DELETE', function () {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['registered_by' => $admin->id]);

    $data = asUser($admin)->deleteJson("/api/clients/{$client->id}")->assertOk()->json('data');

    /**
     * El controller responde con la misma instancia que acaba de borrarse, así que su
     * deleted_at ya está puesto. Es la única respuesta de la API con deletedAt no nulo,
     * tal como lo describe el docblock de ClientResource.
     */
    expect($data['deletedAt'])->toMatch(clientDatePattern())
        ->and(array_keys($data))->toBe(clientResourceKeys());
});
