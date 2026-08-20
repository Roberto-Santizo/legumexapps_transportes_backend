<?php

use App\Enums\UserRole;
use App\Models\Accessory;
use App\Models\AccessoryCharacteristic;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Every accessory characteristics endpoint as method and URI, for the middleware datasets.
 *
 * Son cinco y ninguna más: ninguna anidada bajo /api/accessories/{accessory}, porque el
 * vínculo viaja en el cuerpo y en el query param obligatorio del listado.
 *
 * @return array<string, array{string, string}>
 */
function accessoryCharacteristicEndpoints(): array
{
    return [
        'index' => ['GET', '/api/accessory-characteristics'],
        'store' => ['POST', '/api/accessory-characteristics'],
        'show' => ['GET', '/api/accessory-characteristics/1'],
        'update' => ['PATCH', '/api/accessory-characteristics/1'],
        'destroy' => ['DELETE', '/api/accessory-characteristics/1'],
    ];
}

/**
 * The three endpoints restricted to the administrator.
 *
 * @return array<string, array{string, string}>
 */
function accessoryCharacteristicWriteEndpoints(): array
{
    return [
        'store' => ['POST', '/api/accessory-characteristics'],
        'update' => ['PATCH', '/api/accessory-characteristics/1'],
        'destroy' => ['DELETE', '/api/accessory-characteristics/1'],
    ];
}

/**
 * The roles that may read the characteristics but never write them.
 *
 * @return array<string, UserRole>
 */
function accessoryCharacteristicNonAdminRoles(): array
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
 * The six keys AccessoryCharacteristicResource promises, in the order it declares them.
 *
 * @return array<int, string>
 */
function accessoryCharacteristicResourceKeys(): array
{
    return ['id', 'accessoryId', 'name', 'value', 'registeredBy', 'createdAt'];
}

/**
 * A valid store payload, in the snake_case the endpoint expects.
 *
 * `accessory_id` no es camelCase a propósito: el StoreAccessoryCharacteristicRequest
 * lo declara así, a diferencia del `accessoryId` del query param del listado.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function accessoryCharacteristicPayload(int $accessoryId, array $overrides = []): array
{
    return array_merge([
        'accessory_id' => $accessoryId,
        'name' => 'placa',
        'value' => 'P-123ABC',
    ], $overrides);
}

/**
 * Create characteristics of one accessory without spawning an accessory and a user per row.
 *
 * @param  array<string, mixed>  $overrides
 * @return Collection<int, AccessoryCharacteristic>
 */
function seedCharacteristics(Accessory $accessory, User $registeredBy, int $count, array $overrides = []): Collection
{
    return AccessoryCharacteristic::factory()->count($count)->create(array_merge([
        'accessory_id' => $accessory->id,
        'registered_by' => $registeredBy->id,
    ], $overrides));
}

/*
|--------------------------------------------------------------------------
| Rutas y middlewares: jwt.auth y role
|--------------------------------------------------------------------------
*/

it('expone cinco rutas planas de características y ninguna anidada bajo accesorios', function () {
    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => str_contains($uri, 'characteristics'))
        ->values();

    expect($uris)->toHaveCount(5)
        ->and($uris->filter(fn (string $uri) => str_starts_with($uri, 'api/accessories/')))->toBeEmpty();
});

it('no declara carrier.required en ninguna ruta de características', function () {
    $middlewares = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'accessory-characteristics'))
        ->flatMap(fn ($route) => $route->gatherMiddleware())
        ->unique()
        ->values();

    expect($middlewares)->not->toContain('carrier.required')
        ->and($middlewares)->toContain('jwt.auth');
});

