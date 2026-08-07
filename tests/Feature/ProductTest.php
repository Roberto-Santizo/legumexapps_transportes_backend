<?php

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Every products endpoint as method and URI, for the middleware datasets.
 *
 * @return array<string, array{string, string}>
 */
function productEndpoints(): array
{
    return [
        'index' => ['GET', '/api/products'],
        'store' => ['POST', '/api/products'],
        'show' => ['GET', '/api/products/1'],
        'update' => ['PATCH', '/api/products/1'],
        'toggle-status' => ['PATCH', '/api/products/1/toggle-status'],
        'destroy' => ['DELETE', '/api/products/1'],
    ];
}

/**
 * The four endpoints restricted to the administrator.
 *
 * @return array<string, array{string, string}>
 */
function productWriteEndpoints(): array
{
    return [
        'store' => ['POST', '/api/products'],
        'update' => ['PATCH', '/api/products/1'],
        'toggle-status' => ['PATCH', '/api/products/1/toggle-status'],
        'destroy' => ['DELETE', '/api/products/1'],
    ];
}

/**
 * The roles that may read the catalogue but never write it.
 *
 * @return array<string, UserRole>
 */
function productNonAdminRoles(): array
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
 * The six keys ProductResource promises, in the order the resource declares them.
 *
 * @return array<int, string>
 */
function productResourceKeys(): array
{
    return ['id', 'name', 'status', 'registeredByName', 'createdAt', 'updatedAt'];
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth y role
|--------------------------------------------------------------------------
*/

it('rechaza con 401 cualquier endpoint de productos sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(productEndpoints());

it('rechaza con 403 a quien no es administrador en los endpoints de escritura', function (string $method, string $uri, UserRole $role) {
    asUser(userWithRole($role))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(productWriteEndpoints())->with(productNonAdminRoles());

it('no crea, modifica ni da de baja nada cuando un no administrador intenta escribir', function (UserRole $role) {
    $product = Product::factory()->active()->create(['name' => 'BROCOLI']);

    $user = userWithRole($role);

    asUser($user)->postJson('/api/products', ['name' => 'fresa'])->assertForbidden();
    asUser($user)->patchJson("/api/products/{$product->id}", ['name' => 'fresa'])->assertForbidden();
    asUser($user)->patchJson("/api/products/{$product->id}/toggle-status")->assertForbidden();
    asUser($user)->deleteJson("/api/products/{$product->id}")->assertForbidden();

    $this->assertDatabaseCount('products', 1);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'BROCOLI', 'status' => true]);
})->with(productNonAdminRoles());

it('deja leer el catálogo a cualquier autenticado sin exigirle empresa', function (UserRole $role) {
    $product = Product::factory()->active()->create();

    /** Ninguno de estos usuarios está vinculado a una empresa: el catálogo es nacional. */
    $user = userWithRole($role);

    asUser($user)->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data');
    asUser($user)->getJson("/api/products/{$product->id}")->assertOk();
})->with(productNonAdminRoles() + ['administrator' => UserRole::Administrator]);

/*
|--------------------------------------------------------------------------
| POST /api/products
|--------------------------------------------------------------------------
*/

it('registra un producto en mayúsculas y activo con 201', function () {
    $admin = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Ana Administradora']);

    $response = asUser($admin)->postJson('/api/products', ['name' => 'brocoli']);

    $response->assertCreated()
        ->assertJson([
            'statusCode' => 201,
            'message' => 'Producto registrado correctamente',
            'data' => [
                'name' => 'BROCOLI',
                'status' => true,
                'registeredByName' => 'Ana Administradora',
            ],
        ]);

    expect(array_keys($response->json('data')))->toEqualCanonicalizing(productResourceKeys());

    $this->assertDatabaseHas('products', [
        'name' => 'BROCOLI',
        'status' => true,
        'registered_by' => $admin->id,
    ]);
});

it('recorta y colapsa los espacios del nombre al registrar', function (string $enviado, string $guardado) {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/products', ['name' => $enviado])
        ->assertCreated()
        ->assertJsonPath('data.name', $guardado);

    $this->assertDatabaseHas('products', ['name' => $guardado]);
})->with([
    'espacios en los extremos' => ['  mini zanahoria  ', 'MINI ZANAHORIA'],
    'espacios internos repetidos' => ['  mini   zanahoria  ', 'MINI ZANAHORIA'],
    'tabulaciones internas' => ["ejote\t\tfrances", 'EJOTE FRANCES'],
]);

it('ignora el status del body al registrar y nace siempre activo', function () {
    $response = asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/products', ['name' => 'brocoli', 'status' => false]);

    $response->assertCreated()->assertJsonPath('data.status', true);

    $this->assertDatabaseHas('products', ['name' => 'BROCOLI', 'status' => true]);
});

it('toma registered_by del usuario autenticado aunque el body mande otro', function () {
    $admin = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Ana Administradora']);
    $otro = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Beto Administrador']);

    asUser($admin)->postJson('/api/products', [
        'name' => 'brocoli',
        'registeredBy' => $otro->id,
        'registered_by' => $otro->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.registeredByName', 'Ana Administradora');

    $this->assertDatabaseHas('products', ['name' => 'BROCOLI', 'registered_by' => $admin->id]);
    $this->assertDatabaseMissing('products', ['registered_by' => $otro->id]);
});

it('rechaza con 422 un nombre que ya existe, aunque venga en minúsculas', function (string $enviado) {
    Product::factory()->create(['name' => 'BROCOLI']);

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/products', ['name' => $enviado])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name'])
        ->assertJsonPath('errors.name.0', 'Ya existe un producto con ese nombre');

    $this->assertDatabaseCount('products', 1);
})->with([
    'mismo nombre exacto' => ['BROCOLI'],
    'en minúsculas' => ['brocoli'],
    'con mayúsculas mezcladas' => ['BroCoLi'],
    'con espacios de sobra' => ['  brocoli  '],
]);

it('rechaza con 422 los nombres inválidos al registrar', function (mixed $name, string $message) {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/products', ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name'])
        ->assertJsonPath('errors.name.0', $message);

    $this->assertDatabaseCount('products', 0);
})->with([
    'nombre vacío' => ['', 'El nombre del producto es obligatorio'],
    'solo espacios' => ['   ', 'El nombre del producto es obligatorio'],
    'nombre nulo' => [null, 'El nombre del producto es obligatorio'],
    'nombre no textual' => [['brocoli'], 'El nombre del producto debe ser texto'],
    'nombre de más de 255 caracteres' => [str_repeat('a', 256), 'El nombre del producto no puede superar los 255 caracteres'],
]);

