<?php

use App\Enums\FuelPriceStatus;
use App\Enums\FuelType;
use App\Enums\UserRole;
use App\Models\FuelPrice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Every fuel prices endpoint as method and URI, for the middleware datasets.
 *
 * @return array<string, array{string, string}>
 */
function fuelPriceEndpoints(): array
{
    return [
        'index' => ['GET', '/api/fuel-prices'],
        'current' => ['GET', '/api/fuel-prices/current'],
        'store' => ['POST', '/api/fuel-prices'],
        'show' => ['GET', '/api/fuel-prices/1'],
        'update' => ['PATCH', '/api/fuel-prices/1'],
        'deactivate' => ['PATCH', '/api/fuel-prices/1/deactivate'],
        'destroy' => ['DELETE', '/api/fuel-prices/1'],
    ];
}

/**
 * The four endpoints restricted to the administrator.
 *
 * @return array<string, array{string, string}>
 */
function fuelPriceWriteEndpoints(): array
{
    return [
        'store' => ['POST', '/api/fuel-prices'],
        'update' => ['PATCH', '/api/fuel-prices/1'],
        'deactivate' => ['PATCH', '/api/fuel-prices/1/deactivate'],
        'destroy' => ['DELETE', '/api/fuel-prices/1'],
    ];
}

/**
 * The roles that may read the catalogue but never write it.
 *
 * @return array<string, UserRole>
 */
function fuelPriceNonAdminRoles(): array
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
 * A valid store payload.
 *
 * @return array<string, mixed>
 */
function validFuelPricePayload(array $overrides = []): array
{
    return array_merge([
        'fuelType' => FuelType::Diesel->value,
        'price' => 34.50,
    ], $overrides);
}

/**
 * How many active rows each fuel type has right now.
 *
 * @return array<string, int>
 */