it('rechaza con 401 cualquier endpoint de características sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(accessoryCharacteristicEndpoints());

it('rechaza con 403 a quien no es administrador en los endpoints de escritura', function (string $method, string $uri, UserRole $role) {
    asUser(userWithRole($role))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(accessoryCharacteristicWriteEndpoints())->with(accessoryCharacteristicNonAdminRoles());

it('no crea, edita ni borra nada cuando un no administrador intenta escribir', function (UserRole $role) {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = seedCharacteristics($accessory, $admin, 1, ['name' => 'PLACA', 'value' => 'P-123ABC'])->first();

    $user = userWithRole($role);

    asUser($user)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($accessory->id, ['name' => 'color']))->assertForbidden();
    asUser($user)->patchJson("/api/accessory-characteristics/{$characteristic->id}", ['value' => 'otro'])->assertForbidden();
    asUser($user)->deleteJson("/api/accessory-characteristics/{$characteristic->id}")->assertForbidden();

    expect(AccessoryCharacteristic::query()->count())->toBe(1)
        ->and($characteristic->fresh()->value)->toBe('P-123ABC');
})->with(accessoryCharacteristicNonAdminRoles());

it('deja leer las características a cualquier rol, incluido un carrier sin empresa', function (UserRole $role) {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = seedCharacteristics($accessory, $admin, 2)->first();

    $user = userWithRole($role);

    /** Ninguna ruta lleva carrier.required: las características cuelgan del inventario nacional. */
    expect($user->currentCarrier())->toBeNull();

    /** Con limit para no cruzarse con el sobre roto del listado sin paginar. */
    asUser($user)->getJson("/api/accessory-characteristics?accessoryId={$accessory->id}&limit=10")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    asUser($user)->getJson("/api/accessory-characteristics/{$characteristic->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $characteristic->id);
})->with(accessoryCharacteristicNonAdminRoles());

it('deja leer las características también a un administrador', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = seedCharacteristics($accessory, $admin, 1)->first();

    asUser($admin)->getJson("/api/accessory-characteristics?accessoryId={$accessory->id}")->assertOk();
    asUser($admin)->getJson("/api/accessory-characteristics/{$characteristic->id}")->assertOk();
});

/*
|--------------------------------------------------------------------------
| Listado — accessoryId obligatorio
|--------------------------------------------------------------------------
*/

it('rechaza con 422 el listado sin accessoryId, con el formato de validación de Laravel', function () {
    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/accessory-characteristics')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessoryId'])
        ->assertJsonPath('errors.accessoryId.0', 'El accesorio es obligatorio')
        ->assertJsonStructure(['message', 'errors' => ['accessoryId']]);

    /** No es el sobre habitual: no hay statusCode ni data. */
    expect(array_keys($response->json()))->toEqualCanonicalizing(['message', 'errors']);
});

it('rechaza con 422 un accessoryId que no es entero', function () {
    asUser(userWithRole(UserRole::Administrator))->getJson('/api/accessory-characteristics?accessoryId=abc')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessoryId'])
        ->assertJsonPath('errors.accessoryId.0', 'El accesorio debe ser un número entero');
});

it('responde 404 y no lista vacío cuando el accessoryId no existe', function () {
    asUser(userWithRole(UserRole::Administrator))->getJson('/api/accessory-characteristics?accessoryId=9999')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El accesorio no existe',
            'data' => null,
        ]);
});

it('lista las características de un accesorio dado de baja o en reparación', function (string $estado) {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->{$estado}()->create(['registered_by' => $admin->id]);

    seedCharacteristics($accessory, $admin, 3);

    asUser($admin)->getJson("/api/accessory-characteristics?accessoryId={$accessory->id}&limit=10")
        ->assertOk()
        ->assertJsonPath('message', 'Características obtenidas correctamente')
        ->assertJsonCount(3, 'data');
})->with(['inactive', 'underRepair']);

it('devuelve solo las características del accesorio pedido, ordenadas por id ascendente', function () {
    $admin = userWithRole(UserRole::Administrator);
    $uno = Accessory::factory()->create(['registered_by' => $admin->id]);
    $otro = Accessory::factory()->create(['registered_by' => $admin->id]);

    $propias = seedCharacteristics($uno, $admin, 4);
    seedCharacteristics($otro, $admin, 3);

    $data = asUser($admin)->getJson("/api/accessory-characteristics?accessoryId={$uno->id}&limit=10")
        ->assertOk()
        ->json('data');

    expect(array_column($data, 'id'))->toBe($propias->pluck('id')->sort()->values()->all())
        ->and(array_unique(array_column($data, 'accessoryId')))->toBe([$uno->id]);
});

