<?php

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\FinishedProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Every finished products endpoint as method and URI, for the middleware datasets.
 *
 * @return array<string, array{string, string}>
 */
function finishedProductEndpoints(): array
{
    return [
        'index' => ['GET', '/api/finished-products'],
        'store' => ['POST', '/api/finished-products'],
        'show' => ['GET', '/api/finished-products/1'],
        'update' => ['PATCH', '/api/finished-products/1'],
        'destroy' => ['DELETE', '/api/finished-products/1'],
    ];
}

/**
 * The three endpoints restricted to administrator and export.
 *
 * @return array<string, array{string, string}>
 */
function finishedProductWriteEndpoints(): array
{
    return [
        'store' => ['POST', '/api/finished-products'],
        'update' => ['PATCH', '/api/finished-products/1'],
        'destroy' => ['DELETE', '/api/finished-products/1'],
    ];
}

/**
 * The roles that may write the catalog.
 *
 * @return array<string, UserRole>
 */
function finishedProductWriterRoles(): array
{
    return [
        'administrator' => UserRole::Administrator,
        'export' => UserRole::Export,
    ];
}

/**
 * The roles that never write the catalog, pilot included.
 *
 * @return array<string, UserRole>
 */
function finishedProductNonWriterRoles(): array
{
    return [
        'manager' => UserRole::Manager,
        'carrier' => UserRole::Carrier,
        'user' => UserRole::User,
        'shipment' => UserRole::Shipment,
        'pilot' => UserRole::Pilot,
    ];
}

/**
 * Every role that reads the catalog: all but pilot.
 *
 * @return array<string, UserRole>
 */
function finishedProductReaderRoles(): array
{
    return finishedProductWriterRoles() + [
        'manager' => UserRole::Manager,
        'carrier' => UserRole::Carrier,
        'user' => UserRole::User,
        'shipment' => UserRole::Shipment,
    ];
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
     */
    function asUser(User $user): TestCase
    {
        resetAuthState();

        return test()->withToken(JWTAuth::fromUser($user))->withHeader('Accept', 'application/json');
    }
}

/**
 * The eleven keys FinishedProductResource promises, in declaration order.
 *
 * @return array<int, string>
 */
function finishedProductResourceKeys(): array
{
    return [
        'id', 'code', 'name', 'presentation', 'boxesPerPallet', 'clientId', 'clientName',
        'registeredByName', 'createdAt', 'updatedAt', 'deletedAt',
    ];
}

/**
 * A valid store payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function finishedProductPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'bro-iqf-10',
        'name' => 'Brócoli florete IQF',
        'presentation' => 10,
        'boxesPerPallet' => 96.5,
        'clientId' => fn () => Client::factory()->create()->id,
    ], $overrides);
}

/**
 * Resolve the lazy clientId of the payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function finishedProductBody(array $overrides = []): array
{
    return array_map(fn ($value) => $value instanceof Closure ? $value() : $value, finishedProductPayload($overrides));
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth y role
|--------------------------------------------------------------------------
*/

