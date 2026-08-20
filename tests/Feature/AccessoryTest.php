<?php

use App\Enums\AccessoryStatus;
use App\Enums\UserRole;
use App\Models\Accessory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Every accessories endpoint as method and URI, for the middleware datasets.
 *
 * Son cinco y ninguna más: con tres estados un /toggle-status no significa nada,
 * así que el cambio de estado vive en el PATCH.
 *
 * @return array<string, array{string, string}>
 */
function accessoryEndpoints(): array
{
    return [
        'index' => ['GET', '/api/accessories'],
        'store' => ['POST', '/api/accessories'],
        'show' => ['GET', '/api/accessories/1'],
        'update' => ['PATCH', '/api/accessories/1'],
        'destroy' => ['DELETE', '/api/accessories/1'],
    ];
}

/**
 * The three endpoints restricted to the administrator.
 *
 * @return array<string, array{string, string}>
 */
function accessoryWriteEndpoints(): array
{
    return [
        'store' => ['POST', '/api/accessories'],
        'update' => ['PATCH', '/api/accessories/1'],
        'destroy' => ['DELETE', '/api/accessories/1'],
    ];
}

/**
 * The roles that may read the inventory but never write it.
 *
 * @return array<string, UserRole>
 */
function accessoryNonAdminRoles(): array
{
    return [
        'carrier' => UserRole::Carrier,
        'pilot' => UserRole::Pilot,
        'manager' => UserRole::Manager,
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
 * The eleven keys AccessoryResource promises, in the order the resource declares them.
 *
 * @return array<int, string>
 */
function accessoryResourceKeys(): array
{
    return ['id', 'name', 'code', 'description', 'price', 'purchaseDate', 'annualDepreciation', 'currentValue', 'status', 'registeredBy', 'createdAt'];
}

/**
 * A valid store payload, with the five mandatory fields already filled in.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function accessoryPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'gato hidraulico 20 ton',
        'code' => 'acc-0001',
        'price' => 1500.5,
        'purchaseDate' => '2024-01-15',
        'annualDepreciation' => 10,
    ], $overrides);
}

/**
 * La expresión del formato de fecha `d-m-Y h:i:s A` que promete el AccessoryResource.
 */
function accessoryDateTimePattern(): string
{
    return '/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} (AM|PM)$/';
}

/**
 * La expresión del dinero de dos decimales que promete el AccessoryResource.
 */
function accessoryMoneyPattern(): string
{
    return '/^\d+\.\d{2}$/';
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth y role
|--------------------------------------------------------------------------
*/

it('rechaza con 401 cualquier endpoint de accesorios sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(accessoryEndpoints());

it('rechaza con 403 a quien no es administrador en los endpoints de escritura', function (string $method, string $uri, UserRole $role) {
    asUser(userWithRole($role))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(accessoryWriteEndpoints())->with(accessoryNonAdminRoles());

it('no crea, modifica ni da de baja nada cuando un no administrador intenta escribir', function (UserRole $role) {
    $accessory = Accessory::factory()->active()->create(['name' => 'GATO HIDRAULICO 20 TON', 'code' => 'ACC-0001']);
    $user = userWithRole($role);

    asUser($user)->postJson('/api/accessories', accessoryPayload(['name' => 'lona de carga', 'code' => 'acc-0002']))->assertForbidden();
    asUser($user)->patchJson("/api/accessories/{$accessory->id}", ['name' => 'otro nombre'])->assertForbidden();
    asUser($user)->deleteJson("/api/accessories/{$accessory->id}")->assertForbidden();

    expect(Accessory::query()->count())->toBe(1)
        ->and($accessory->fresh()->name)->toBe('GATO HIDRAULICO 20 TON')
        ->and($accessory->fresh()->status)->toBe(AccessoryStatus::Active);
})->with(accessoryNonAdminRoles());

it('deja leer el inventario a cualquier rol, incluido un usuario sin empresa', function (UserRole $role) {
    $accessory = Accessory::factory()->create();
    $user = userWithRole($role);

    /** Ninguna ruta lleva carrier.required: el inventario es nacional. */
    expect($user->currentCarrier())->toBeNull();

    asUser($user)->getJson('/api/accessories')->assertOk();
    asUser($user)->getJson("/api/accessories/{$accessory->id}")->assertOk();
})->with(accessoryNonAdminRoles());

it('expone cinco rutas de accesorios y ninguna de toggle-status', function () {
    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => str_starts_with($uri, 'api/accessories'))
        ->values();

    expect($uris)->toHaveCount(5)
        ->and($uris->filter(fn (string $uri) => str_contains($uri, 'toggle-status')))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('registra un accesorio con los cinco campos obligatorios y lo hace nacer activo', function () {
    $admin = userWithRole(UserRole::Administrator);

    $response = asUser($admin)->postJson('/api/accessories', accessoryPayload())
        ->assertCreated()
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Accesorio registrado correctamente')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.registeredBy', $admin->name);

    expect(array_keys($response->json('data')))->toBe(accessoryResourceKeys());

    $this->assertDatabaseHas('accessories', [
        'id' => $response->json('data.id'),
        'name' => 'GATO HIDRAULICO 20 TON',
        'code' => 'ACC-0001',
        'price' => '1500.50',
        'status' => 'active',
        'registered_by' => $admin->id,
    ]);
});

it('deja la descripción en null cuando el alta no la manda', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/accessories', accessoryPayload())
        ->assertCreated()
        ->assertJsonPath('data.description', null);
});

it('guarda la descripción cuando el alta la manda', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/accessories', accessoryPayload(['description' => 'Gato de la bodega central']))
        ->assertCreated()
        ->assertJsonPath('data.description', 'Gato de la bodega central');
});

it('guarda el nombre y el código en mayúsculas', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/accessories', accessoryPayload(['name' => '  gato   hidraulico ', 'code' => ' acc-0001 ']))
        ->assertCreated()
        ->assertJsonPath('data.name', 'GATO HIDRAULICO')
        ->assertJsonPath('data.code', 'ACC-0001');

    $this->assertDatabaseHas('accessories', ['name' => 'GATO HIDRAULICO', 'code' => 'ACC-0001']);
});

it('ignora el status que llegue en el alta: el accesorio nace activo', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/accessories', accessoryPayload(['status' => 'under_repair']))
        ->assertCreated()
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('accessories', ['code' => 'ACC-0001', 'status' => 'active']);
});

it('registra al usuario autenticado aunque el body mande otro responsable', function () {
    $admin = userWithRole(UserRole::Administrator);
    $otro = userWithRole(UserRole::Administrator);

    $response = asUser($admin)->postJson('/api/accessories', accessoryPayload([
        'registeredBy' => $otro->id,
        'registered_by' => $otro->id,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.registeredBy', $admin->name);

    $this->assertDatabaseHas('accessories', [
        'id' => $response->json('data.id'),
        'registered_by' => $admin->id,
    ]);
});

it('rechaza con 422 el alta sin alguno de los campos obligatorios, con el mensaje en español', function (string $campo, string $mensaje) {
    $payload = accessoryPayload();

    unset($payload[$campo]);

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/accessories', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo])
        ->assertJsonFragment([$mensaje]);
})->with([
    'name' => ['name', 'El nombre del accesorio es obligatorio'],
    'code' => ['code', 'El código del accesorio es obligatorio'],
    'price' => ['price', 'El precio es obligatorio'],
    'purchaseDate' => ['purchaseDate', 'La fecha de compra es obligatoria'],
    'annualDepreciation' => ['annualDepreciation', 'El porcentaje de depreciación anual es obligatorio'],
]);

it('rechaza con 422 un precio de cero y acepta un céntimo', function () {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/accessories', accessoryPayload(['price' => 0]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['price'])
        ->assertJsonFragment(['El precio debe ser mayor a 0']);

    asUser($admin)->postJson('/api/accessories', accessoryPayload(['price' => 0.01]))
        ->assertCreated()
        ->assertJsonPath('data.price', '0.01');
});

it('rechaza con 422 una fecha de compra futura y acepta la de hoy', function () {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/accessories', accessoryPayload([
        'purchaseDate' => Carbon::tomorrow()->format('Y-m-d'),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['purchaseDate'])
        ->assertJsonFragment(['La fecha de compra no puede ser futura']);

    asUser($admin)->postJson('/api/accessories', accessoryPayload([
        'purchaseDate' => Carbon::today()->format('Y-m-d'),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.purchaseDate', Carbon::today()->format('d-m-Y'));
});

it('acepta los extremos de la depreciación anual y rechaza lo que se sale del rango', function (mixed $valor, bool $aceptada) {
    $response = asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/accessories', accessoryPayload(['annualDepreciation' => $valor]));

    $aceptada
        ? $response->assertCreated()
        : $response->assertStatus(422)->assertJsonValidationErrors(['annualDepreciation']);
})->with([
    'cero' => [0, true],
    'cien' => [100, true],
    'cien coma cero uno' => [100.01, false],
    'negativa' => [-1, false],
]);

it('rechaza el nombre repetido en otra caja sin llegar al índice único', function () {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/accessories', accessoryPayload())->assertCreated();

    /**
     * El nombre se normaliza en prepareForValidation, así que la regla unique lo caza como
     * 422 antes de que el service llegue a lanzar su 400. Lo que se defiende aquí es que
     * el duplicado nunca es el 500 del índice único.
     */
    $response = asUser($admin)->postJson('/api/accessories', accessoryPayload([
        'name' => 'Gato   Hidraulico 20 Ton',
        'code' => 'acc-0002',
    ]));

    expect($response->status())->toBeIn([400, 422]);

    $response->assertJsonFragment(['Ya existe un accesorio con ese nombre']);

    expect(Accessory::query()->count())->toBe(1);
});

it('rechaza el código repetido en otra caja sin llegar al índice único', function () {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/accessories', accessoryPayload())->assertCreated();

    $response = asUser($admin)->postJson('/api/accessories', accessoryPayload([
        'name' => 'lona de carga 8x12',
        'code' => 'ACC-0001',
    ]));

    expect($response->status())->toBeIn([400, 422]);

    $response->assertJsonFragment(['Ya existe un accesorio con ese código']);

    expect(Accessory::query()->count())->toBe(1);
});

it('no libera el código de un accesorio dado de baja', function () {
    $admin = userWithRole(UserRole::Administrator);

    $id = asUser($admin)->postJson('/api/accessories', accessoryPayload())->assertCreated()->json('data.id');

    asUser($admin)->deleteJson("/api/accessories/{$id}")->assertOk()->assertJsonPath('data.status', 'inactive');

    /** A diferencia de la placa de un vehículo, un inactive conserva su código para siempre. */
    $response = asUser($admin)->postJson('/api/accessories', accessoryPayload([
        'name' => 'lona de carga 8x12',
        'code' => 'acc-0001',
    ]));

    expect($response->status())->toBeIn([400, 422]);

    $response->assertJsonFragment(['Ya existe un accesorio con ese código']);

    expect(Accessory::query()->count())->toBe(1);
});

it('trata como distintos dos códigos que solo difieren en los espacios internos', function () {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/accessories', accessoryPayload(['name' => 'gato a', 'code' => 'A 100']))->assertCreated();
    asUser($admin)->postJson('/api/accessories', accessoryPayload(['name' => 'gato b', 'code' => 'A100']))->assertCreated();

    expect(Accessory::query()->pluck('code')->all())->toBe(['A 100', 'A100']);
});

/*
|--------------------------------------------------------------------------
| Detalle
|--------------------------------------------------------------------------
*/

it('devuelve las once claves en camelCase con las fechas y el dinero de la spec', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create([
        'registered_by' => $admin->id,
        'purchase_date' => '2024-01-15',
        'created_at' => Carbon::parse('2026-08-19 20:45:12'),
    ]);

    $detalle = asUser($admin)->getJson("/api/accessories/{$accessory->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Accesorio obtenido correctamente')
        ->assertJsonPath('data.purchaseDate', '15-01-2024')
        ->assertJsonPath('data.createdAt', '19-08-2026 08:45:12 PM')
        ->assertJsonPath('data.registeredBy', $admin->name)
        ->json('data');

    expect(array_keys($detalle))->toBe(accessoryResourceKeys())
        ->and($detalle['createdAt'])->toMatch(accessoryDateTimePattern())
        ->and($detalle['price'])->toBeString()->toMatch(accessoryMoneyPattern())
        ->and($detalle['currentValue'])->toBeString()->toMatch(accessoryMoneyPattern());
});

it('devuelve el currentValue como texto de dos decimales en el listado y en el detalle', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create([
        'registered_by' => $admin->id,
        'price' => 1000,
        'annual_depreciation' => 10,
        'purchase_date' => Carbon::today()->subYears(2)->format('Y-m-d'),
    ]);

    $delListado = asUser($admin)->getJson('/api/accessories')->assertOk()->json('data.0.currentValue');
    $delDetalle = asUser($admin)->getJson("/api/accessories/{$accessory->id}")->assertOk()->json('data.currentValue');

    expect($delListado)->toBeString()->toMatch(accessoryMoneyPattern())
        ->and($delDetalle)->toBe($delListado);
});

it('responde 404 en las tres acciones que resuelven el accesorio por id', function (string $method, string $uri) {
    asUser(userWithRole(UserRole::Administrator))->json($method, $uri)
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El accesorio no existe',
            'data' => null,
        ]);
})->with([
    'show' => ['GET', '/api/accessories/9999'],
    'update' => ['PATCH', '/api/accessories/9999'],
    'destroy' => ['DELETE', '/api/accessories/9999'],
]);

/*
|--------------------------------------------------------------------------
| Edición
|--------------------------------------------------------------------------
*/

it('cambia solo el campo que manda el PATCH', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create([
        'registered_by' => $admin->id,
        'name' => 'GATO HIDRAULICO 20 TON',
        'code' => 'ACC-0001',
        'description' => 'la de siempre',
        'price' => 1500,
        'purchase_date' => '2024-01-15',
        'annual_depreciation' => 10,
    ]);

    asUser($admin)->patchJson("/api/accessories/{$accessory->id}", ['name' => '  gato   nuevo '])
        ->assertOk()
        ->assertJsonPath('message', 'Accesorio actualizado correctamente')
        ->assertJsonPath('data.name', 'GATO NUEVO')
        ->assertJsonPath('data.code', 'ACC-0001')
        ->assertJsonPath('data.description', 'la de siempre')
        ->assertJsonPath('data.price', '1500.00')
        ->assertJsonPath('data.purchaseDate', '15-01-2024')
        ->assertJsonPath('data.annualDepreciation', '10.00');
});

it('mueve el estado libremente entre los tres valores', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->inactive()->create(['registered_by' => $admin->id]);

    /** Sin reglas de transición: hasta inactive → active se acepta. */
    asUser($admin)->patchJson("/api/accessories/{$accessory->id}", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    asUser($admin)->patchJson("/api/accessories/{$accessory->id}", ['status' => 'under_repair'])
        ->assertOk()
        ->assertJsonPath('data.status', 'under_repair');

    asUser($admin)->patchJson("/api/accessories/{$accessory->id}", ['status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $this->assertDatabaseHas('accessories', ['id' => $accessory->id, 'status' => 'inactive']);
});

it('rechaza con 422 un estado fuera del enum', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/accessories/{$accessory->id}", ['status' => 'perdido'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status'])
        ->assertJsonFragment(['El estado debe ser active, inactive o under_repair']);
});

it('recalcula el currentValue de la misma respuesta al cambiar la depreciación', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create([
        'registered_by' => $admin->id,
        'price' => 1000,
        'annual_depreciation' => 0,
        'purchase_date' => Carbon::today()->subYears(2)->format('Y-m-d'),
    ]);

    /** Sin depreciación el accesorio vale su precio. */
    asUser($admin)->getJson("/api/accessories/{$accessory->id}")
        ->assertOk()
        ->assertJsonPath('data.currentValue', '1000.00');

    $conDepreciacion = asUser($admin)->patchJson("/api/accessories/{$accessory->id}", ['annualDepreciation' => 20])
        ->assertOk()
        ->assertJsonPath('data.annualDepreciation', '20.00')
        ->json('data.currentValue');

    expect($conDepreciacion)->toBeString()->toMatch(accessoryMoneyPattern())
        ->and((float) $conDepreciacion)->toBeLessThan(1000.0);
});

it('rechaza el nombre y el código de otro accesorio y acepta los propios', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id, 'name' => 'GATO HIDRAULICO', 'code' => 'ACC-0001']);
    Accessory::factory()->create(['registered_by' => $admin->id, 'name' => 'LONA DE CARGA', 'code' => 'ACC-0002']);

    asUser($admin)->patchJson("/api/accessories/{$accessory->id}", ['name' => 'gato hidraulico', 'code' => 'acc-0001'])
        ->assertOk()
        ->assertJsonPath('data.name', 'GATO HIDRAULICO')
        ->assertJsonPath('data.code', 'ACC-0001');

    $porNombre = asUser($admin)->patchJson("/api/accessories/{$accessory->id}", ['name' => 'lona de carga']);
    $porCodigo = asUser($admin)->patchJson("/api/accessories/{$accessory->id}", ['code' => 'acc-0002']);

    expect($porNombre->status())->toBeIn([400, 422])
        ->and($porCodigo->status())->toBeIn([400, 422])
        ->and($accessory->fresh()->name)->toBe('GATO HIDRAULICO')
        ->and($accessory->fresh()->code)->toBe('ACC-0001');
});