it('devuelve 200 con data vacío cuando el accesorio no tiene características', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->getJson("/api/accessory-characteristics?accessoryId={$accessory->id}")
        ->assertOk()
        ->assertJsonPath('data', []);
});

/*
|--------------------------------------------------------------------------
| Listado — paginación opt-in
|--------------------------------------------------------------------------
*/

it('devuelve la colección completa y ninguna clave de paginación sin limit', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    seedCharacteristics($accessory, $admin, 12);

    $response = asUser($admin)->getJson("/api/accessory-characteristics?accessoryId={$accessory->id}")->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12);
});

it('devuelve los metadatos de paginación en la raíz del sobre con limit', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    seedCharacteristics($accessory, $admin, 12);

    $response = asUser($admin)->getJson("/api/accessory-characteristics?accessoryId={$accessory->id}&limit=5")->assertOk();

    expect($response->json())->toHaveKeys(['statusCode', 'message', 'data', 'total', 'currentPage', 'lastPage'])
        ->and($response->json('total'))->toBe(12)
        ->and($response->json('currentPage'))->toBe(1)
        ->and($response->json('lastPage'))->toBe(2)
        ->and($response->json('meta'))->toBeNull()
        /** limit=5 se acota al mínimo de 10. */
        ->and($response->json('data'))->toHaveCount(10);
});

it('acota el tamaño de página a [10, 100] también por HTTP', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    seedCharacteristics($accessory, $admin, 101);

    $porDebajo = asUser($admin)->getJson("/api/accessory-characteristics?accessoryId={$accessory->id}&limit=1")->assertOk();
    $porEncima = asUser($admin)->getJson("/api/accessory-characteristics?accessoryId={$accessory->id}&limit=500")->assertOk();

    expect($porDebajo->json('data'))->toHaveCount(10)
        ->and($porDebajo->json('lastPage'))->toBe(11)
        ->and($porEncima->json('data'))->toHaveCount(100)
        ->and($porEncima->json('lastPage'))->toBe(2)
        ->and($porEncima->json('total'))->toBe(101);
});

it('no pagina cuando el limit no es numérico', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    seedCharacteristics($accessory, $admin, 12);

    $response = asUser($admin)->getJson("/api/accessory-characteristics?accessoryId={$accessory->id}&limit=abc")->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12);
});

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('registra una característica con los tres campos obligatorios', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    $response = asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($accessory->id))
        ->assertCreated()
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Característica registrada correctamente')
        ->assertJsonPath('data.accessoryId', $accessory->id)
        ->assertJsonPath('data.name', 'PLACA')
        ->assertJsonPath('data.value', 'P-123ABC')
        ->assertJsonPath('data.registeredBy', $admin->name);

    expect(array_keys($response->json('data')))->toBe(accessoryCharacteristicResourceKeys());

    $this->assertDatabaseHas('accessory_characteristics', [
        'id' => $response->json('data.id'),
        'accessory_id' => $accessory->id,
        'name' => 'PLACA',
        'value' => 'P-123ABC',
        'registered_by' => $admin->id,
    ]);
});

it('normaliza el nombre a mayúsculas colapsando los espacios internos', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($accessory->id, [
        'name' => '  placa   trasera ',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.name', 'PLACA TRASERA');

    $this->assertDatabaseHas('accessory_characteristics', ['accessory_id' => $accessory->id, 'name' => 'PLACA TRASERA']);
});

it('solo recorta el valor, sin cambiarlo de caja', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($accessory->id, [
        'name' => 'tipo de combustible',
        'value' => '  Diésel ',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.name', 'TIPO DE COMBUSTIBLE')
        ->assertJsonPath('data.value', 'Diésel');

    $this->assertDatabaseHas('accessory_characteristics', ['accessory_id' => $accessory->id, 'value' => 'Diésel']);
});

it('conserva los espacios internos del valor', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($accessory->id, [
        'value' => '  Acero   inoxidable ',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.value', 'Acero   inoxidable');
});

it('rechaza con 400 del service un nombre repetido en el mismo accesorio', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($accessory->id))->assertCreated();

    /** Repetido en otra caja y con otros espacios: la normalización lo deja en el mismo PLACA. */
    asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($accessory->id, [
        'name' => '  Placa ',
        'value' => 'P-999XYZ',
    ]))
        ->assertBadRequest()
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El accesorio ya tiene una característica con ese nombre',
            'data' => null,
        ]);

    expect(AccessoryCharacteristic::query()->count())->toBe(1);
});