it('rechaza con 422 un alta sin la clave name', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/products', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name'])
        ->assertJsonPath('errors.name.0', 'El nombre del producto es obligatorio');

    $this->assertDatabaseCount('products', 0);
});

/*
|--------------------------------------------------------------------------
| GET /api/products
|--------------------------------------------------------------------------
*/

it('devuelve el catálogo ordenado por id ascendente', function () {
    $primero = Product::factory()->create(['name' => 'ZANAHORIA']);
    $segundo = Product::factory()->create(['name' => 'APIO']);
    $tercero = Product::factory()->create(['name' => 'BROCOLI']);

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/products');

    $response->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Productos obtenidos correctamente',
        ])
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => [productResourceKeys()],
        ]);

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe([$primero->id, $segundo->id, $tercero->id]);
});

it('incluye activos e inactivos en el listado sin filtros', function () {
    $activos = Product::factory()->count(2)->active()->create();
    $inactivos = Product::factory()->count(3)->inactive()->create();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/products');

    $response->assertOk()->assertJsonCount(5, 'data');

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toEqualCanonicalizing($activos->pluck('id')->merge($inactivos->pluck('id'))->all());
});

it('filtra el listado por status booleano', function (string $query, int $esperado, ?bool $status) {
    Product::factory()->count(2)->active()->create();
    Product::factory()->count(3)->inactive()->create();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson("/api/products?status={$query}");

    $response->assertOk()->assertJsonCount($esperado, 'data');

    if ($status !== null) {
        expect(collect($response->json('data'))->pluck('status')->unique()->all())->toBe([$status]);
    }
})->with([
    'false' => ['false', 3, false],
    'cero' => ['0', 3, false],
    'true' => ['true', 2, true],
    'uno' => ['1', 2, true],
]);