it('rechaza con 401 cualquier endpoint de productos terminados sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(finishedProductEndpoints());

it('rechaza con 403 al piloto también en la lectura', function (string $method, string $uri) {
    asUser(userWithRole(UserRole::Pilot))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with([
    'index' => ['GET', '/api/finished-products'],
    'show' => ['GET', '/api/finished-products/1'],
]);

it('rechaza con 403 la escritura a quien no es administrador ni export', function (string $method, string $uri, UserRole $role) {
    asUser(userWithRole($role))->json($method, $uri)
        ->assertForbidden()
        ->assertJsonPath('statusCode', 403);
})->with(finishedProductWriteEndpoints())->with(finishedProductNonWriterRoles());

it('no crea, modifica ni borra nada cuando un rol sin escritura lo intenta', function (UserRole $role) {
    $product = FinishedProduct::factory()->create(['code' => 'SKU-1', 'name' => 'ORIGINAL']);
    $user = userWithRole($role);

    asUser($user)->postJson('/api/finished-products', finishedProductBody())->assertForbidden();
    asUser($user)->patchJson("/api/finished-products/{$product->id}", ['name' => 'otro'])->assertForbidden();
    asUser($user)->deleteJson("/api/finished-products/{$product->id}")->assertForbidden();

    expect(FinishedProduct::withTrashed()->count())->toBe(1)
        ->and($product->fresh()->name)->toBe('ORIGINAL')
        ->and($product->fresh()->deleted_at)->toBeNull();
})->with(finishedProductNonWriterRoles());

it('deja leer el catálogo a todo rol salvo el piloto, incluidos user y shipment', function (UserRole $role) {
    $product = FinishedProduct::factory()->create();

    asUser(userWithRole($role))->getJson('/api/finished-products')
        ->assertOk()
        ->assertJsonPath('message', 'Productos terminados obtenidos correctamente')
        ->assertJsonCount(1, 'data');

    asUser(userWithRole($role))->getJson("/api/finished-products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Producto terminado obtenido correctamente')
        ->assertJsonPath('data.id', $product->id);
})->with(finishedProductReaderRoles());

it('deja crear, editar y borrar a administrator y export', function (UserRole $role) {
    $user = userWithRole($role);

    $id = asUser($user)->postJson('/api/finished-products', finishedProductBody())
        ->assertCreated()
        ->json('data.id');

    asUser($user)->patchJson("/api/finished-products/{$id}", ['name' => 'nuevo nombre'])
        ->assertOk()
        ->assertJsonPath('message', 'Producto terminado actualizado correctamente')
        ->assertJsonPath('data.name', 'NUEVO NOMBRE');

    asUser($user)->deleteJson("/api/finished-products/{$id}")
        ->assertOk()
        ->assertJsonPath('message', 'Producto terminado eliminado correctamente');

    $this->assertSoftDeleted('finished_products', ['id' => $id]);
})->with(finishedProductWriterRoles());

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('registra un producto terminado y devuelve 201 con las once claves', function () {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['name' => 'FRESH FOODS INC']);

    $response = asUser($admin)->postJson('/api/finished-products', finishedProductBody(['clientId' => $client->id]))
        ->assertCreated()
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Producto terminado registrado correctamente')
        ->assertJsonPath('data.code', 'BRO-IQF-10')
        ->assertJsonPath('data.name', 'BRÓCOLI FLORETE IQF')
        ->assertJsonPath('data.presentation', '10.00')
        ->assertJsonPath('data.boxesPerPallet', '96.50')
        ->assertJsonPath('data.clientId', $client->id)
        ->assertJsonPath('data.clientName', 'FRESH FOODS INC')
        ->assertJsonPath('data.registeredByName', $admin->name)
        ->assertJsonPath('data.deletedAt', null);

    expect(array_keys($response->json('data')))->toBe(finishedProductResourceKeys())
        ->and($response->json('data.createdAt'))->toMatch('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} (AM|PM)$/');

    $this->assertDatabaseHas('finished_products', [
        'id' => $response->json('data.id'),
        'code' => 'BRO-IQF-10',
        'client_id' => $client->id,
        'registered_by' => $admin->id,
        'deleted_at' => null,
    ]);
});

it('guarda el código trimado y en mayúsculas', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/finished-products', finishedProductBody(['code' => ' bro-iqf-10 ']))
        ->assertCreated()
        ->assertJsonPath('data.code', 'BRO-IQF-10');

    $this->assertDatabaseHas('finished_products', ['code' => 'BRO-IQF-10']);
});

it('guarda el nombre en mayúsculas, incluidas las tildes', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/finished-products', finishedProductBody(['name' => 'Brócoli florete']))
        ->assertCreated()
        ->assertJsonPath('data.name', 'BRÓCOLI FLORETE');
});

it('guarda el nombre sin colapsar los espacios interiores', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/finished-products', finishedProductBody(['name' => 'brócoli  florete']))
        ->assertCreated()
        ->assertJsonPath('data.name', 'BRÓCOLI  FLORETE');

    $this->assertDatabaseHas('finished_products', ['name' => 'BRÓCOLI  FLORETE']);
});