it('acepta el mismo nombre en otro accesorio', function () {
    $admin = userWithRole(UserRole::Administrator);
    $uno = Accessory::factory()->create(['registered_by' => $admin->id]);
    $otro = Accessory::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($uno->id))->assertCreated();
    asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($otro->id))->assertCreated();

    expect(AccessoryCharacteristic::query()->where('name', '=', 'PLACA')->count())->toBe(2);
});

it('rechaza con 422 el alta sin alguno de los campos obligatorios, con el mensaje en español', function (string $campo, string $mensaje) {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    $payload = accessoryCharacteristicPayload($accessory->id);

    unset($payload[$campo]);

    asUser($admin)->postJson('/api/accessory-characteristics', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$campo])
        ->assertJsonFragment([$mensaje]);
})->with([
    'accessory_id' => ['accessory_id', 'El accesorio es obligatorio'],
    'name' => ['name', 'El nombre de la característica es obligatorio'],
    'value' => ['value', 'El valor de la característica es obligatorio'],
]);

it('rechaza con 422 por la regla exists un accessory_id inexistente', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload(9999))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessory_id'])
        ->assertJsonFragment(['El accesorio no existe']);
});

it('rechaza con 422 un valor de más de 500 caracteres y acepta uno de 500', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($accessory->id, [
        'value' => str_repeat('a', 501),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['value'])
        ->assertJsonFragment(['El valor de la característica no puede superar los 500 caracteres']);

    asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($accessory->id, [
        'value' => str_repeat('a', 500),
    ]))->assertCreated();
});

it('rechaza con 422 un nombre de más de 255 caracteres', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($accessory->id, [
        'name' => str_repeat('a', 256),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name'])
        ->assertJsonFragment(['El nombre de la característica no puede superar los 255 caracteres']);
});

it('registra al usuario autenticado aunque el body mande otro responsable', function () {
    $admin = userWithRole(UserRole::Administrator);
    $otro = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $otro->id]);

    $response = asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($accessory->id, [
        'registeredBy' => $otro->id,
        'registered_by' => $otro->id,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.registeredBy', $admin->name);

    $this->assertDatabaseHas('accessory_characteristics', [
        'id' => $response->json('data.id'),
        'registered_by' => $admin->id,
    ]);
});

it('acepta el alta sobre un accesorio dado de baja o en reparación', function (string $estado) {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->{$estado}()->create(['registered_by' => $admin->id]);

    asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($accessory->id))
        ->assertCreated()
        ->assertJsonPath('data.accessoryId', $accessory->id);
})->with(['inactive', 'underRepair']);

/*
|--------------------------------------------------------------------------
| Detalle
|--------------------------------------------------------------------------
*/

it('devuelve las seis claves en camelCase con la fecha del proyecto', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = seedCharacteristics($accessory, $admin, 1, [
        'name' => 'PLACA',
        'value' => 'P-123ABC',
        'created_at' => Carbon::parse('2026-08-19 20:45:12'),
    ])->first();

    $detalle = asUser($admin)->getJson("/api/accessory-characteristics/{$characteristic->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Característica obtenida correctamente')
        ->assertJsonPath('data.id', $characteristic->id)
        ->assertJsonPath('data.accessoryId', $accessory->id)
        ->assertJsonPath('data.name', 'PLACA')
        ->assertJsonPath('data.value', 'P-123ABC')
        ->assertJsonPath('data.registeredBy', $admin->name)
        ->assertJsonPath('data.createdAt', '19-08-2026 08:45:12 PM')
        ->json('data');

    expect(array_keys($detalle))->toBe(accessoryCharacteristicResourceKeys())
        ->and($detalle['accessoryId'])->toBeInt()
        ->and($detalle)->not->toHaveKey('updatedAt')
        ->and($detalle)->not->toHaveKey('accessoryName');
});