it('ignora sin error un status que no es booleano', function (string $query) {
    Product::factory()->count(2)->active()->create();
    Product::factory()->count(3)->inactive()->create();

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/products?status={$query}")
        ->assertOk()
        ->assertJsonCount(5, 'data');
})->with([
    'palabra suelta' => ['quizas'],
    'número fuera de rango' => ['7'],
    'número negativo' => ['-1'],
    /** ConvertEmptyStringsToNull deja el filtro en null antes de llegar al service. */
    'valor vacío' => [''],
]);

it('busca por nombre con el término normalizado a mayúsculas', function (string $term) {
    $brocoli = Product::factory()->create(['name' => 'BROCOLI']);
    Product::factory()->create(['name' => 'ZANAHORIA']);
    Product::factory()->create(['name' => 'APIO']);

    $response = asUser(userWithRole(UserRole::Administrator))->getJson("/api/products?search={$term}");

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $brocoli->id)
        ->assertJsonPath('data.0.name', 'BROCOLI');
})->with([
    'en minúsculas' => ['broc'],
    'en mayúsculas' => ['BROC'],
    'con mayúsculas mezcladas' => ['BroC'],
    'coincidencia interna' => ['ocol'],
    'nombre completo' => ['brocoli'],
]);

it('ignora el filtro search en blanco', function (string $term) {
    Product::factory()->count(3)->create();

    $query = http_build_query(['search' => $term]);

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/products?{$query}")
        ->assertOk()
        ->assertJsonCount(3, 'data');
})->with([
    'vacío' => [''],
    'solo espacios' => ['   '],
    'solo tabulaciones' => ["\t"],
]);

it('devuelve una lista vacía y no 404 cuando la búsqueda no encuentra nada', function () {
    Product::factory()->count(3)->create();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/products?search=zzz')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('data', []);
});

it('combina el filtro de estado con el de búsqueda', function () {
    $buscado = Product::factory()->inactive()->create(['name' => 'BROCOLI MORADO']);

    Product::factory()->active()->create(['name' => 'BROCOLI VERDE']);
    Product::factory()->inactive()->create(['name' => 'ZANAHORIA']);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/products?search=brocoli&status=false')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $buscado->id);
});

it('devuelve el nombre de quien registró cada producto del listado', function () {
    $ana = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Ana Administradora']);
    $beto = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Beto Administrador']);

    Product::factory()->create(['registered_by' => $ana->id]);
    Product::factory()->create(['registered_by' => $beto->id]);

    $response = asUser($ana)->getJson('/api/products');

    expect(collect($response->assertOk()->json('data'))->pluck('registeredByName')->all())
        ->toEqualCanonicalizing(['Ana Administradora', 'Beto Administrador']);
});

it('no dispara N+1 al listar productos de muchos registradores', function () {
    Product::factory()->count(20)->create();

    /** El token se emite antes de escuchar: sus claims consultan la empresa del usuario. */
    $token = JWTAuth::fromUser(userWithRole(UserRole::Administrator));

    resetAuthState();

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withToken($token)->getJson('/api/products')->assertOk()->assertJsonCount(20, 'data');

    $sobreProductos = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "products"'));
    $sobreUsuarios = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "users"'));

    /** Una consulta por el listado y otra por la relación: la del usuario autenticado es aparte. */
    expect($sobreProductos)->toHaveCount(1)
        ->and($sobreUsuarios->count())->toBeLessThanOrEqual(2);
});

/*
|--------------------------------------------------------------------------
| Paginación
|--------------------------------------------------------------------------
*/

it('devuelve todos los productos y ninguna clave de paginación sin limit', function () {
    Product::factory()->count(12)->create();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/products');

    $response->assertOk()->assertJsonCount(12, 'data');

    expect($response->json())->not->toHaveKeys(['total', 'currentPage', 'lastPage']);
});