it('guarda el nombre sin recortar los espacios de los extremos', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/finished-products', finishedProductBody(['name' => ' brócoli  florete ']))
        ->assertCreated()
        ->assertJsonPath('data.name', ' BRÓCOLI  FLORETE ');

    $this->assertDatabaseHas('finished_products', ['name' => ' BRÓCOLI  FLORETE ']);
});

it('rechaza con 422 un nombre de solo espacios aunque la ruta no pase por TrimStrings', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/finished-products', finishedProductBody(['name' => '   ']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => 'El nombre del producto terminado es obligatorio']);
});

it('crea dos productos con el mismo nombre', function () {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/finished-products', finishedProductBody(['code' => 'SKU-1', 'name' => 'arveja china']))->assertCreated();
    asUser($admin)->postJson('/api/finished-products', finishedProductBody(['code' => 'SKU-2', 'name' => 'arveja china']))->assertCreated();

    expect(FinishedProduct::query()->where('name', 'ARVEJA CHINA')->count())->toBe(2);
});

it('rechaza con 422 un código con un espacio interior', function (string $code) {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/finished-products', finishedProductBody(['code' => $code]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code'])
        ->assertJsonFragment(['El código no puede contener espacios']);

    expect(FinishedProduct::withTrashed()->count())->toBe(0);
})->with([
    'un espacio' => 'BRO IQF',
    'un tabulador' => "BRO\tIQF",
]);

it('rechaza con 422 un código de más de quince caracteres', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/finished-products', finishedProductBody(['code' => str_repeat('A', 16)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

it('rechaza con 422 el alta sin alguno de los campos obligatorios', function (string $campo) {
    $body = finishedProductBody();

    unset($body[$campo]);

    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/finished-products', $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$campo]);
})->with(['code', 'name', 'presentation', 'boxesPerPallet', 'clientId']);

it('rechaza con 400 un código duplicado, sin importar la caja', function () {
    $admin = userWithRole(UserRole::Administrator);
    FinishedProduct::factory()->create(['code' => 'BRO-IQF-10']);

    asUser($admin)->postJson('/api/finished-products', finishedProductBody(['code' => 'bro-iqf-10']))
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'Ya existe un producto terminado con ese código, que puede haber sido eliminado',
            'data' => null,
        ]);

    expect(FinishedProduct::withTrashed()->count())->toBe(1);
});

it('rechaza con 400 un código que ocupa un producto borrado', function () {
    FinishedProduct::factory()->trashed()->create(['code' => 'BRO-IQF-10']);

    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/finished-products', finishedProductBody(['code' => 'BRO-IQF-10']))
        ->assertStatus(400)
        ->assertJsonPath('message', 'Ya existe un producto terminado con ese código, que puede haber sido eliminado');
});

it('rechaza con 422 una presentación o unas cajas por tarima inválidas', function (string $campo, mixed $valor) {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/finished-products', finishedProductBody([$campo => $valor]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$campo]);

    expect(FinishedProduct::withTrashed()->count())->toBe(0);
})->with(['presentation', 'boxesPerPallet'])->with([
    'cero' => 0,
    'negativo' => -5,
    'no numérico' => 'abc',
    'sobre el máximo' => 100000000,
]);

it('rechaza con 422 un cliente inexistente', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/finished-products', finishedProductBody(['clientId' => 99999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['clientId'])
        ->assertJsonFragment(['El cliente seleccionado no existe']);
});

it('rechaza con 400 un cliente borrado en el alta', function () {
    $client = Client::factory()->trashed()->create();

    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/finished-products', finishedProductBody(['clientId' => $client->id]))
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El cliente seleccionado ya fue eliminado',
            'data' => null,
        ]);

    expect(FinishedProduct::withTrashed()->count())->toBe(0);
});

