<?php

use App\Enums\UserRole;
use App\Models\ShippingLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Every shipping lines endpoint as method and URI, for the middleware datasets.
 *
 * There are only five: this domain has no fixed route before the apiResource —
 * no /toggle-status and no /restore.
 *
 * @return array<string, array{string, string}>
 */
function shippingLineEndpoints(): array
{
    return [
        'index' => ['GET', '/api/shipping-lines'],
        'store' => ['POST', '/api/shipping-lines'],
        'show' => ['GET', '/api/shipping-lines/1'],
        'update' => ['PATCH', '/api/shipping-lines/1'],
        'destroy' => ['DELETE', '/api/shipping-lines/1'],
    ];
}

/**
 * The three endpoints restricted to the administrator.
 *
 * @return array<string, array{string, string}>
 */
function shippingLineWriteEndpoints(): array
{
    return [
        'store' => ['POST', '/api/shipping-lines'],
        'update' => ['PATCH', '/api/shipping-lines/1'],
        'destroy' => ['DELETE', '/api/shipping-lines/1'],
    ];
}

/**
 * The roles that may read the shipping lines but never write them.
 *
 * @return array<string, UserRole>
 */
function shippingLineNonAdminRoles(): array
{
    return [
        'carrier' => UserRole::Carrier,
        'pilot' => UserRole::Pilot,
        'manager' => UserRole::Manager,
    ];
}

/**
 * Every role of the project: all four read the shipping lines.
 *
 * @return array<string, UserRole>
 */