function activeFuelPricesByType(): array
{
    return FuelPrice::query()
        ->where('status', '=', FuelPriceStatus::Active->value)
        ->get()
        ->countBy(fn (FuelPrice $fuelPrice) => $fuelPrice->fuel_type->value)
        ->all();
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth y role
|--------------------------------------------------------------------------
*/

it('rechaza con 401 cualquier endpoint de precios sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(fuelPriceEndpoints());

it('rechaza con 403 a quien no es administrador en los endpoints de escritura', function (string $method, string $uri, UserRole $role) {
    asUser(userWithRole($role))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(fuelPriceWriteEndpoints())->with(fuelPriceNonAdminRoles());

it('no crea, modifica ni borra nada cuando un no administrador intenta escribir', function (UserRole $role) {
    $fuelPrice = FuelPrice::factory()->active()->create([
        'fuel_type' => FuelType::Diesel,
        'price' => 34.50,
    ]);

    $user = userWithRole($role);

    asUser($user)->postJson('/api/fuel-prices', validFuelPricePayload(['price' => 99.99]))->assertForbidden();
    asUser($user)->patchJson("/api/fuel-prices/{$fuelPrice->id}", ['price' => 99.99])->assertForbidden();
    asUser($user)->patchJson("/api/fuel-prices/{$fuelPrice->id}/deactivate")->assertForbidden();
    asUser($user)->deleteJson("/api/fuel-prices/{$fuelPrice->id}")->assertForbidden();

    $this->assertDatabaseCount('fuel_prices', 1);
    $this->assertDatabaseHas('fuel_prices', [
        'id' => $fuelPrice->id,
        'price' => 34.50,
        'status' => 'active',
    ]);
})->with(fuelPriceNonAdminRoles());

it('deja leer el catálogo a cualquier autenticado sin exigirle empresa', function (UserRole $role) {
    $fuelPrice = FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel]);

    /** Ninguno de estos usuarios está vinculado a una empresa: el precio es un dato nacional. */
    $user = userWithRole($role);

    asUser($user)->getJson('/api/fuel-prices')->assertOk()->assertJsonCount(1, 'data');
    asUser($user)->getJson('/api/fuel-prices/current?fuelType=diesel')->assertOk();
    asUser($user)->getJson("/api/fuel-prices/{$fuelPrice->id}")->assertOk();
})->with(fuelPriceNonAdminRoles() + ['administrator' => UserRole::Administrator]);

/*
|--------------------------------------------------------------------------
| POST /api/fuel-prices
|--------------------------------------------------------------------------
*/

it('registra un precio y lo deja vigente con 201', function () {
    $admin = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Ana Administradora']);

    $response = asUser($admin)->postJson('/api/fuel-prices', validFuelPricePayload());

    $response->assertCreated()
        ->assertJson([
            'statusCode' => 201,
            'message' => 'Precio de combustible registrado correctamente',
            'data' => [
                'fuelType' => 'diesel',
                'price' => '34.50',
                'status' => 'active',
                'registeredByName' => 'Ana Administradora',
            ],
        ]);

    expect(array_keys($response->json('data')))
        ->toEqualCanonicalizing(['id', 'fuelType', 'price', 'status', 'registeredByName', 'createdAt']);

    $this->assertDatabaseHas('fuel_prices', [
        'fuel_type' => 'diesel',
        'price' => 34.50,
        'status' => 'active',
        'registered_by' => $admin->id,
    ]);
});

it('desactiva el precio anterior del mismo tipo al registrar uno nuevo', function () {
    $admin = userWithRole(UserRole::Administrator);

    $anterior = FuelPrice::factory()->active()->create([
        'fuel_type' => FuelType::Diesel,
        'price' => 33.00,
    ]);

    asUser($admin)->postJson('/api/fuel-prices', validFuelPricePayload(['price' => 34.50]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('fuel_prices', ['id' => $anterior->id, 'status' => 'inactive']);

    expect(activeFuelPricesByType())->toBe(['diesel' => 1]);
});

it('no toca el status de los precios vigentes de otros tipos', function () {
    $admin = userWithRole(UserRole::Administrator);

    $otros = collect([FuelType::Regular, FuelType::Premium, FuelType::DieselPremium])
        ->map(fn (FuelType $type) => FuelPrice::factory()->active()->create(['fuel_type' => $type]));

    FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel]);

    asUser($admin)->postJson('/api/fuel-prices', validFuelPricePayload())->assertCreated();

    foreach ($otros as $fuelPrice) {
        $this->assertDatabaseHas('fuel_prices', ['id' => $fuelPrice->id, 'status' => 'active']);
    }

    expect(activeFuelPricesByType())->toEqual([
        'regular' => 1,
        'premium' => 1,
        'diesel_premium' => 1,
        'diesel' => 1,
    ]);
});

it('deja como mucho un vigente por tipo tras una secuencia de altas', function () {
    $admin = userWithRole(UserRole::Administrator);

    foreach ([FuelType::Diesel, FuelType::Regular, FuelType::Diesel, FuelType::Premium, FuelType::Diesel] as $type) {
        asUser($admin)->postJson('/api/fuel-prices', validFuelPricePayload(['fuelType' => $type->value]))
            ->assertCreated();
    }

    $this->assertDatabaseCount('fuel_prices', 5);

    expect(activeFuelPricesByType())->toEqual([
        'diesel' => 1,
        'regular' => 1,
        'premium' => 1,
    ]);
});

it('toma registered_by del usuario autenticado aunque el body mande otro', function () {
    $admin = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Ana Administradora']);
    $otro = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/fuel-prices', validFuelPricePayload([
        'registeredBy' => $otro->id,
        'registered_by' => $otro->id,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.registeredByName', 'Ana Administradora');

    $this->assertDatabaseHas('fuel_prices', ['registered_by' => $admin->id]);
    $this->assertDatabaseMissing('fuel_prices', ['registered_by' => $otro->id]);
});

it('ignora el status del body al registrar y nace siempre vigente', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/fuel-prices', validFuelPricePayload(['status' => FuelPriceStatus::Inactive->value]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('fuel_prices', ['fuel_type' => 'diesel', 'status' => 'active']);
});

it('valida los campos obligatorios al registrar un precio', function (string $field, string $message) {
    $payload = validFuelPricePayload();
    unset($payload[$field]);

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/fuel-prices', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field])
        ->assertJsonPath("errors.{$field}.0", $message);

    $this->assertDatabaseCount('fuel_prices', 0);
})->with([
    'sin tipo de combustible' => ['fuelType', 'El tipo de combustible es obligatorio'],
    'sin precio' => ['price', 'El precio es obligatorio'],
]);

it('rechaza con 422 los valores inválidos al registrar un precio', function (array $overrides, string $field, string $message) {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/fuel-prices', validFuelPricePayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field])
        ->assertJsonPath("errors.{$field}.0", $message);

    $this->assertDatabaseCount('fuel_prices', 0);
})->with([
    'tipo fuera del enum' => [['fuelType' => 'super'], 'fuelType', 'El tipo de combustible no es válido'],
    'precio en cero' => [['price' => 0], 'price', 'El precio debe ser mayor que cero'],
    'precio negativo' => [['price' => -1], 'price', 'El precio debe ser mayor que cero'],
    'precio no numérico' => [['price' => 'carísimo'], 'price', 'El precio debe ser un número en quetzales por galón'],
    'precio por encima del máximo' => [['price' => 1000000], 'price', 'El precio no puede superar los 999999.99 quetzales por galón'],
]);

/*
|--------------------------------------------------------------------------
| GET /api/fuel-prices/current
|--------------------------------------------------------------------------
*/

it('devuelve el único precio vigente del tipo pedido', function () {
    $admin = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Ana Administradora']);

    FuelPrice::factory()->count(2)->inactive()->create(['fuel_type' => FuelType::Diesel]);
    $vigente = FuelPrice::factory()->active()->create([
        'fuel_type' => FuelType::Diesel,
        'price' => 34.50,
        'registered_by' => $admin->id,
    ]);
    FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Regular]);

    asUser($admin)->getJson('/api/fuel-prices/current?fuelType=diesel')
        ->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Precio vigente obtenido correctamente',
            'data' => [
                'id' => $vigente->id,
                'fuelType' => 'diesel',
                'price' => '34.50',
                'status' => 'active',
                'registeredByName' => 'Ana Administradora',
            ],
        ]);
});