it('ignora un registered_by mandado en el body', function () {
    $admin = userWithRole(UserRole::Administrator);
    $otro = userWithRole(UserRole::Administrator);

    $id = asUser($admin)->postJson('/api/finished-products', finishedProductBody(['registered_by' => $otro->id, 'registeredBy' => $otro->id]))
        ->assertCreated()
        ->json('data.id');

    expect(FinishedProduct::find($id)->registered_by)->toBe($admin->id);
});

/*
|--------------------------------------------------------------------------
| Detalle
|--------------------------------------------------------------------------
*/

it('devuelve el detalle con las once claves', function () {
    $product = FinishedProduct::factory()->create(['presentation' => 25, 'boxes_per_pallet' => 48]);

    $response = asUser(userWithRole(UserRole::Manager))->getJson("/api/finished-products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.code', $product->code)
        ->assertJsonPath('data.presentation', '25.00')
        ->assertJsonPath('data.boxesPerPallet', '48.00')
        ->assertJsonPath('data.clientName', $product->client->name)
        ->assertJsonPath('data.deletedAt', null);

    expect(array_keys($response->json('data')))->toBe(finishedProductResourceKeys());
});

it('responde 404 al detalle de un id inexistente o borrado', function (bool $borrado) {
    $id = $borrado ? FinishedProduct::factory()->trashed()->create()->id : 99999;

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/finished-products/{$id}")
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El producto terminado no existe',
            'data' => null,
        ]);
})->with(['inexistente' => false, 'borrado' => true]);

/*
|--------------------------------------------------------------------------
| Edición
|--------------------------------------------------------------------------
*/

it('cambia el cliente a otro cliente activo', function () {
    $product = FinishedProduct::factory()->create();
    $nuevo = Client::factory()->create(['name' => 'CLIENTE NUEVO']);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/finished-products/{$product->id}", ['clientId' => $nuevo->id])
        ->assertOk()
        ->assertJsonPath('data.clientId', $nuevo->id)
        ->assertJsonPath('data.clientName', 'CLIENTE NUEVO');

    $this->assertDatabaseHas('finished_products', ['id' => $product->id, 'client_id' => $nuevo->id]);
});

it('actualiza los campos enviados con la misma normalización del alta', function () {
    $product = FinishedProduct::factory()->create();

    asUser(userWithRole(UserRole::Export))
        ->patchJson("/api/finished-products/{$product->id}", [
            'code' => ' nuevo-01 ',
            'name' => 'ejote  francés',
            'presentation' => 20,
            'boxesPerPallet' => 120,
        ])
        ->assertOk()
        ->assertJsonPath('data.code', 'NUEVO-01')
        ->assertJsonPath('data.name', 'EJOTE  FRANCÉS')
        ->assertJsonPath('data.presentation', '20.00')
        ->assertJsonPath('data.boxesPerPallet', '120.00');
});

it('responde 200 sin cambios con un cuerpo vacío', function () {
    $product = FinishedProduct::factory()->create();
    $antes = $product->fresh()->only(['code', 'name', 'presentation', 'boxes_per_pallet', 'client_id', 'registered_by']);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/finished-products/{$product->id}", [])
        ->assertOk()
        ->assertJsonPath('data.id', $product->id);

    expect($product->fresh()->only(['code', 'name', 'presentation', 'boxes_per_pallet', 'client_id', 'registered_by']))->toBe($antes);
});

it('no reescribe registered_by al editar con otro usuario', function (UserRole $role) {
    $autor = userWithRole(UserRole::Administrator);
    $product = FinishedProduct::factory()->create(['registered_by' => $autor->id]);

    asUser(userWithRole($role))
        ->patchJson("/api/finished-products/{$product->id}", ['name' => 'otro nombre', 'registered_by' => 12345])
        ->assertOk()
        ->assertJsonPath('data.registeredByName', $autor->name);

    expect($product->fresh()->registered_by)->toBe($autor->id);
})->with(finishedProductWriterRoles());

it('permite reenviar el propio código en la edición', function () {
    $product = FinishedProduct::factory()->create(['code' => 'SKU-1']);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/finished-products/{$product->id}", ['code' => 'sku-1'])
        ->assertOk()
        ->assertJsonPath('data.code', 'SKU-1');
});