it('borra la descripción cuando el PATCH manda null y la deja intacta cuando la omite', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id, 'description' => 'la de siempre']);

    asUser($admin)->patchJson("/api/accessories/{$accessory->id}", ['price' => 999])
        ->assertOk()
        ->assertJsonPath('data.description', 'la de siempre');

    asUser($admin)->patchJson("/api/accessories/{$accessory->id}", ['description' => null])
        ->assertOk()
        ->assertJsonPath('data.description', null);

    $this->assertDatabaseHas('accessories', ['id' => $accessory->id, 'description' => null]);
});

it('no reescribe al responsable del alta cuando edita otro administrador', function () {
    $original = userWithRole(UserRole::Administrator);
    $otro = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $original->id]);

    asUser($otro)->patchJson("/api/accessories/{$accessory->id}", ['price' => 250])
        ->assertOk()
        ->assertJsonPath('data.registeredBy', $original->name);

    $this->assertDatabaseHas('accessories', ['id' => $accessory->id, 'registered_by' => $original->id]);
});

it('acepta un PATCH con el cuerpo vacío como no-op', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->patchJson("/api/accessories/{$accessory->id}", [])
        ->assertOk()
        ->assertJsonPath('data.name', $accessory->name)
        ->assertJsonPath('data.code', $accessory->code)
        ->assertJsonPath('data.status', $accessory->status->value);
});