it('rechaza con 422 el precio vigente sin fuelType, sin dejar que el comodín capture /current', function () {
    asUser(userWithRole(UserRole::Administrator))->getJson('/api/fuel-prices/current')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fuelType'])
        ->assertJsonPath('errors.fuelType.0', 'El tipo de combustible es obligatorio');
});

it('rechaza con 422 un fuelType fuera del enum al pedir el vigente', function () {
    asUser(userWithRole(UserRole::Administrator))->getJson('/api/fuel-prices/current?fuelType=gasolina')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fuelType'])
        ->assertJsonPath('errors.fuelType.0', 'El tipo de combustible no es válido');
});

it('devuelve 404 cuando el tipo pedido no tiene precio vigente', function () {
    FuelPrice::factory()->count(2)->inactive()->create(['fuel_type' => FuelType::Premium]);
    FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel]);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/fuel-prices/current?fuelType=premium')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'No existe un precio vigente para el combustible indicado',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| GET /api/fuel-prices
|--------------------------------------------------------------------------
*/

it('devuelve el histórico con lo más reciente primero', function () {
    $viejo = FuelPrice::factory()->inactive()->create(['created_at' => now()->subDays(2)]);
    $medio = FuelPrice::factory()->inactive()->create(['created_at' => now()->subDay()]);
    $nuevo = FuelPrice::factory()->active()->create(['created_at' => now()]);

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/fuel-prices');

    $response->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Precios de combustible obtenidos correctamente',
        ])
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => [['id', 'fuelType', 'price', 'status', 'registeredByName', 'createdAt']],
        ]);

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe([$nuevo->id, $medio->id, $viejo->id]);
});

it('desempata por id las altas del mismo instante', function () {
    $instante = now();

    $primero = FuelPrice::factory()->inactive()->create(['created_at' => $instante]);
    $segundo = FuelPrice::factory()->inactive()->create(['created_at' => $instante]);
    $tercero = FuelPrice::factory()->active()->create(['created_at' => $instante]);

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/fuel-prices');

    expect(collect($response->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$tercero->id, $segundo->id, $primero->id]);
});

it('filtra el listado por fuelType y status a la vez', function () {
    $buscado = FuelPrice::factory()->inactive()->create(['fuel_type' => FuelType::Diesel]);

    FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel]);
    FuelPrice::factory()->inactive()->create(['fuel_type' => FuelType::Regular]);
    FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Regular]);

    asUser(userWithRole(UserRole::Administrator))
        ->getJson('/api/fuel-prices?fuelType=diesel&status=inactive')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $buscado->id)
        ->assertJsonPath('data.0.fuelType', 'diesel')
        ->assertJsonPath('data.0.status', 'inactive');
});

it('ignora sin error un filtro que no pertenece a su enum', function (string $query) {
    FuelPrice::factory()->count(3)->create();

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/fuel-prices?{$query}")
        ->assertOk()
        ->assertJsonCount(3, 'data');
})->with([
    'status fuera del enum' => ['status=vencido'],
    'fuelType fuera del enum' => ['fuelType=gasolina'],
    'ambos fuera del enum' => ['fuelType=gasolina&status=vencido'],
]);