it('rechaza con 400 editar hacia un código de otro producto, borrado o no', function (bool $borrado) {
    $factory = FinishedProduct::factory();
    ($borrado ? $factory->trashed() : $factory)->create(['code' => 'SKU-1']);
    $product = FinishedProduct::factory()->create(['code' => 'SKU-2']);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/finished-products/{$product->id}", ['code' => 'SKU-1'])
        ->assertStatus(400)
        ->assertJsonPath('message', 'Ya existe un producto terminado con ese código, que puede haber sido eliminado');

    expect($product->fresh()->code)->toBe('SKU-2');
})->with(['activo' => false, 'borrado' => true]);

it('rechaza con 422 campos vacíos o inválidos en la edición', function (string $campo, mixed $valor) {
    $product = FinishedProduct::factory()->create();

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/finished-products/{$product->id}", [$campo => $valor])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$campo]);
})->with([
    'code vacío' => ['code', ''],
    'code con espacio' => ['code', 'SKU 1'],
    'name vacío' => ['name', ''],
    'presentation cero' => ['presentation', 0],
    'boxesPerPallet negativo' => ['boxesPerPallet', -1],
    'clientId inexistente' => ['clientId', 99999],
]);

it('rechaza con 400 un cliente borrado en la edición', function () {
    $product = FinishedProduct::factory()->create();
    $borrado = Client::factory()->trashed()->create();

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/finished-products/{$product->id}", ['clientId' => $borrado->id])
        ->assertStatus(400)
        ->assertJsonPath('message', 'El cliente seleccionado ya fue eliminado');

    expect($product->fresh()->client_id)->not->toBe($borrado->id);
});

it('responde 400 al editar o borrar un producto ya borrado', function (string $method) {
    $product = FinishedProduct::factory()->trashed()->create();

    asUser(userWithRole(UserRole::Administrator))
        ->json($method, "/api/finished-products/{$product->id}", $method === 'PATCH' ? ['name' => 'otro'] : [])
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El producto terminado ya fue eliminado',
            'data' => null,
        ]);
})->with(['PATCH', 'DELETE']);

it('responde 404 al editar o borrar un id inexistente', function (string $method) {
    asUser(userWithRole(UserRole::Administrator))
        ->json($method, '/api/finished-products/99999', $method === 'PATCH' ? ['name' => 'otro'] : [])
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El producto terminado no existe',
            'data' => null,
        ]);
})->with(['PATCH', 'DELETE']);

/*
|--------------------------------------------------------------------------
| Borrado
|--------------------------------------------------------------------------
*/

it('borra con 200, devuelve deletedAt con fecha y lo saca del listado y del detalle', function () {
    $admin = userWithRole(UserRole::Administrator);
    $product = FinishedProduct::factory()->create();
    $otro = FinishedProduct::factory()->create();

    $response = asUser($admin)->deleteJson("/api/finished-products/{$product->id}")->assertOk();

    expect($response->json('data.deletedAt'))->toMatch('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} (AM|PM)$/')
        ->and(array_keys($response->json('data')))->toBe(finishedProductResourceKeys());

    $this->assertSoftDeleted('finished_products', ['id' => $product->id]);

    expect(collect(asUser($admin)->getJson('/api/finished-products')->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$otro->id]);

    asUser($admin)->getJson("/api/finished-products/{$product->id}")->assertNotFound();
});