function shippingLineReaderRoles(): array
{
    return shippingLineNonAdminRoles() + ['administrator' => UserRole::Administrator];
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
 * The six keys ShippingLineResource promises, in the order the resource declares them.
 *
 * One less than ClientResource: this domain owns a single business field and has
 * no `code` at all.
 *
 * @return array<int, string>
 */
function shippingLineResourceKeys(): array
{
    return ['id', 'name', 'registeredByName', 'createdAt', 'updatedAt', 'deletedAt'];
}

/**
 * A valid store payload: one single mandatory field.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shippingLinePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'maersk line',
    ], $overrides);
}

/**
 * La expresión del formato de fecha `d-m-Y h:i:s A` que promete el ShippingLineResource.
 */
function shippingLineDatePattern(): string
{
    return '/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} (AM|PM)$/';
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth y role
|--------------------------------------------------------------------------
*/

it('rechaza con 401 cualquier endpoint de navieras sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(shippingLineEndpoints());

it('rechaza con 403 a quien no es administrador en los endpoints de escritura de navieras', function (string $method, string $uri, UserRole $role) {
    asUser(userWithRole($role))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(shippingLineWriteEndpoints())->with(shippingLineNonAdminRoles());

it('no crea, modifica ni borra ninguna naviera cuando un no administrador intenta escribir', function (UserRole $role) {
    $shippingLine = ShippingLine::factory()->create(['name' => 'MAERSK LINE']);
    $user = userWithRole($role);

    asUser($user)->postJson('/api/shipping-lines', shippingLinePayload(['name' => 'otra naviera']))->assertForbidden();
    asUser($user)->patchJson("/api/shipping-lines/{$shippingLine->id}", ['name' => 'otro nombre'])->assertForbidden();
    asUser($user)->deleteJson("/api/shipping-lines/{$shippingLine->id}")->assertForbidden();

    expect(ShippingLine::query()->count())->toBe(1)
        ->and($shippingLine->fresh()->name)->toBe('MAERSK LINE')
        ->and($shippingLine->fresh()->deleted_at)->toBeNull();
})->with(shippingLineNonAdminRoles());

it('deja leer las navieras a cualquier rol, incluido uno sin empresa', function (UserRole $role) {
    $shippingLine = ShippingLine::factory()->create();
    $user = userWithRole($role);

    /** Ninguna ruta lleva carrier.required: el catálogo de navieras es nacional. */
    expect($user->currentCarrier())->toBeNull();

    asUser($user)->getJson('/api/shipping-lines')
        ->assertOk()
        ->assertJsonPath('message', 'Navieras obtenidas correctamente');

    asUser($user)->getJson("/api/shipping-lines/{$shippingLine->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Naviera obtenida correctamente');
})->with(shippingLineReaderRoles());

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('registra una naviera con su único campo y devuelve 201', function () {
    $admin = userWithRole(UserRole::Administrator);

    $response = asUser($admin)->postJson('/api/shipping-lines', shippingLinePayload())
        ->assertCreated()
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Naviera registrada correctamente')
        ->assertJsonPath('data.name', 'MAERSK LINE')
        ->assertJsonPath('data.registeredByName', $admin->name)
        ->assertJsonPath('data.deletedAt', null);

    expect(array_keys($response->json('data')))->toBe(shippingLineResourceKeys());

    $this->assertDatabaseHas('shipping_lines', [
        'id' => $response->json('data.id'),
        'name' => 'MAERSK LINE',
        'registered_by' => $admin->id,
        'deleted_at' => null,
    ]);
});

it('guarda el nombre en mayúsculas y con los espacios internos colapsados', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/shipping-lines', shippingLinePayload(['name' => '  maersk   line ']))
        ->assertCreated()
        ->assertJsonPath('data.name', 'MAERSK LINE');

    $this->assertDatabaseHas('shipping_lines', ['name' => 'MAERSK LINE']);
});

it('rechaza con 422 el alta sin el nombre', function () {
    $response = asUser(userWithRole(UserRole::Administrator))->postJson('/api/shipping-lines', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name'])
        ->assertJsonFragment(['El nombre de la naviera es obligatorio']);

    /** El 422 sale con el formato de Laravel, no con el sobre del ResponseHandler. */
    expect(array_keys($response->json()))->toBe(['message', 'errors'])
        ->and(ShippingLine::withTrashed()->count())->toBe(0);
});

it('rechaza con 422 un nombre que solo trae espacios, porque normalizarlo lo deja vacío', function (string $name) {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/shipping-lines', shippingLinePayload(['name' => $name]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name'])
        ->assertJsonFragment(['El nombre de la naviera es obligatorio']);

    expect(ShippingLine::withTrashed()->count())->toBe(0);
})->with([
    'espacios' => '   ',
    'tabulador y salto de línea' => "\t\n ",
    'cadena vacía' => '',
]);

it('rechaza con 422 un nombre que no es texto', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/shipping-lines', shippingLinePayload(['name' => ['maersk']]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name'])
        ->assertJsonFragment(['El nombre de la naviera debe ser texto']);
});

it('rechaza con 422 un nombre de más de 255 caracteres', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/shipping-lines', shippingLinePayload(['name' => str_repeat('a', 256)]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name'])
        ->assertJsonFragment(['El nombre de la naviera no puede superar los 255 caracteres']);

    expect(ShippingLine::withTrashed()->count())->toBe(0);
});

it('acepta un nombre de exactamente 255 caracteres', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/shipping-lines', shippingLinePayload(['name' => str_repeat('a', 255)]))
        ->assertCreated()
        ->assertJsonPath('data.name', str_repeat('A', 255));
});

it('rechaza con 400 desde el service, y nunca con 422, un nombre ya registrado', function () {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/shipping-lines', shippingLinePayload())->assertCreated();

    /** Sin regla unique en el FormRequest: los duplicados salen siempre por el 400 del service. */
    asUser($admin)->postJson('/api/shipping-lines', shippingLinePayload(['name' => 'MAERSK LINE']))
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'Ya existe una naviera con ese nombre, que puede haber sido eliminada',
            'data' => null,
        ]);

    expect(ShippingLine::withTrashed()->count())->toBe(1);
});