it('devuelve el nombre de quien registró cada precio del listado', function () {
    $ana = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Ana Administradora']);
    $beto = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Beto Administrador']);

    FuelPrice::factory()->create(['registered_by' => $ana->id]);
    FuelPrice::factory()->create(['registered_by' => $beto->id]);

    $response = asUser($ana)->getJson('/api/fuel-prices');

    expect(collect($response->assertOk()->json('data'))->pluck('registeredByName')->all())
        ->toEqualCanonicalizing(['Ana Administradora', 'Beto Administrador']);
});

it('no dispara N+1 al listar precios de muchos registradores', function () {
    FuelPrice::factory()->count(20)->create();

    /** El token se emite antes de escuchar: sus claims consultan la empresa del usuario. */
    $token = JWTAuth::fromUser(userWithRole(UserRole::Administrator));

    resetAuthState();

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withToken($token)->getJson('/api/fuel-prices')->assertOk()->assertJsonCount(20, 'data');

    $sobrePrecios = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "fuel_prices"'));
    $sobreUsuarios = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "users"'));

    /** Una consulta por el listado y otra por la relación: la del usuario autenticado es aparte. */
    expect($sobrePrecios)->toHaveCount(1)
        ->and($sobreUsuarios->count())->toBeLessThanOrEqual(2);
});

/*
|--------------------------------------------------------------------------
| Paginación
|--------------------------------------------------------------------------
*/

it('devuelve todos los precios y ninguna clave de paginación sin limit', function () {
    FuelPrice::factory()->count(12)->create();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/fuel-prices');

    $response->assertOk()->assertJsonCount(12, 'data');

    expect($response->json())->not->toHaveKeys(['total', 'currentPage', 'lastPage']);
});

it('devuelve el sobre paginado de precios con limit numérico', function () {
    FuelPrice::factory()->count(12)->create();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/fuel-prices?limit=10')
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
            'data' => [['id', 'fuelType', 'price', 'status', 'registeredByName', 'createdAt']],
            'total',
            'currentPage',
            'lastPage',
        ]);
});

it('devuelve todos los precios sin error cuando limit no es numérico', function () {
    FuelPrice::factory()->count(12)->create();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/fuel-prices?limit=abc');

    $response->assertOk()->assertJsonCount(12, 'data');

    expect($response->json())->not->toHaveKey('total');
});

it('acota el limit inferior de precios a 10 por página', function () {
    FuelPrice::factory()->count(12)->create();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/fuel-prices?limit=3')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('lastPage', 2);
});

it('acota el limit superior de precios a 100 por página', function () {
    FuelPrice::factory()->count(101)->create();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/fuel-prices?limit=500')
        ->assertOk()
        ->assertJsonCount(100, 'data')
        ->assertJsonPath('total', 101)
        ->assertJsonPath('lastPage', 2);
});

it('aplica a la vez el filtro de tipo y la paginación', function () {
    FuelPrice::factory()->count(12)->inactive()->create(['fuel_type' => FuelType::Diesel]);
    FuelPrice::factory()->count(5)->inactive()->create(['fuel_type' => FuelType::Regular]);

    $response = asUser(userWithRole(UserRole::Administrator))
        ->getJson('/api/fuel-prices?fuelType=diesel&limit=10');

    $response->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('total', 12)
        ->assertJsonPath('lastPage', 2);

    expect(collect($response->json('data'))->pluck('fuelType')->unique()->values()->all())->toBe(['diesel']);
});

/*
|--------------------------------------------------------------------------
| GET /api/fuel-prices/{fuelPrice}
|--------------------------------------------------------------------------
*/

it('devuelve exactamente las seis claves del recurso al pedir un precio', function () {
    $admin = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Ana Administradora']);

    $fuelPrice = FuelPrice::factory()->active()->create([
        'fuel_type' => FuelType::DieselPremium,
        'price' => 40.75,
        'registered_by' => $admin->id,
    ]);

    $response = asUser($admin)->getJson("/api/fuel-prices/{$fuelPrice->id}");

    $response->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Precio de combustible obtenido correctamente',
            'data' => [
                'id' => $fuelPrice->id,
                'fuelType' => 'diesel_premium',
                'price' => '40.75',
                'status' => 'active',
                'registeredByName' => 'Ana Administradora',
            ],
        ]);

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and(array_keys($response->json('data')))
        ->toEqualCanonicalizing(['id', 'fuelType', 'price', 'status', 'registeredByName', 'createdAt']);
});