it('devuelve el sobre paginado de productos con limit numérico', function () {
    Product::factory()->count(12)->create();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/products?limit=10')
        ->assertOk()
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
            'data' => [productResourceKeys()],
            'total',
            'currentPage',
            'lastPage',
        ]);
});

it('devuelve todos los productos sin error cuando limit no es numérico', function () {
    Product::factory()->count(12)->create();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/products?limit=abc');

    $response->assertOk()->assertJsonCount(12, 'data');

    expect($response->json())->not->toHaveKey('total');
});

it('acota el limit inferior de productos a 10 por página', function () {
    Product::factory()->count(12)->create();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/products?limit=3')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('lastPage', 2);
});

it('acota el limit superior de productos a 100 por página', function () {
    Product::factory()->count(101)->create();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/products?limit=500')
        ->assertOk()
        ->assertJsonCount(100, 'data')
        ->assertJsonPath('total', 101)
        ->assertJsonPath('lastPage', 2);
});

it('aplica a la vez el filtro de búsqueda y la paginación', function () {
    foreach (range(1, 12) as $i) {
        Product::factory()->create(['name' => "BROCOLI {$i}"]);
    }

    foreach (range(1, 5) as $i) {
        Product::factory()->create(['name' => "ZANAHORIA {$i}"]);
    }

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/products?search=brocoli&limit=10');

    $response->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('total', 12)
        ->assertJsonPath('lastPage', 2);

    expect(collect($response->json('data'))->every(fn (array $row) => str_starts_with($row['name'], 'BROCOLI')))
        ->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| GET /api/products/{product}
|--------------------------------------------------------------------------
*/

it('devuelve exactamente las seis claves del recurso al pedir un producto', function () {
    $admin = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Ana Administradora']);

    $product = Product::factory()->active()->create([
        'name' => 'BROCOLI',
        'registered_by' => $admin->id,
    ]);

    $response = asUser($admin)->getJson("/api/products/{$product->id}");

    $response->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Producto obtenido correctamente',
            'data' => [
                'id' => $product->id,
                'name' => 'BROCOLI',
                'status' => true,
                'registeredByName' => 'Ana Administradora',
            ],
        ]);

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and(array_keys($response->json('data')))->toEqualCanonicalizing(productResourceKeys());
});

it('devuelve el status como booleano JSON, no como 1 o 0 ni como cadena', function (bool $status) {
    $product = Product::factory()->{$status ? 'active' : 'inactive'}()->create();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson("/api/products/{$product->id}");

    expect($response->assertOk()->json('data.status'))->toBeBool()->toBe($status);
})->with([
    'activo' => [true],
    'inactivo' => [false],
]);

it('emite las fechas con el formato d-m-Y h:i:s A propio del catálogo', function () {
    $product = Product::factory()->create([
        'created_at' => Carbon::parse('2026-08-07 18:03:22'),
        'updated_at' => Carbon::parse('2026-08-07 18:11:40'),
    ]);

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.createdAt', '07-08-2026 06:03:22 PM')
        ->assertJsonPath('data.updatedAt', '07-08-2026 06:11:40 PM');
});

it('devuelve también los productos dados de baja', function () {
    $product = Product::factory()->inactive()->create();

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.status', false);
});

it('devuelve 404 al pedir un producto que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))->getJson('/api/products/99999')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El producto no existe',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| PATCH /api/products/{product}
|--------------------------------------------------------------------------
*/

it('actualiza el nombre y lo deja normalizado', function () {
    $product = Product::factory()->active()->create(['name' => 'BROCOLI']);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/products/{$product->id}", ['name' => '  fresa  silvestre '])
        ->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Producto actualizado correctamente',
            'data' => [
                'id' => $product->id,
                'name' => 'FRESA SILVESTRE',
                'status' => true,
            ],
        ]);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'FRESA SILVESTRE', 'status' => true]);
});