it('caza el duplicado aunque llegue en otra caja o con espacios de sobra', function (string $name) {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/shipping-lines', shippingLinePayload(['name' => 'MAERSK LINE']))->assertCreated();

    asUser($admin)->postJson('/api/shipping-lines', shippingLinePayload(['name' => $name]))
        ->assertStatus(400)
        ->assertJsonPath('message', 'Ya existe una naviera con ese nombre, que puede haber sido eliminada');

    expect(ShippingLine::withTrashed()->count())->toBe(1);
})->with([
    'en minúsculas' => 'maersk line',
    'capitalizado' => 'Maersk Line',
    'con espacios de sobra' => '  maersk   line ',
]);

it('sigue reservando el nombre de una naviera borrada', function () {
    ShippingLine::factory()->trashed()->create(['name' => 'MAERSK LINE']);

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/shipping-lines', shippingLinePayload(['name' => '  maersk line ']))
        ->assertStatus(400)
        ->assertJsonPath('message', 'Ya existe una naviera con ese nombre, que puede haber sido eliminada');

    expect(ShippingLine::withTrashed()->count())->toBe(1);
});

it('registra al usuario autenticado aunque el body mande otro responsable', function () {
    $admin = userWithRole(UserRole::Administrator);
    $otro = userWithRole(UserRole::Administrator);

    $response = asUser($admin)->postJson('/api/shipping-lines', shippingLinePayload([
        'registeredBy' => $otro->id,
        'registered_by' => $otro->id,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.registeredByName', $admin->name);

    $this->assertDatabaseHas('shipping_lines', [
        'id' => $response->json('data.id'),
        'registered_by' => $admin->id,
    ]);
});

/*
|--------------------------------------------------------------------------
| Detalle
|--------------------------------------------------------------------------
*/

it('devuelve la naviera por id con las seis claves y las fechas formateadas', function () {
    $admin = userWithRole(UserRole::Administrator);
    $shippingLine = ShippingLine::factory()->create([
        'registered_by' => $admin->id,
        'created_at' => Carbon::parse('2026-08-26 20:45:12'),
        'updated_at' => Carbon::parse('2026-08-26 20:51:40'),
    ]);

    $detalle = asUser($admin)->getJson("/api/shipping-lines/{$shippingLine->id}")
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Naviera obtenida correctamente')
        ->assertJsonPath('data.createdAt', '26-08-2026 08:45:12 PM')
        ->assertJsonPath('data.updatedAt', '26-08-2026 08:51:40 PM')
        ->json('data');

    expect(array_keys($detalle))->toBe(shippingLineResourceKeys())
        ->and($detalle['id'])->toBe($shippingLine->id)
        ->and($detalle['name'])->toBe($shippingLine->name)
        ->and($detalle['registeredByName'])->toBe($admin->name)
        ->and($detalle['deletedAt'])->toBeNull()
        ->and($detalle['createdAt'])->toMatch(shippingLineDatePattern());
});

it('responde 404 con el mismo mensaje ante un id inexistente y ante una naviera borrada', function (bool $borrada) {
    $admin = userWithRole(UserRole::Administrator);

    $id = $borrada
        ? ShippingLine::factory()->trashed()->create(['registered_by' => $admin->id])->id
        : 9999;

    asUser($admin)->getJson("/api/shipping-lines/{$id}")
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'La naviera no existe',
            'data' => null,
        ]);
})->with([
    'id inexistente' => false,
    'naviera borrada' => true,
]);

/*
|--------------------------------------------------------------------------
| Edición
|--------------------------------------------------------------------------
*/

it('edita el nombre de la naviera normalizándolo', function () {
    $admin = userWithRole(UserRole::Administrator);
    $shippingLine = ShippingLine::factory()->create(['name' => 'MAERSK LINE', 'registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/shipping-lines/{$shippingLine->id}", ['name' => '  hapag   lloyd '])
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Naviera actualizada correctamente')
        ->assertJsonPath('data.id', $shippingLine->id)
        ->assertJsonPath('data.name', 'HAPAG LLOYD');

    $this->assertDatabaseHas('shipping_lines', [
        'id' => $shippingLine->id,
        'name' => 'HAPAG LLOYD',
    ]);
});

it('acepta un PATCH con el cuerpo vacío como no-op', function () {
    $admin = userWithRole(UserRole::Administrator);
    $shippingLine = ShippingLine::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/shipping-lines/{$shippingLine->id}", [])
        ->assertOk()
        ->assertJsonPath('message', 'Naviera actualizada correctamente')
        ->assertJsonPath('data.name', $shippingLine->name);

    $this->assertDatabaseHas('shipping_lines', ['id' => $shippingLine->id, 'name' => $shippingLine->name]);
});

it('acepta que una naviera reenvíe su propio nombre sin chocar consigo misma', function () {
    $admin = userWithRole(UserRole::Administrator);
    $shippingLine = ShippingLine::factory()->create(['name' => 'MAERSK LINE', 'registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/shipping-lines/{$shippingLine->id}", ['name' => '  maersk   line '])
        ->assertOk()
        ->assertJsonPath('data.name', 'MAERSK LINE');
});

it('rechaza con 400 en la edición el nombre de otra naviera, esté viva o borrada', function (string $estado) {
    $admin = userWithRole(UserRole::Administrator);
    $shippingLine = ShippingLine::factory()->create(['name' => 'MAERSK LINE', 'registered_by' => $admin->id]);

    $estado === 'viva'
        ? ShippingLine::factory()->create(['name' => 'HAPAG LLOYD', 'registered_by' => $admin->id])
        : ShippingLine::factory()->trashed()->create(['name' => 'HAPAG LLOYD', 'registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/shipping-lines/{$shippingLine->id}", ['name' => 'hapag lloyd'])
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'Ya existe una naviera con ese nombre, que puede haber sido eliminada',
            'data' => null,
        ]);

    expect($shippingLine->fresh()->name)->toBe('MAERSK LINE');
})->with(['viva', 'borrada']);

it('rechaza con 422 en la edición un nombre vacío, demasiado largo o que no es texto', function (array $payload, string $mensaje) {
    $admin = userWithRole(UserRole::Administrator);
    $shippingLine = ShippingLine::factory()->create(['name' => 'MAERSK LINE', 'registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/shipping-lines/{$shippingLine->id}", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name'])
        ->assertJsonFragment([$mensaje]);

    expect($shippingLine->fresh()->name)->toBe('MAERSK LINE');
})->with([
    'vacío' => [['name' => ''], 'El nombre de la naviera es obligatorio'],
    'solo espacios' => [['name' => '   '], 'El nombre de la naviera es obligatorio'],
    'de 256 caracteres' => [['name' => str_repeat('a', 256)], 'El nombre de la naviera no puede superar los 255 caracteres'],
    'que no es texto' => [['name' => ['maersk']], 'El nombre de la naviera debe ser texto'],
]);

it('no reescribe al responsable del alta aunque el PATCH lo mande', function () {
    $admin = userWithRole(UserRole::Administrator);
    $otro = userWithRole(UserRole::Administrator);
    $shippingLine = ShippingLine::factory()->create(['registered_by' => $admin->id]);

    asUser($otro)->patchJson("/api/shipping-lines/{$shippingLine->id}", [
        'name' => 'hapag lloyd',
        'registeredBy' => $otro->id,
        'registered_by' => $otro->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.registeredByName', $admin->name);

    $this->assertDatabaseHas('shipping_lines', ['id' => $shippingLine->id, 'registered_by' => $admin->id]);
});

it('responde 404 al editar un id inexistente', function () {
    asUser(userWithRole(UserRole::Administrator))->patchJson('/api/shipping-lines/9999', ['name' => 'maersk line'])
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'La naviera no existe',
            'data' => null,
        ]);
});

it('responde 400 al editar una naviera ya borrada', function () {
    $shippingLine = ShippingLine::factory()->trashed()->create();

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/shipping-lines/{$shippingLine->id}", ['name' => 'hapag lloyd'])
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'La naviera ya fue eliminada',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| Borrado lógico
|--------------------------------------------------------------------------
*/

it('borra la naviera dejando la fila con deleted_at', function () {
    $admin = userWithRole(UserRole::Administrator);
    $shippingLine = ShippingLine::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->deleteJson("/api/shipping-lines/{$shippingLine->id}")
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Naviera eliminada correctamente')
        ->assertJsonPath('data.id', $shippingLine->id);

    $this->assertSoftDeleted('shipping_lines', ['id' => $shippingLine->id]);

    /** La fila sigue en la tabla: la API no la devuelve y tampoco sabe restaurarla. */
    expect(ShippingLine::query()->find($shippingLine->id))->toBeNull()
        ->and(ShippingLine::withTrashed()->find($shippingLine->id))->not->toBeNull();
});

it('responde 400 en el segundo DELETE y 404 en un id inexistente', function () {
    $admin = userWithRole(UserRole::Administrator);
    $shippingLine = ShippingLine::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->deleteJson("/api/shipping-lines/{$shippingLine->id}")->assertOk();

    /** El segundo intento no es idempotente: distingue "ya no está" de "nunca existió". */
    asUser($admin)->deleteJson("/api/shipping-lines/{$shippingLine->id}")
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'La naviera ya fue eliminada',
            'data' => null,
        ]);

    asUser($admin)->deleteJson('/api/shipping-lines/9999')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'La naviera no existe',
            'data' => null,
        ]);
});

it('deja de listar y de mostrar la naviera borrada', function () {
    $admin = userWithRole(UserRole::Administrator);
    $borrada = ShippingLine::factory()->create(['registered_by' => $admin->id]);
    ShippingLine::factory()->count(2)->create(['registered_by' => $admin->id]);

    asUser($admin)->deleteJson("/api/shipping-lines/{$borrada->id}")->assertOk();

    $data = asUser($admin)->getJson('/api/shipping-lines')->assertOk()->json('data');

    expect($data)->toHaveCount(2)
        ->and(collect($data)->pluck('id')->all())->not->toContain($borrada->id);

    asUser($admin)->getJson("/api/shipping-lines/{$borrada->id}")->assertNotFound();
});

it('no libera el nombre de la naviera borrada por la API', function () {
    $admin = userWithRole(UserRole::Administrator);
    $shippingLine = ShippingLine::factory()->create(['name' => 'MAERSK LINE', 'registered_by' => $admin->id]);

    asUser($admin)->deleteJson("/api/shipping-lines/{$shippingLine->id}")->assertOk();

    asUser($admin)->postJson('/api/shipping-lines', shippingLinePayload(['name' => 'maersk line']))
        ->assertStatus(400)
        ->assertJsonPath('message', 'Ya existe una naviera con ese nombre, que puede haber sido eliminada');

    expect(ShippingLine::withTrashed()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Listado, búsqueda y paginación
|--------------------------------------------------------------------------
*/

it('devuelve la colección completa y ninguna clave de paginación sin limit', function () {
    $admin = userWithRole(UserRole::Administrator);
    ShippingLine::factory()->count(12)->create(['registered_by' => $admin->id]);

    $response = asUser($admin)->getJson('/api/shipping-lines')->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12)
        ->and($response->json('message'))->toBe('Navieras obtenidas correctamente');
});

it('devuelve 200 con el listado vacío cuando el catálogo está vacío', function () {
    expect(asUser(userWithRole(UserRole::Administrator))->getJson('/api/shipping-lines')->assertOk()->json('data'))->toBe([]);
});

it('devuelve los metadatos de paginación en la raíz del sobre', function () {
    $admin = userWithRole(UserRole::Administrator);
    ShippingLine::factory()->count(12)->create(['registered_by' => $admin->id]);

    $response = asUser($admin)->getJson('/api/shipping-lines?limit=5')->assertOk();

    expect($response->json())->toHaveKeys(['statusCode', 'message', 'data', 'total', 'currentPage', 'lastPage'])
        ->and($response->json('total'))->toBe(12)
        ->and($response->json('currentPage'))->toBe(1)
        /** limit=5 sube al mínimo de 10, así que doce navieras ocupan dos páginas. */
        ->and($response->json('lastPage'))->toBe(2)
        ->and($response->json('data'))->toHaveCount(10)
        ->and($response->json('meta'))->toBeNull();
});

it('acota el tamaño de página a [10, 100] también por HTTP', function () {
    $admin = userWithRole(UserRole::Administrator);
    ShippingLine::factory()->count(101)->create(['registered_by' => $admin->id]);

    $porDebajo = asUser($admin)->getJson('/api/shipping-lines?limit=1')->assertOk();
    $porEncima = asUser($admin)->getJson('/api/shipping-lines?limit=500')->assertOk();

    expect($porDebajo->json('data'))->toHaveCount(10)
        ->and($porDebajo->json('lastPage'))->toBe(11)
        ->and($porEncima->json('data'))->toHaveCount(100)
        ->and($porEncima->json('lastPage'))->toBe(2)
        ->and($porEncima->json('total'))->toBe(101);
});

it('no pagina cuando el limit no es numérico', function () {
    $admin = userWithRole(UserRole::Administrator);
    ShippingLine::factory()->count(12)->create(['registered_by' => $admin->id]);

    $response = asUser($admin)->getJson('/api/shipping-lines?limit=abc')->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12);
});

it('no cuenta las navieras borradas en el total de la paginación', function () {
    $admin = userWithRole(UserRole::Administrator);
    ShippingLine::factory()->count(3)->create(['registered_by' => $admin->id]);
    ShippingLine::factory()->trashed()->count(2)->create(['registered_by' => $admin->id]);

    expect(asUser($admin)->getJson('/api/shipping-lines?limit=10')->assertOk()->json('total'))->toBe(3);
});

it('busca por nombre sin distinguir mayúsculas ni espacios de sobra', function (string $search) {
    $admin = userWithRole(UserRole::Administrator);
    /** Nombres deterministas: los de la factory son aleatorios y podrían contener el término. */
    ShippingLine::factory()->create(['name' => 'MAERSK LINE', 'registered_by' => $admin->id]);
    ShippingLine::factory()->create(['name' => 'HAPAG LLOYD', 'registered_by' => $admin->id]);

    $data = asUser(userWithRole(UserRole::Pilot))->getJson('/api/shipping-lines?search='.urlencode($search))
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('MAERSK LINE');
})->with([
    'trozo en minúsculas' => 'maer',
    'trozo en mayúsculas' => 'MAER',
    'nombre completo en minúsculas' => 'maersk line',
    'nombre capitalizado' => 'Maersk Line',
    'con espacios de sobra' => '  maersk   line ',
    'trozo del final' => 'sk lin',
]);

it('no devuelve las navieras borradas en una búsqueda que las alcanzaría', function () {
    $admin = userWithRole(UserRole::Administrator);
    ShippingLine::factory()->create(['name' => 'MAERSK LINE', 'registered_by' => $admin->id]);
    ShippingLine::factory()->trashed()->create(['name' => 'MAERSK SEALAND', 'registered_by' => $admin->id]);

    $data = asUser($admin)->getJson('/api/shipping-lines?search=maersk')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('MAERSK LINE');
});

it('devuelve el catálogo completo cuando el término de búsqueda viene vacío o en blanco', function (string $query) {
    $admin = userWithRole(UserRole::Administrator);
    ShippingLine::factory()->count(2)->create(['registered_by' => $admin->id]);

    expect(asUser($admin)->getJson('/api/shipping-lines?search='.$query)->assertOk()->json('data'))->toHaveCount(2);
})->with([
    'vacío' => '',
    'solo espacios' => '%20%20',
]);

it('devuelve el listado vacío, y nunca 422, cuando la búsqueda no encuentra nada', function () {
    $admin = userWithRole(UserRole::Administrator);
    ShippingLine::factory()->create(['name' => 'MAERSK LINE', 'registered_by' => $admin->id]);

    expect(asUser($admin)->getJson('/api/shipping-lines?search=inexistente')->assertOk()->json('data'))->toBe([]);
});

it('devuelve las navieras ordenadas por id ascendente', function () {
    $admin = userWithRole(UserRole::Administrator);
    $shippingLines = ShippingLine::factory()->count(5)->create(['registered_by' => $admin->id]);

    $ids = $shippingLines->pluck('id')->sort()->values()->all();

    expect(collect(asUser($admin)->getJson('/api/shipping-lines')->assertOk()->json('data'))->pluck('id')->all())->toBe($ids);
});

it('no dispara N+1 al listar navieras de muchos registradores', function () {
    ShippingLine::factory()->count(20)->create();

    /** El token se emite antes de escuchar: sus claims consultan la empresa del usuario. */
    $token = JWTAuth::fromUser(userWithRole(UserRole::Administrator));

    resetAuthState();

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withToken($token)->getJson('/api/shipping-lines')->assertOk()->assertJsonCount(20, 'data');

    $sobreNavieras = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "shipping_lines"'));
    $sobreUsuarios = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "users"'));

    /** Una consulta por el listado y otra por la relación: la del usuario autenticado es aparte. */
    expect($sobreNavieras)->toHaveCount(1)
        ->and($sobreUsuarios->count())->toBeLessThanOrEqual(2);
});

/*
|--------------------------------------------------------------------------
| Forma de la respuesta
|--------------------------------------------------------------------------
*/

it('devuelve las seis claves en camelCase en el listado y en el detalle', function () {
    $admin = userWithRole(UserRole::Administrator);
    $shippingLine = ShippingLine::factory()->create(['registered_by' => $admin->id]);

    $delListado = asUser($admin)->getJson('/api/shipping-lines')->assertOk()->json('data.0');
    $detalle = asUser($admin)->getJson("/api/shipping-lines/{$shippingLine->id}")->assertOk()->json('data');

    expect(array_keys($delListado))->toBe(shippingLineResourceKeys())
        ->and(array_keys($detalle))->toBe(shippingLineResourceKeys())
        ->and($delListado['registeredByName'])->toBe($admin->name)
        ->and($delListado['createdAt'])->toMatch(shippingLineDatePattern())
        ->and($delListado['updatedAt'])->toMatch(shippingLineDatePattern());
});

it('devuelve deletedAt en null en las cuatro respuestas que entregan una naviera viva', function () {
    $admin = userWithRole(UserRole::Administrator);

    $id = asUser($admin)->postJson('/api/shipping-lines', shippingLinePayload())->assertCreated()->assertJsonPath('data.deletedAt', null)->json('data.id');

    expect(asUser($admin)->getJson('/api/shipping-lines')->assertOk()->json('data.0.deletedAt'))->toBeNull()
        ->and(asUser($admin)->getJson("/api/shipping-lines/{$id}")->assertOk()->json('data.deletedAt'))->toBeNull()
        ->and(asUser($admin)->patchJson("/api/shipping-lines/{$id}", ['name' => 'hapag lloyd'])->assertOk()->json('data.deletedAt'))->toBeNull();
});

it('devuelve la naviera ya con su deletedAt en la respuesta del DELETE', function () {
    $admin = userWithRole(UserRole::Administrator);
    $shippingLine = ShippingLine::factory()->create(['registered_by' => $admin->id]);

    $data = asUser($admin)->deleteJson("/api/shipping-lines/{$shippingLine->id}")->assertOk()->json('data');

    /**
     * El controller responde con la misma instancia que acaba de borrarse, así que su
     * deleted_at ya está puesto. Es la única respuesta de la API con deletedAt no nulo,
     * tal como lo describe el docblock de ShippingLineResource.
     */
    expect($data['deletedAt'])->toMatch(shippingLineDatePattern())
        ->and(array_keys($data))->toBe(shippingLineResourceKeys());
});