it('responde 404 en las tres acciones que resuelven la característica por id', function (string $method, string $uri) {
    asUser(userWithRole(UserRole::Administrator))->json($method, $uri)
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'La característica no existe',
            'data' => null,
        ]);
})->with([
    'show' => ['GET', '/api/accessory-characteristics/9999'],
    'update' => ['PATCH', '/api/accessory-characteristics/9999'],
    'destroy' => ['DELETE', '/api/accessory-characteristics/9999'],
]);

/*
|--------------------------------------------------------------------------
| Edición
|--------------------------------------------------------------------------
*/

it('cambia solo el valor y deja el nombre intacto', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = seedCharacteristics($accessory, $admin, 1, ['name' => 'PLACA', 'value' => 'P-123ABC'])->first();

    asUser($admin)->patchJson("/api/accessory-characteristics/{$characteristic->id}", ['value' => '  P-999XYZ '])
        ->assertOk()
        ->assertJsonPath('message', 'Característica actualizada correctamente')
        ->assertJsonPath('data.name', 'PLACA')
        ->assertJsonPath('data.value', 'P-999XYZ');

    $this->assertDatabaseHas('accessory_characteristics', [
        'id' => $characteristic->id,
        'name' => 'PLACA',
        'value' => 'P-999XYZ',
    ]);
});

it('cambia solo el nombre y deja el valor intacto', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = seedCharacteristics($accessory, $admin, 1, ['name' => 'PLACA', 'value' => 'P-123ABC'])->first();

    asUser($admin)->patchJson("/api/accessory-characteristics/{$characteristic->id}", ['name' => '  placa   trasera '])
        ->assertOk()
        ->assertJsonPath('data.name', 'PLACA TRASERA')
        ->assertJsonPath('data.value', 'P-123ABC');
});

it('ignora en silencio un accessory_id que llegue en el PATCH', function (string $clave) {
    $admin = userWithRole(UserRole::Administrator);
    $uno = Accessory::factory()->create(['registered_by' => $admin->id]);
    $otro = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = seedCharacteristics($uno, $admin, 1, ['name' => 'PLACA', 'value' => 'P-123ABC'])->first();

    asUser($admin)->patchJson("/api/accessory-characteristics/{$characteristic->id}", [
        $clave => $otro->id,
        'value' => 'P-999XYZ',
    ])
        ->assertOk()
        ->assertJsonPath('data.accessoryId', $uno->id)
        ->assertJsonPath('data.value', 'P-999XYZ');

    $this->assertDatabaseHas('accessory_characteristics', [
        'id' => $characteristic->id,
        'accessory_id' => $uno->id,
    ]);
})->with(['accessory_id', 'accessoryId']);

it('rechaza con 400 el nombre de otra característica del mismo accesorio y acepta el propio', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = seedCharacteristics($accessory, $admin, 1, ['name' => 'PLACA', 'value' => 'P-123ABC'])->first();
    seedCharacteristics($accessory, $admin, 1, ['name' => 'COLOR', 'value' => 'Rojo']);

    /** Reenviar su propio nombre no choca consigo misma. */
    asUser($admin)->patchJson("/api/accessory-characteristics/{$characteristic->id}", ['name' => ' placa '])
        ->assertOk()
        ->assertJsonPath('data.name', 'PLACA');

    asUser($admin)->patchJson("/api/accessory-characteristics/{$characteristic->id}", ['name' => 'color'])
        ->assertBadRequest()
        ->assertJsonPath('message', 'El accesorio ya tiene una característica con ese nombre');

    expect($characteristic->fresh()->name)->toBe('PLACA');
});

it('acepta el nombre que ya usa otro accesorio', function () {
    $admin = userWithRole(UserRole::Administrator);
    $uno = Accessory::factory()->create(['registered_by' => $admin->id]);
    $otro = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = seedCharacteristics($uno, $admin, 1, ['name' => 'COLOR', 'value' => 'Rojo'])->first();
    seedCharacteristics($otro, $admin, 1, ['name' => 'PLACA', 'value' => 'P-123ABC']);

    asUser($admin)->patchJson("/api/accessory-characteristics/{$characteristic->id}", ['name' => 'placa'])
        ->assertOk()
        ->assertJsonPath('data.name', 'PLACA');
});