it('devuelve también los precios del histórico ya desplazados', function () {
    $fuelPrice = FuelPrice::factory()->inactive()->create();

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/fuel-prices/{$fuelPrice->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $fuelPrice->id)
        ->assertJsonPath('data.status', 'inactive');
});

it('devuelve el precio con dos decimales aunque venga entero', function () {
    $fuelPrice = FuelPrice::factory()->active()->create(['price' => 35]);

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/fuel-prices/{$fuelPrice->id}")
        ->assertOk()
        ->assertJsonPath('data.price', '35.00');
});

it('devuelve 404 al pedir un precio que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))->getJson('/api/fuel-prices/99999')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El precio de combustible no existe',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| PUT|PATCH /api/fuel-prices/{fuelPrice}
|--------------------------------------------------------------------------
*/

it('actualiza el precio de la fila vigente', function () {
    $fuelPrice = FuelPrice::factory()->active()->create([
        'fuel_type' => FuelType::Diesel,
        'price' => 34.50,
    ]);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/fuel-prices/{$fuelPrice->id}", ['price' => 35.00])
        ->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Precio de combustible actualizado correctamente',
            'data' => [
                'id' => $fuelPrice->id,
                'price' => '35.00',
                'status' => 'active',
                'fuelType' => 'diesel',
            ],
        ]);

    $this->assertDatabaseHas('fuel_prices', ['id' => $fuelPrice->id, 'price' => 35.00, 'status' => 'active']);
});

it('ignora fuelType y status en el body del update y solo cambia el precio', function () {
    $fuelPrice = FuelPrice::factory()->active()->create([
        'fuel_type' => FuelType::Diesel,
        'price' => 34.50,
    ]);

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/fuel-prices/{$fuelPrice->id}", [
        'price' => 35.00,
        'fuelType' => FuelType::Premium->value,
        'status' => FuelPriceStatus::Inactive->value,
    ])
        ->assertOk()
        ->assertJsonPath('data.fuelType', 'diesel')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.price', '35.00');

    $this->assertDatabaseHas('fuel_prices', [
        'id' => $fuelPrice->id,
        'fuel_type' => 'diesel',
        'status' => 'active',
        'price' => 35.00,
    ]);
});

it('no reescribe registered_by al actualizar el precio', function () {
    $ana = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Ana Administradora']);
    $beto = User::factory()->create(['role' => UserRole::Administrator, 'name' => 'Beto Administrador']);

    $fuelPrice = FuelPrice::factory()->active()->create(['registered_by' => $ana->id]);

    asUser($beto)->patchJson("/api/fuel-prices/{$fuelPrice->id}", ['price' => 35.00])
        ->assertOk()
        ->assertJsonPath('data.registeredByName', 'Ana Administradora');

    $this->assertDatabaseHas('fuel_prices', ['id' => $fuelPrice->id, 'registered_by' => $ana->id]);
});

it('rechaza con 400 actualizar una fila del histórico', function () {
    $fuelPrice = FuelPrice::factory()->inactive()->create(['price' => 33.00]);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/fuel-prices/{$fuelPrice->id}", ['price' => 35.00])
        ->assertBadRequest()
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'Solo se puede modificar el precio vigente',
            'data' => null,
        ]);

    $this->assertDatabaseHas('fuel_prices', ['id' => $fuelPrice->id, 'price' => 33.00, 'status' => 'inactive']);
});

it('rechaza con 422 un update sin precio', function (array $payload) {
    $fuelPrice = FuelPrice::factory()->active()->create(['price' => 34.50]);

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/fuel-prices/{$fuelPrice->id}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['price']);

    $this->assertDatabaseHas('fuel_prices', ['id' => $fuelPrice->id, 'price' => 34.50]);
})->with([
    'body vacío' => [[]],
    'solo fuelType' => [['fuelType' => 'premium']],
    'precio en cero' => [['price' => 0]],
    'precio negativo' => [['price' => -1]],
    'precio no numérico' => [['price' => 'carísimo']],
]);

it('devuelve 404 al actualizar un precio que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->patchJson('/api/fuel-prices/99999', ['price' => 35.00])
        ->assertNotFound()
        ->assertJson(['message' => 'El precio de combustible no existe']);
});

/*
|--------------------------------------------------------------------------
| PATCH /api/fuel-prices/{fuelPrice}/deactivate
|--------------------------------------------------------------------------
*/