/*
|--------------------------------------------------------------------------
| Baja lógica
|--------------------------------------------------------------------------
*/

it('da de baja de forma idempotente sin borrar la fila', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->active()->create(['registered_by' => $admin->id]);

    asUser($admin)->deleteJson("/api/accessories/{$accessory->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Accesorio dado de baja correctamente')
        ->assertJsonPath('data.status', 'inactive');

    asUser($admin)->deleteJson("/api/accessories/{$accessory->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $this->assertDatabaseHas('accessories', ['id' => $accessory->id, 'status' => 'inactive']);

    expect(Accessory::query()->count())->toBe(1);
});

it('sigue listando los accesorios dados de baja cuando no hay filtro de estado', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->active()->create(['registered_by' => $admin->id]);

    asUser($admin)->deleteJson("/api/accessories/{$accessory->id}")->assertOk();

    expect(asUser($admin)->getJson('/api/accessories')->assertOk()->json('data'))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Listado, filtros y paginación
|--------------------------------------------------------------------------
*/

it('devuelve la colección completa y ninguna clave de paginación sin limit', function () {
    $admin = userWithRole(UserRole::Administrator);
    Accessory::factory()->count(12)->create(['registered_by' => $admin->id]);

    $response = asUser($admin)->getJson('/api/accessories')->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12)
        ->and($response->json('message'))->toBe('Accesorios obtenidos correctamente');
});

it('devuelve los metadatos de paginación en la raíz del sobre', function () {
    $admin = userWithRole(UserRole::Administrator);
    Accessory::factory()->count(3)->create(['registered_by' => $admin->id]);

    $response = asUser($admin)->getJson('/api/accessories?limit=10')->assertOk();

    expect($response->json())->toHaveKeys(['statusCode', 'message', 'data', 'total', 'currentPage', 'lastPage'])
        ->and($response->json('total'))->toBe(3)
        ->and($response->json('currentPage'))->toBe(1)
        ->and($response->json('lastPage'))->toBe(1)
        ->and($response->json('meta'))->toBeNull();
});

it('acota el tamaño de página a [10, 100] también por HTTP', function () {
    $admin = userWithRole(UserRole::Administrator);

    /** Los 101 accesorios comparten registrador: lo que se mide aquí es el tamaño de página. */
    Accessory::factory()->count(101)->create(['registered_by' => $admin->id]);

    $porDebajo = asUser($admin)->getJson('/api/accessories?limit=5')->assertOk();
    $porEncima = asUser($admin)->getJson('/api/accessories?limit=500')->assertOk();

    expect($porDebajo->json('data'))->toHaveCount(10)
        ->and($porDebajo->json('lastPage'))->toBe(11)
        ->and($porEncima->json('data'))->toHaveCount(100)
        ->and($porEncima->json('lastPage'))->toBe(2)
        ->and($porEncima->json('total'))->toBe(101);
});

it('no pagina cuando el limit no es numérico', function () {
    $admin = userWithRole(UserRole::Administrator);
    Accessory::factory()->count(12)->create(['registered_by' => $admin->id]);

    $response = asUser($admin)->getJson('/api/accessories?limit=abc')->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12);
});