it('no reescribe al responsable del alta cuando edita otro administrador', function () {
    $original = userWithRole(UserRole::Administrator);
    $otro = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $original->id]);
    $characteristic = seedCharacteristics($accessory, $original, 1)->first();

    asUser($otro)->patchJson("/api/accessory-characteristics/{$characteristic->id}", ['value' => 'otro valor'])
        ->assertOk()
        ->assertJsonPath('data.registeredBy', $original->name);

    $this->assertDatabaseHas('accessory_characteristics', [
        'id' => $characteristic->id,
        'registered_by' => $original->id,
    ]);
});

it('acepta un PATCH con el cuerpo vacío como no-op', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = seedCharacteristics($accessory, $admin, 1, ['name' => 'PLACA', 'value' => 'P-123ABC'])->first();

    asUser($admin)->patchJson("/api/accessory-characteristics/{$characteristic->id}", [])
        ->assertOk()
        ->assertJsonPath('data.name', 'PLACA')
        ->assertJsonPath('data.value', 'P-123ABC');
});

it('rechaza con 422 un nombre o un valor vacíos en el PATCH', function (string $campo) {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = seedCharacteristics($accessory, $admin, 1)->first();

    asUser($admin)->patchJson("/api/accessory-characteristics/{$characteristic->id}", [$campo => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$campo]);
})->with(['name', 'value']);

it('edita la característica de un accesorio dado de baja', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->inactive()->create(['registered_by' => $admin->id]);
    $characteristic = seedCharacteristics($accessory, $admin, 1, ['name' => 'PLACA', 'value' => 'P-123ABC'])->first();

    asUser($admin)->patchJson("/api/accessory-characteristics/{$characteristic->id}", ['value' => 'P-999XYZ'])
        ->assertOk()
        ->assertJsonPath('data.value', 'P-999XYZ');
});

/*
|--------------------------------------------------------------------------
| Baja física
|--------------------------------------------------------------------------
*/

it('borra la fila de verdad y responde 404 al segundo DELETE', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = seedCharacteristics($accessory, $admin, 1, ['name' => 'PLACA', 'value' => 'P-123ABC'])->first();

    asUser($admin)->deleteJson("/api/accessory-characteristics/{$characteristic->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Característica eliminada correctamente')
        ->assertJsonPath('data.id', $characteristic->id)
        ->assertJsonPath('data.name', 'PLACA');

    $this->assertDatabaseMissing('accessory_characteristics', ['id' => $characteristic->id]);

    asUser($admin)->deleteJson("/api/accessory-characteristics/{$characteristic->id}")
        ->assertNotFound()
        ->assertJsonPath('message', 'La característica no existe');
});

it('no toca el accesorio ni las demás características al borrar una', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->active()->create(['registered_by' => $admin->id]);
    $characteristics = seedCharacteristics($accessory, $admin, 3);
    $borrada = $characteristics->first();

    asUser($admin)->deleteJson("/api/accessory-characteristics/{$borrada->id}")->assertOk();

    expect(AccessoryCharacteristic::query()->where('accessory_id', '=', $accessory->id)->count())->toBe(2)
        ->and(Accessory::query()->whereKey($accessory->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('accessories', ['id' => $accessory->id, 'status' => 'active']);

    asUser($admin)->getJson("/api/accessory-characteristics?accessoryId={$accessory->id}&limit=10")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('libera el nombre borrado para poder volver a registrarlo', function () {
    $admin = userWithRole(UserRole::Administrator);
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    $id = asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($accessory->id))
        ->assertCreated()
        ->json('data.id');

    asUser($admin)->deleteJson("/api/accessory-characteristics/{$id}")->assertOk();

    /** Sin SoftDeletes no queda tumba que bloquee el nombre. */
    asUser($admin)->postJson('/api/accessory-characteristics', accessoryCharacteristicPayload($accessory->id))
        ->assertCreated();
});