it('actualiza solo el estado sin tocar el nombre', function () {
    $product = Product::factory()->active()->create(['name' => 'BROCOLI']);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/products/{$product->id}", ['status' => false])
        ->assertOk()
        ->assertJsonPath('data.name', 'BROCOLI')
        ->assertJsonPath('data.status', false);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'BROCOLI', 'status' => false]);
});

it('actualiza nombre y estado a la vez', function () {
    $product = Product::factory()->inactive()->create(['name' => 'BROCOLI']);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/products/{$product->id}", ['name' => 'fresa', 'status' => true])
        ->assertOk()
        ->assertJsonPath('data.name', 'FRESA')
        ->assertJsonPath('data.status', true);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'FRESA', 'status' => true]);
});

it('reactiva con un PATCH de status true un producto dado de baja', function () {
    $product = Product::factory()->inactive()->create();

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/products/{$product->id}", ['status' => true])
        ->assertOk()
        ->assertJsonPath('data.status', true);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => true]);
});

it('acepta reenviar el mismo nombre del propio producto sin chocar consigo mismo', function (string $enviado) {
    $product = Product::factory()->active()->create(['name' => 'BROCOLI']);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/products/{$product->id}", ['name' => $enviado])
        ->assertOk()
        ->assertJsonPath('data.name', 'BROCOLI');

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'BROCOLI']);
})->with([
    'idéntico' => ['BROCOLI'],
    'en minúsculas' => ['brocoli'],
    'con espacios de sobra' => ['  brocoli  '],
]);

it('rechaza con 422 un nombre que ya usa otro producto', function (string $enviado) {
    Product::factory()->create(['name' => 'ZANAHORIA']);
    $product = Product::factory()->create(['name' => 'BROCOLI']);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/products/{$product->id}", ['name' => $enviado])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name'])
        ->assertJsonPath('errors.name.0', 'Ya existe un producto con ese nombre');

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'BROCOLI']);
})->with([
    'idéntico' => ['ZANAHORIA'],
    'en minúsculas' => ['zanahoria'],
]);

it('rechaza con 422 un PATCH con el cuerpo vacío', function () {
    $product = Product::factory()->active()->create(['name' => 'BROCOLI']);

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/products/{$product->id}", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'status'])
        ->assertJsonPath('errors.name.0', 'Debe enviar al menos el nombre o el estado')
        ->assertJsonPath('errors.status.0', 'Debe enviar al menos el nombre o el estado');

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'BROCOLI', 'status' => true]);
});

it('rechaza con 422 los valores inválidos al actualizar', function (array $payload, string $field, string $message) {
    $product = Product::factory()->active()->create(['name' => 'BROCOLI']);

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/products/{$product->id}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field])
        ->assertJsonPath("errors.{$field}.0", $message);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'BROCOLI', 'status' => true]);
})->with([
    'estado no booleano' => [['status' => 'quizas'], 'status', 'El estado debe ser verdadero o falso'],
    'nombre no textual' => [['name' => ['fresa']], 'name', 'El nombre del producto debe ser texto'],
    'nombre vacío' => [['name' => ''], 'name', 'Debe enviar al menos el nombre o el estado'],
    'nombre de más de 255 caracteres' => [
        ['name' => str_repeat('a', 256)],
        'name',
        'El nombre del producto no puede superar los 255 caracteres',
    ],
]);

it('no reescribe registered_by al actualizar el producto', function () {
    $ana = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Ana Administradora']);
    $beto = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Beto Administrador']);

    $product = Product::factory()->create(['registered_by' => $ana->id]);

    asUser($beto)->patchJson("/api/products/{$product->id}", ['name' => 'fresa'])
        ->assertOk()
        ->assertJsonPath('data.registeredByName', 'Ana Administradora');

    $this->assertDatabaseHas('products', ['id' => $product->id, 'registered_by' => $ana->id]);
});