it('filtra por estado e ignora un valor que no está en el enum', function () {
    $admin = userWithRole(UserRole::Administrator);
    Accessory::factory()->active()->create(['registered_by' => $admin->id]);
    Accessory::factory()->inactive()->create(['registered_by' => $admin->id]);
    Accessory::factory()->underRepair()->create(['registered_by' => $admin->id]);

    $user = userWithRole(UserRole::Carrier);

    expect(asUser($user)->getJson('/api/accessories?status=under_repair')->assertOk()->json('data'))->toHaveCount(1)
        ->and(asUser($user)->getJson('/api/accessories?status=inactive')->assertOk()->json('data'))->toHaveCount(1)
        ->and(asUser($user)->getJson('/api/accessories?status=perdido')->assertOk()->json('data'))->toHaveCount(3)
        ->and(asUser($user)->getJson('/api/accessories')->assertOk()->json('data'))->toHaveCount(3);
});

it('busca por nombre y por código sin distinguir mayúsculas', function (string $search) {
    $admin = userWithRole(UserRole::Administrator);
    Accessory::factory()->create(['registered_by' => $admin->id, 'name' => 'GATO HIDRAULICO 20 TON', 'code' => 'ACC-0001']);
    Accessory::factory()->create(['registered_by' => $admin->id, 'name' => 'LONA DE CARGA 8X12', 'code' => 'LON-0002']);

    $data = asUser(userWithRole(UserRole::Pilot))->getJson('/api/accessories?search='.urlencode($search))
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('GATO HIDRAULICO 20 TON');
})->with([
    'nombre en minúsculas' => 'gato',
    'nombre en mayúsculas' => 'GATO',
    'nombre capitalizado' => 'Gato',
    'código en minúsculas' => 'acc-0001',
    'código en mayúsculas' => 'ACC-0001',
]);