it('desactiva la fila vigente y deja su tipo sin precio', function () {
    $fuelPrice = FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel]);

    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->patchJson("/api/fuel-prices/{$fuelPrice->id}/deactivate")
        ->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Precio de combustible desactivado correctamente',
            'data' => [
                'id' => $fuelPrice->id,
                'status' => 'inactive',
            ],
        ]);

    $this->assertDatabaseHas('fuel_prices', ['id' => $fuelPrice->id, 'status' => 'inactive']);

    asUser($admin)->getJson('/api/fuel-prices/current?fuelType=diesel')
        ->assertNotFound()
        ->assertJson(['message' => 'No existe un precio vigente para el combustible indicado']);
});

it('no asciende ninguna fila inactiva al desactivar la vigente', function () {
    $historico = FuelPrice::factory()->count(3)->inactive()->create(['fuel_type' => FuelType::Diesel]);
    $vigente = FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel]);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/fuel-prices/{$vigente->id}/deactivate")
        ->assertOk();

    expect(activeFuelPricesByType())->toBe([]);

    foreach ($historico as $fuelPrice) {
        $this->assertDatabaseHas('fuel_prices', ['id' => $fuelPrice->id, 'status' => 'inactive']);
    }
});

it('rechaza con 400 desactivar una fila que ya está en el histórico', function () {
    $fuelPrice = FuelPrice::factory()->inactive()->create();

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/fuel-prices/{$fuelPrice->id}/deactivate")
        ->assertBadRequest()
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'Solo se puede modificar el precio vigente',
            'data' => null,
        ]);

    $this->assertDatabaseHas('fuel_prices', ['id' => $fuelPrice->id, 'status' => 'inactive']);
});

it('devuelve 404 al desactivar un precio que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->patchJson('/api/fuel-prices/99999/deactivate')
        ->assertNotFound()
        ->assertJson(['message' => 'El precio de combustible no existe']);
});

/*
|--------------------------------------------------------------------------
| DELETE /api/fuel-prices/{fuelPrice}
|--------------------------------------------------------------------------
*/

it('borra de verdad la fila vigente y devuelve el recurso eliminado', function () {
    $fuelPrice = FuelPrice::factory()->active()->create([
        'fuel_type' => FuelType::Diesel,
        'price' => 34.50,
    ]);

    asUser(userWithRole(UserRole::Administrator))->deleteJson("/api/fuel-prices/{$fuelPrice->id}")
        ->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Precio de combustible eliminado correctamente',
            'data' => [
                'id' => $fuelPrice->id,
                'fuelType' => 'diesel',
                'price' => '34.50',
            ],
        ]);

    $this->assertDatabaseMissing('fuel_prices', ['id' => $fuelPrice->id]);
    $this->assertDatabaseCount('fuel_prices', 0);
});

it('deja el tipo sin vigente tras borrar su precio', function () {
    $fuelPrice = FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel]);

    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->deleteJson("/api/fuel-prices/{$fuelPrice->id}")->assertOk();

    asUser($admin)->getJson('/api/fuel-prices/current?fuelType=diesel')
        ->assertNotFound()
        ->assertJson(['message' => 'No existe un precio vigente para el combustible indicado']);
});

it('no asciende ninguna fila inactiva al borrar la vigente', function () {
    $historico = FuelPrice::factory()->count(3)->inactive()->create(['fuel_type' => FuelType::Diesel]);
    $vigente = FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel]);

    asUser(userWithRole(UserRole::Administrator))->deleteJson("/api/fuel-prices/{$vigente->id}")->assertOk();

    expect(activeFuelPricesByType())->toBe([]);

    $this->assertDatabaseCount('fuel_prices', 3);

    foreach ($historico as $fuelPrice) {
        $this->assertDatabaseHas('fuel_prices', ['id' => $fuelPrice->id, 'status' => 'inactive']);
    }
});

it('rechaza con 400 borrar una fila del histórico y la deja intacta', function () {
    $fuelPrice = FuelPrice::factory()->inactive()->create();

    asUser(userWithRole(UserRole::Administrator))->deleteJson("/api/fuel-prices/{$fuelPrice->id}")
        ->assertBadRequest()
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'Solo se puede modificar el precio vigente',
            'data' => null,
        ]);

    $this->assertDatabaseHas('fuel_prices', ['id' => $fuelPrice->id, 'status' => 'inactive']);
    $this->assertDatabaseCount('fuel_prices', 1);
});

it('devuelve 404 al borrar un precio que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))->deleteJson('/api/fuel-prices/99999')
        ->assertNotFound()
        ->assertJson(['message' => 'El precio de combustible no existe']);
});