it('devuelve un updatedAt posterior al createdAt tras actualizar', function () {
    $product = Product::factory()->create([
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    $response = asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/products/{$product->id}", ['name' => 'fresa']);

    $formato = 'd-m-Y h:i:s A';

    $createdAt = Carbon::createFromFormat($formato, $response->assertOk()->json('data.createdAt'));
    $updatedAt = Carbon::createFromFormat($formato, $response->json('data.updatedAt'));

    expect($updatedAt->greaterThan($createdAt))->toBeTrue();
});

it('devuelve 404 al actualizar un producto que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))->patchJson('/api/products/99999', ['name' => 'fresa'])
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El producto no existe',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| PATCH /api/products/{product}/toggle-status
|--------------------------------------------------------------------------
*/

it('invierte el estado del producto sin recibir body', function (bool $inicial) {
    $product = Product::factory()->{$inicial ? 'active' : 'inactive'}()->create(['name' => 'BROCOLI']);

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/products/{$product->id}/toggle-status")
        ->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Estado del producto actualizado correctamente',
            'data' => [
                'id' => $product->id,
                'name' => 'BROCOLI',
                'status' => ! $inicial,
            ],
        ]);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => ! $inicial]);
})->with([
    'activo pasa a inactivo' => [true],
    'inactivo pasa a activo' => [false],
]);

it('devuelve el producto a su estado inicial con dos toggle seguidos', function () {
    $product = Product::factory()->active()->create();

    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->patchJson("/api/products/{$product->id}/toggle-status")
        ->assertOk()
        ->assertJsonPath('data.status', false);

    asUser($admin)->patchJson("/api/products/{$product->id}/toggle-status")
        ->assertOk()
        ->assertJsonPath('data.status', true);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => true]);
});

it('no deja que el comodín {product} capture la ruta toggle-status', function () {
    $product = Product::factory()->active()->create();

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/products/{$product->id}/toggle-status")
        ->assertOk()
        ->assertJsonPath('message', 'Estado del producto actualizado correctamente');
});

it('devuelve 404 al invertir el estado de un producto que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))->patchJson('/api/products/99999/toggle-status')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El producto no existe',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| DELETE /api/products/{product}
|--------------------------------------------------------------------------
*/

it('da de baja lógicamente el producto y deja la fila viva', function () {
    $product = Product::factory()->active()->create(['name' => 'BROCOLI']);

    asUser(userWithRole(UserRole::Administrator))->deleteJson("/api/products/{$product->id}")
        ->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Producto dado de baja correctamente',
            'data' => [
                'id' => $product->id,
                'name' => 'BROCOLI',
                'status' => false,
            ],
        ]);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'BROCOLI', 'status' => false]);
    $this->assertDatabaseCount('products', 1);
});

it('sigue mostrando en el listado sin filtros el producto dado de baja', function () {
    $product = Product::factory()->active()->create();

    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->deleteJson("/api/products/{$product->id}")->assertOk();

    asUser($admin)->getJson('/api/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $product->id)
        ->assertJsonPath('data.0.status', false);
});

it('responde 200 al dar de baja un producto que ya estaba inactivo', function () {
    $product = Product::factory()->inactive()->create(['name' => 'BROCOLI']);

    asUser(userWithRole(UserRole::Administrator))->deleteJson("/api/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.status', false);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'BROCOLI', 'status' => false]);
});

it('es idempotente al repetir la baja del mismo producto', function () {
    $product = Product::factory()->active()->create();

    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->deleteJson("/api/products/{$product->id}")->assertOk()->assertJsonPath('data.status', false);
    asUser($admin)->deleteJson("/api/products/{$product->id}")->assertOk()->assertJsonPath('data.status', false);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => false]);
    $this->assertDatabaseCount('products', 1);
});

it('reactiva con toggle-status un producto dado de baja', function () {
    $product = Product::factory()->active()->create();

    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->deleteJson("/api/products/{$product->id}")->assertOk();
    asUser($admin)->patchJson("/api/products/{$product->id}/toggle-status")
        ->assertOk()
        ->assertJsonPath('data.status', true);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => true]);
});

it('devuelve 404 al dar de baja un producto que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))->deleteJson('/api/products/99999')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El producto no existe',
            'data' => null,
        ]);
});