it('devuelve el listado completo cuando el término de búsqueda viene en blanco', function () {
    $admin = userWithRole(UserRole::Administrator);
    Accessory::factory()->count(2)->create(['registered_by' => $admin->id]);

    expect(asUser($admin)->getJson('/api/accessories?search=%20%20')->assertOk()->json('data'))->toHaveCount(2);
});

it('devuelve los accesorios ordenados por id ascendente', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessories = Accessory::factory()->count(5)->create(['registered_by' => $admin->id]);

    $ids = $accessories->pluck('id')->sort()->values()->all();

    expect(collect(asUser($admin)->getJson('/api/accessories')->assertOk()->json('data'))->pluck('id')->all())
        ->toBe($ids);
});

it('no dispara N+1 al listar accesorios de muchos registradores', function () {
    Accessory::factory()->count(20)->create();

    /** El token se emite antes de escuchar: sus claims consultan la empresa del usuario. */
    $token = JWTAuth::fromUser(userWithRole(UserRole::Administrator));

    resetAuthState();

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withToken($token)->getJson('/api/accessories')->assertOk()->assertJsonCount(20, 'data');

    $sobreAccesorios = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "accessories"'));
    $sobreUsuarios = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "users"'));

    /** Una consulta por el listado y otra por la relación: la del usuario autenticado es aparte. */
    expect($sobreAccesorios)->toHaveCount(1)
        ->and($sobreUsuarios->count())->toBeLessThanOrEqual(2);
});