it('no bloquea el borrado de un cliente con productos terminados y estos siguen listándose', function () {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create(['name' => 'CLIENTE QUE SE VA']);
    $product = FinishedProduct::factory()->create(['client_id' => $client->id]);

    asUser($admin)->deleteJson("/api/clients/{$client->id}")->assertOk();

    $this->assertSoftDeleted('clients', ['id' => $client->id]);

    asUser($admin)->getJson('/api/finished-products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $product->id)
        ->assertJsonPath('data.0.clientId', $client->id)
        ->assertJsonPath('data.0.clientName', 'CLIENTE QUE SE VA');

    asUser($admin)->getJson("/api/finished-products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.clientName', 'CLIENTE QUE SE VA');
});

/*
|--------------------------------------------------------------------------
| Listado, filtros y paginación
|--------------------------------------------------------------------------
*/

it('busca por código y por nombre, sin distinguir mayúsculas', function (string $search) {
    $admin = userWithRole(UserRole::Administrator);
    $match = FinishedProduct::factory()->create(['code' => 'BRO-IQF-10', 'name' => 'BRÓCOLI FLORETE']);
    FinishedProduct::factory()->create(['code' => 'ARV-01', 'name' => 'ARVEJA CHINA']);

    expect(collect(asUser($admin)->getJson('/api/finished-products?search='.urlencode($search))->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$match->id]);
})->with([
    'por código' => 'iqf',
    'por nombre' => 'brócoli',
    'con espacios alrededor' => '  florete ',
]);

it('filtra por clientId e ignora uno no numérico', function () {
    $admin = userWithRole(UserRole::Administrator);
    $client = Client::factory()->create();
    $propios = FinishedProduct::factory()->count(2)->create(['client_id' => $client->id]);
    FinishedProduct::factory()->count(3)->create();

    expect(collect(asUser($admin)->getJson("/api/finished-products?clientId={$client->id}")->assertOk()->json('data'))->pluck('id')->all())
        ->toBe($propios->pluck('id')->all());

    asUser($admin)->getJson('/api/finished-products?clientId=abc')
        ->assertOk()
        ->assertJsonCount(5, 'data');
});

it('ordena el listado por id ascendente', function () {
    $ids = FinishedProduct::factory()->count(4)->create()->pluck('id')->sort()->values()->all();

    expect(collect(asUser(userWithRole(UserRole::Administrator))->getJson('/api/finished-products')->json('data'))->pluck('id')->all())
        ->toBe($ids);
});

it('devuelve la colección completa sin claves de paginación sin limit', function () {
    FinishedProduct::factory()->count(12)->create();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/finished-products')->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12);
});

it('pagina con los metadatos en la raíz y acota el tamaño a [10, 100]', function () {
    $admin = userWithRole(UserRole::Administrator);
    FinishedProduct::factory()->count(101)->create();
    FinishedProduct::factory()->trashed()->count(2)->create();

    $porDebajo = asUser($admin)->getJson('/api/finished-products?limit=5')->assertOk();
    $porEncima = asUser($admin)->getJson('/api/finished-products?limit=500')->assertOk();

    expect($porDebajo->json())->toHaveKeys(['statusCode', 'message', 'data', 'total', 'currentPage', 'lastPage'])
        ->and($porDebajo->json('meta'))->toBeNull()
        ->and($porDebajo->json('data'))->toHaveCount(10)
        ->and($porDebajo->json('total'))->toBe(101)
        ->and($porDebajo->json('currentPage'))->toBe(1)
        ->and($porDebajo->json('lastPage'))->toBe(11)
        ->and($porEncima->json('data'))->toHaveCount(100)
        ->and($porEncima->json('lastPage'))->toBe(2);
});

it('no dispara N+1: el número de consultas del listado no crece con N', function () {
    /** El token se emite fuera de la escucha: sus claims consultan la empresa del usuario. */
    $token = JWTAuth::fromUser(userWithRole(UserRole::Administrator));

    $contarConsultas = function () use ($token): int {
        resetAuthState();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->withToken($token)->getJson('/api/finished-products')->assertOk();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    };

    /** Cada producto con su propio cliente y su propio registrador. */
    FinishedProduct::factory()->count(2)->create();
    $conPocos = $contarConsultas();

    FinishedProduct::factory()->count(15)->create();
    $conMuchos = $contarConsultas();

    /** Listado + client + registeredBy, más la recarga del usuario de jwt.auth y del role:. */
    expect($conMuchos)->toBe($conPocos)
        ->and($conMuchos)->toBeLessThanOrEqual(6);
});
