<?php

use App\Enums\FuelPriceStatus;
use App\Enums\FuelType;
use App\Enums\UserRole;
use App\Models\FreightRate;
use App\Models\FuelPrice;
use App\Models\Product;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Every freight rates endpoint as method and URI, for the middleware datasets.
 *
 * @return array<string, array{string, string}>
 */
function freightRateEndpoints(): array
{
    return [
        'index' => ['GET', '/api/freight-rates'],
        'quote' => ['GET', '/api/freight-rates/quote'],
        'store' => ['POST', '/api/freight-rates'],
        'show' => ['GET', '/api/freight-rates/1'],
        'update' => ['PATCH', '/api/freight-rates/1'],
        'destroy' => ['DELETE', '/api/freight-rates/1'],
    ];
}

/**
 * The four endpoints restricted to the administrator, the detail included.
 *
 * @return array<string, array{string, string}>
 */
function freightRateAdminEndpoints(): array
{
    return [
        'store' => ['POST', '/api/freight-rates'],
        'show' => ['GET', '/api/freight-rates/1'],
        'update' => ['PATCH', '/api/freight-rates/1'],
        'destroy' => ['DELETE', '/api/freight-rates/1'],
    ];
}

/**
 * The roles that may read the price table but never write it.
 *
 * @return array<string, UserRole>
 */
function freightRateNonAdminRoles(): array
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
     * The JWT singletons survive between calls of the same test, so the guard state is
     * dropped before handing the fresh token over.
     */
    function asUser(User $user): TestCase
    {
        resetAuthState();

        return test()->withToken(JWTAuth::fromUser($user))->withHeader('Accept', 'application/json');
    }
}

/**
 * Un cuadrado de un grado con esquina inferior izquierda en el punto dado.
 *
 * @return array<int, array{0: float, 1: float}>
 */
function freightRateSquarePairs(float $latitude, float $longitude): array
{
    return [
        [$latitude, $longitude],
        [$latitude + 1.0, $longitude],
        [$latitude + 1.0, $longitude + 1.0],
        [$latitude, $longitude + 1.0],
    ];
}

/**
 * Las once claves que promete FreightRateResource, en el orden en que las declara.
 *
 * @return array<int, string>
 */
function freightRateResourceKeys(): array
{
    return [
        'id', 'zoneId', 'zoneName', 'productId', 'productName', 'fuelType',
        'fuelMin', 'pricePerPound', 'registeredByName', 'createdAt', 'updatedAt',
    ];
}

/**
 * Las once claves que promete FreightQuoteResource, en el orden en que las declara.
 *
 * @return array<int, string>
 */
function freightQuoteResourceKeys(): array
{
    return [
        'freightRateId', 'zoneId', 'zoneName', 'productId', 'productName', 'fuelType',
        'currentFuelPrice', 'appliedFuelMin', 'pricePerPound', 'pounds', 'total',
    ];
}

/**
 * La expresión del formato de fecha `d-m-Y h:i:s A` que promete el FreightRateResource.
 */
function freightRateDatePattern(): string
{
    return '/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} (AM|PM)$/';
}

/**
 * El cuerpo de un alta por HTTP, con la zona y el producto ya creados.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function freightRateApiBody(Zone $zone, Product $product, array $overrides = []): array
{
    return array_merge([
        'zoneId' => $zone->id,
        'productId' => $product->id,
        'fuelType' => FuelType::Diesel->value,
        'fuelMin' => 30.00,
        'pricePerPound' => 0.454120,
    ], $overrides);
}

/**
 * El escenario completo de una cotización por HTTP: zona con polígono, producto y diésel vigente.
 *
 * @param  array<int, array{0: float, 1: float}>  $bands  pares [fuelMin, pricePerPound].
 * @return array{zone: Zone, product: Product}
 */
function freightRateApiScenario(float $currentFuelPrice, array $bands): array
{
    $zone = Zone::factory()->active()->withArea(freightRateSquarePairs(14.0, -91.0))->create();
    $product = Product::factory()->active()->create();

    FuelPrice::factory()->active()->create([
        'fuel_type' => FuelType::Diesel,
        'price' => $currentFuelPrice,
    ]);

    foreach ($bands as [$fuelMin, $pricePerPound]) {
        FreightRate::factory()->create([
            'zone_id' => $zone->id,
            'product_id' => $product->id,
            'fuel_type' => FuelType::Diesel,
            'fuel_min' => $fuelMin,
            'price_per_pound' => $pricePerPound,
        ]);
    }

    return ['zone' => $zone, 'product' => $product];
}

/**
 * La query de la cotización, con el punto dentro de la zona del escenario.
 *
 * @param  array<string, mixed>  $overrides
 */
function freightRateQuoteUri(Product $product, array $overrides = []): string
{
    $params = array_merge([
        'lat' => 14.5,
        'lng' => -90.5,
        'productId' => $product->id,
        'fuelType' => FuelType::Diesel->value,
    ], $overrides);

    return '/api/freight-rates/quote?'.http_build_query(array_filter(
        $params,
        fn (mixed $value) => $value !== null,
    ));
}

/*
|--------------------------------------------------------------------------
| Ruteo y autorización
|--------------------------------------------------------------------------
*/

it('responde 401 sin token en las seis rutas', function (string $method, string $uri) {
    test()->withHeader('Accept', 'application/json')->json($method, $uri)
        ->assertStatus(401)
        ->assertJsonStructure(['statusCode', 'message']);
})->with(freightRateEndpoints());

it('responde 403 en las rutas de administrador con los otros tres roles', function (string $method, string $uri) {
    foreach (freightRateNonAdminRoles() as $role) {
        asUser(userWithRole($role))->json($method, $uri)->assertStatus(403);
    }
})->with(freightRateAdminEndpoints());

it('abre el listado a cualquier autenticado, sin exigir empresa', function (UserRole $role) {
    asUser(userWithRole($role))->getJson('/api/freight-rates')->assertStatus(200);
})->with(freightRateNonAdminRoles());

it('resuelve /quote como ruta fija y no como un id de tarifa', function () {
    $zone = Zone::factory()->active()->withArea(freightRateSquarePairs(14.0, -91.0))->create();
    $product = Product::factory()->active()->create();

    FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel, 'price' => 40.00]);

    FreightRate::factory()->create([
        'zone_id' => $zone->id,
        'product_id' => $product->id,
        'fuel_type' => FuelType::Diesel,
        'fuel_min' => 35.00,
        'price_per_pound' => 0.454120,
    ]);

    asUser(userWithRole(UserRole::Pilot))
        ->getJson('/api/freight-rates/quote?lat=14.5&lng=-90.5&productId='.$product->id.'&fuelType=diesel&pounds=45000')
        ->assertStatus(200)
        ->assertJsonPath('data.pricePerPound', '0.454120')
        ->assertJsonPath('data.currentFuelPrice', '40.00')
        ->assertJsonPath('data.appliedFuelMin', '35.00')
        ->assertJsonPath('data.total', '20435.40');
});

it('registra una tarifa como administrador', function () {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)
        ->postJson('/api/freight-rates', [
            'zoneId' => $zone->id,
            'productId' => $product->id,
            'fuelType' => 'diesel',
            'fuelMin' => 30.00,
            'pricePerPound' => 0.454120,
        ])
        ->assertStatus(201)
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Tarifa registrada correctamente')
        ->assertJsonPath('data.fuelMin', '30.00')
        ->assertJsonPath('data.pricePerPound', '0.454120')
        ->assertJsonPath('data.zoneName', $zone->name)
        ->assertJsonPath('data.productName', $product->name)
        ->assertJsonPath('data.registeredByName', $admin->name);

    $this->assertDatabaseHas('freight_rates', [
        'zone_id' => $zone->id,
        'product_id' => $product->id,
        'fuel_type' => 'diesel',
        'fuel_min' => '30.00',
        'price_per_pound' => '0.454120',
        'registered_by' => $admin->id,
        'deleted_at' => null,
    ]);
});

it('abre la cotización a cualquier autenticado, sin exigir empresa', function (UserRole $role) {
    ['product' => $product] = freightRateApiScenario(40.00, [[35.00, 0.454120]]);

    asUser(userWithRole($role))->getJson(freightRateQuoteUri($product))
        ->assertOk()
        ->assertJsonPath('message', 'Cotización obtenida correctamente');
})->with(freightRateNonAdminRoles());

/*
|--------------------------------------------------------------------------
| Listado
|--------------------------------------------------------------------------
*/

it('lista las tarifas de todas las zonas en el sobre estándar', function () {
    FreightRate::factory()->count(3)->create();

    $response = asUser(userWithRole(UserRole::Manager))->getJson('/api/freight-rates')->assertOk();

    expect($response->json('statusCode'))->toBe(200)
        ->and($response->json('message'))->toBe('Tarifas obtenidas correctamente')
        ->and($response->json('data'))->toHaveCount(3);
});

it('filtra el listado por zona e ignora cualquier otro query param', function () {
    $zone = Zone::factory()->active()->create();
    FreightRate::factory()->count(2)->create(['zone_id' => $zone->id]);
    FreightRate::factory()->create();

    $user = userWithRole(UserRole::Carrier);

    expect(asUser($user)->getJson("/api/freight-rates?zoneId={$zone->id}")->assertOk()->json('data'))->toHaveCount(2)
        /** Una zona que no existe no es un error: es una tabla de precios vacía. */
        ->and(asUser($user)->getJson('/api/freight-rates?zoneId=9999')->assertOk()->json('data'))->toBe([])
        ->and(asUser($user)->getJson('/api/freight-rates?zoneId=norte')->assertOk()->json('data'))->toHaveCount(3)
        /** Un zoneId en forma de array llega como null al service y tampoco vacía la tabla. */
        ->and(asUser($user)->getJson('/api/freight-rates?zoneId[]=1&productId=3')->assertOk()->json('data'))->toHaveCount(3);
});

it('devuelve la colección completa aunque llegue limit, sin metadatos de paginación', function () {
    FreightRate::factory()->count(12)->create();

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/freight-rates?limit=10')->assertOk();

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data'])
        ->and($response->json('data'))->toHaveCount(12);
});

it('ordena el listado por combustible y, dentro de cada uno, por banda ascendente', function () {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();

    foreach ([[FuelType::Regular, 20], [FuelType::Diesel, 35], [FuelType::Diesel, 28]] as [$fuelType, $fuelMin]) {
        FreightRate::factory()->create([
            'zone_id' => $zone->id,
            'product_id' => $product->id,
            'fuel_type' => $fuelType,
            'fuel_min' => $fuelMin,
        ]);
    }

    $data = asUser(userWithRole(UserRole::Pilot))->getJson('/api/freight-rates')->assertOk()->json('data');

    expect(array_column($data, 'fuelType'))->toBe(['diesel', 'diesel', 'regular'])
        ->and(array_column($data, 'fuelMin'))->toBe(['28.00', '35.00', '20.00']);
});

it('devuelve las once claves en camelCase con las fechas de la spec', function () {
    $rate = FreightRate::factory()->create();
    $admin = userWithRole(UserRole::Administrator);

    $detalle = asUser($admin)->getJson("/api/freight-rates/{$rate->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Tarifa obtenida correctamente')
        ->json('data');

    $delListado = asUser($admin)->getJson('/api/freight-rates')->assertOk()->json('data.0');

    expect(array_keys($detalle))->toBe(freightRateResourceKeys())
        ->and(array_keys($delListado))->toBe(freightRateResourceKeys())
        ->and($detalle['createdAt'])->toMatch(freightRateDatePattern())
        ->and($detalle['updatedAt'])->toMatch(freightRateDatePattern())
        ->and($detalle['zoneName'])->toBe($rate->zone->name)
        ->and($detalle['productName'])->toBe($rate->product->name)
        ->and($detalle['registeredByName'])->toBe($rate->registeredBy->name);
});

it('no dispara N+1 al listar veinte tarifas de zonas y productos distintos', function () {
    FreightRate::factory()->count(20)->create();

    /** El token se emite antes de escuchar: sus claims consultan la empresa del usuario. */
    $token = JWTAuth::fromUser(userWithRole(UserRole::Administrator));

    resetAuthState();

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withToken($token)->getJson('/api/freight-rates')->assertOk()->assertJsonCount(20, 'data');

    $sobre = fn (string $table) => collect($queries)
        ->filter(fn (string $sql) => str_contains($sql, "from \"{$table}\""))
        ->count();

    /** Una consulta por el listado y otra por cada relación: la del usuario autenticado es aparte. */
    expect($sobre('freight_rates'))->toBe(1)
        ->and($sobre('zones'))->toBe(1)
        ->and($sobre('products'))->toBe(1)
        ->and($sobre('users'))->toBeLessThanOrEqual(2);
});

/*
|--------------------------------------------------------------------------
| Alta y unicidad de banda
|--------------------------------------------------------------------------
*/

it('rechaza con 400 una segunda tarifa con la misma banda del mismo par', function () {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/freight-rates', freightRateApiBody($zone, $product))->assertCreated();

    asUser($admin)->postJson('/api/freight-rates', freightRateApiBody($zone, $product, ['pricePerPound' => 0.9]))
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'Ya existe una tarifa para esa zona, ese producto y ese combustible desde ese precio',
            'data' => null,
        ]);

    expect(FreightRate::query()->count())->toBe(1);
});

it('acepta por HTTP la misma banda para otro combustible, otra zona u otro producto', function (string $key, string $target) {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/freight-rates', freightRateApiBody($zone, $product))->assertCreated();

    $value = match ($target) {
        'zone' => Zone::factory()->active()->create()->id,
        'product' => Product::factory()->active()->create()->id,
        default => FuelType::Regular->value,
    };

    asUser($admin)->postJson('/api/freight-rates', freightRateApiBody($zone, $product, [$key => $value]))
        ->assertCreated()
        ->assertJsonPath('data.fuelMin', '30.00');

    expect(FreightRate::query()->count())->toBe(2);
})->with([
    'otro combustible' => ['fuelType', 'fuel'],
    'otra zona' => ['zoneId', 'zone'],
    'otro producto' => ['productId', 'product'],
]);

it('vuelve a aceptar la banda después de borrar la tarifa que la ocupaba', function () {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $admin = userWithRole(UserRole::Administrator);

    $id = asUser($admin)->postJson('/api/freight-rates', freightRateApiBody($zone, $product))
        ->assertCreated()
        ->json('data.id');

    asUser($admin)->deleteJson("/api/freight-rates/{$id}")->assertOk();

    asUser($admin)->postJson('/api/freight-rates', freightRateApiBody($zone, $product))
        ->assertCreated()
        ->assertJsonPath('data.fuelMin', '30.00');
});

it('rechaza con 400 el alta sobre una zona o un producto inactivo', function (bool $zoneIsActive, string $message) {
    $zone = Zone::factory()->state(['status' => $zoneIsActive])->create();
    $product = Product::factory()->state(['status' => ! $zoneIsActive])->create();

    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/freight-rates', freightRateApiBody($zone, $product))
        ->assertStatus(400)
        ->assertJsonPath('message', $message);

    expect(FreightRate::query()->count())->toBe(0);
})->with([
    'zona inactiva' => [false, 'La zona seleccionada no está activa'],
    'producto inactivo' => [true, 'El producto seleccionado no está activo'],
]);

it('responde 422, y no 400, cuando la zona o el producto no existen', function (string $key) {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();

    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/freight-rates', freightRateApiBody($zone, $product, [$key => 99999]))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$key]);
})->with(['zoneId', 'productId']);

it('responde 422 cuando falta un campo obligatorio del alta', function (string $missing) {
    $body = freightRateApiBody(Zone::factory()->active()->create(), Product::factory()->active()->create());

    unset($body[$missing]);

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/freight-rates', $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$missing]);
})->with(['zoneId', 'productId', 'fuelType', 'fuelMin', 'pricePerPound']);

it('responde 422 con un valor inválido en el alta', function (string $key, mixed $value) {
    $body = freightRateApiBody(
        Zone::factory()->active()->create(),
        Product::factory()->active()->create(),
        [$key => $value],
    );

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/freight-rates', $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$key]);
})->with([
    'combustible inexistente' => ['fuelType', 'gasolina'],
    'zona no numérica' => ['zoneId', 'norte'],
    'banda en cero' => ['fuelMin', 0],
    'banda no numérica' => ['fuelMin', 'treinta'],
    'tarifa en cero' => ['pricePerPound', 0],
    'tarifa desbordada' => ['pricePerPound', 1000000],
]);

it('registra al usuario autenticado como responsable aunque el body traiga otro', function () {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $admin = userWithRole(UserRole::Administrator);
    $otro = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/freight-rates', freightRateApiBody($zone, $product, [
        'registeredBy' => $otro->id,
        'registered_by' => $otro->id,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.registeredByName', $admin->name);

    $this->assertDatabaseHas('freight_rates', [
        'zone_id' => $zone->id,
        'registered_by' => $admin->id,
    ]);
});

/*
|--------------------------------------------------------------------------
| Detalle y guarda por id
|--------------------------------------------------------------------------
*/

it('responde 400 en las tres acciones por id sobre una tarifa ya borrada', function (string $method) {
    $admin = userWithRole(UserRole::Administrator);
    $rate = FreightRate::factory()->create();

    asUser($admin)->deleteJson("/api/freight-rates/{$rate->id}")->assertOk();

    asUser($admin)->json($method, "/api/freight-rates/{$rate->id}")
        ->assertStatus(400)
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'La tarifa ya fue eliminada',
            'data' => null,
        ]);
})->with(['GET', 'PATCH', 'DELETE']);

it('responde 404 en las tres acciones por id sobre un id que nunca existió', function (string $method) {
    asUser(userWithRole(UserRole::Administrator))->json($method, '/api/freight-rates/9999')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'La tarifa no existe',
            'data' => null,
        ]);
})->with(['GET', 'PATCH', 'DELETE']);

/*
|--------------------------------------------------------------------------
| Edición
|--------------------------------------------------------------------------
*/

it('cambia solo el precio y deja el resto del par intacto', function () {
    $rate = FreightRate::factory()->create(['fuel_min' => 30, 'price_per_pound' => 0.4]);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/freight-rates/{$rate->id}", ['pricePerPound' => 0.6])
        ->assertOk()
        ->assertJsonPath('message', 'Tarifa actualizada correctamente')
        ->assertJsonPath('data.pricePerPound', '0.600000')
        ->assertJsonPath('data.fuelMin', '30.00')
        ->assertJsonPath('data.zoneId', $rate->zone_id)
        ->assertJsonPath('data.productId', $rate->product_id)
        ->assertJsonPath('data.fuelType', $rate->fuel_type->value);

    $this->assertDatabaseHas('freight_rates', [
        'id' => $rate->id,
        'fuel_min' => '30.00',
        'price_per_pound' => '0.600000',
    ]);
});

it('acepta un PATCH vacío como no-op', function () {
    $rate = FreightRate::factory()->create();

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/freight-rates/{$rate->id}", [])
        ->assertOk()
        ->assertJsonPath('data.fuelMin', $rate->fuel_min)
        ->assertJsonPath('data.pricePerPound', $rate->price_per_pound)
        ->assertJsonPath('data.zoneId', $rate->zone_id);
});

it('rechaza con 400 mover la banda a un fuelMin ocupado y acepta uno libre', function () {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/freight-rates', freightRateApiBody($zone, $product, ['fuelMin' => 28.00]))
        ->assertCreated();

    $id = asUser($admin)->postJson('/api/freight-rates', freightRateApiBody($zone, $product, ['fuelMin' => 35.00]))
        ->assertCreated()
        ->json('data.id');

    asUser($admin)->patchJson("/api/freight-rates/{$id}", ['fuelMin' => 28.00])
        ->assertStatus(400)
        ->assertJsonPath('message', 'Ya existe una tarifa para esa zona, ese producto y ese combustible desde ese precio');

    /** Reenviar su propia banda no puede chocar consigo misma. */
    asUser($admin)->patchJson("/api/freight-rates/{$id}", ['fuelMin' => 35.00])
        ->assertOk()
        ->assertJsonPath('data.fuelMin', '35.00');

    asUser($admin)->patchJson("/api/freight-rates/{$id}", ['fuelMin' => 40.00])
        ->assertOk()
        ->assertJsonPath('data.fuelMin', '40.00');
});

it('rechaza con 400 la edición cuando la zona o el producto se desactivaron después', function (string $target, string $message) {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $rate = FreightRate::factory()->create(['zone_id' => $zone->id, 'product_id' => $product->id]);

    ($target === 'zone' ? $zone : $product)->update(['status' => false]);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/freight-rates/{$rate->id}", ['pricePerPound' => 0.5])
        ->assertStatus(400)
        ->assertJsonPath('message', $message);
})->with([
    'zona desactivada' => ['zone', 'La zona seleccionada no está activa'],
    'producto desactivado' => ['product', 'El producto seleccionado no está activo'],
]);

it('responde 422 con un valor inválido en la edición', function (string $key, mixed $value) {
    $rate = FreightRate::factory()->create();

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/freight-rates/{$rate->id}", [$key => $value])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$key]);
})->with([
    'combustible inexistente' => ['fuelType', 'gasolina'],
    'zona inexistente' => ['zoneId', 99999],
    'producto inexistente' => ['productId', 99999],
    'tarifa en cero' => ['pricePerPound', 0],
]);

/*
|--------------------------------------------------------------------------
| Baja
|--------------------------------------------------------------------------
*/

it('borra en lógico, saca la tarifa del listado y deja la fila en base', function () {
    $admin = userWithRole(UserRole::Administrator);
    $rate = FreightRate::factory()->create();

    asUser($admin)->deleteJson("/api/freight-rates/{$rate->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Tarifa eliminada correctamente')
        ->assertJsonPath('data.id', $rate->id);

    $this->assertSoftDeleted('freight_rates', ['id' => $rate->id]);

    expect(asUser($admin)->getJson('/api/freight-rates')->assertOk()->json('data'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Cotización
|--------------------------------------------------------------------------
*/

it('devuelve las once claves de la cotización con la banda que rige y el total', function () {
    ['zone' => $zone, 'product' => $product] = freightRateApiScenario(40.00, [
        [28.00, 0.400000],
        [35.00, 0.454120],
    ]);

    $data = asUser(userWithRole(UserRole::Carrier))
        ->getJson(freightRateQuoteUri($product, ['pounds' => 45000]))
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Cotización obtenida correctamente')
        ->json('data');

    expect(array_keys($data))->toBe(freightQuoteResourceKeys())
        ->and($data['zoneId'])->toBe($zone->id)
        ->and($data['zoneName'])->toBe($zone->name)
        ->and($data['productId'])->toBe($product->id)
        ->and($data['productName'])->toBe($product->name)
        ->and($data['fuelType'])->toBe('diesel')
        ->and($data['currentFuelPrice'])->toBe('40.00')
        ->and($data['appliedFuelMin'])->toBe('35.00')
        ->and($data['pricePerPound'])->toBe('0.454120')
        ->and($data['pounds'])->toBe('45000.00')
        /** El producto se calcula sobre los seis decimales, no sobre una tarifa redondeada. */
        ->and($data['total'])->toBe('20435.40');
});

it('deja libras y total en null sin pounds y devuelve el resto idéntico', function () {
    ['product' => $product] = freightRateApiScenario(40.00, [[35.00, 0.454120]]);

    $user = userWithRole(UserRole::Pilot);

    $conLibras = asUser($user)->getJson(freightRateQuoteUri($product, ['pounds' => 45000]))->assertOk()->json('data');
    $sinLibras = asUser($user)->getJson(freightRateQuoteUri($product))->assertOk()->json('data');

    expect($sinLibras['pounds'])->toBeNull()
        ->and($sinLibras['total'])->toBeNull()
        ->and(Arr::except($sinLibras, ['pounds', 'total']))->toBe(Arr::except($conLibras, ['pounds', 'total']));
});

it('ignora cualquier precio de combustible que mande el cliente', function () {
    ['product' => $product] = freightRateApiScenario(40.00, [
        [28.00, 0.400000],
        [35.00, 0.454120],
    ]);

    asUser(userWithRole(UserRole::Manager))
        ->getJson(freightRateQuoteUri($product, ['fuelPrice' => 10, 'price' => 10, 'currentFuelPrice' => 10]))
        ->assertOk()
        ->assertJsonPath('data.currentFuelPrice', '40.00')
        ->assertJsonPath('data.appliedFuelMin', '35.00')
        ->assertJsonPath('data.pricePerPound', '0.454120');
});

it('responde 404 cuando el punto no cae en ninguna zona', function () {
    ['product' => $product] = freightRateApiScenario(40.00, [[35.00, 0.454120]]);

    asUser(userWithRole(UserRole::Pilot))
        ->getJson(freightRateQuoteUri($product, ['lat' => -33.0, 'lng' => 18.0]))
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El punto indicado no pertenece a ninguna zona registrada',
            'data' => null,
        ]);
});

it('distingue con un mensaje propio cada fallo de la cotización', function () {
    ['product' => $product] = freightRateApiScenario(40.00, [[35.00, 0.454120]]);

    $user = userWithRole(UserRole::Pilot);

    $fueraDeZona = asUser($user)->getJson(freightRateQuoteUri($product, ['lat' => -33.0, 'lng' => 18.0]))
        ->assertNotFound()
        ->json('message');

    $product->update(['status' => false]);

    $productoInactivo = asUser($user)->getJson(freightRateQuoteUri($product))->assertStatus(400)->json('message');

    $product->update(['status' => true]);
    FuelPrice::query()->update(['status' => FuelPriceStatus::Inactive]);

    $sinPrecioVigente = asUser($user)->getJson(freightRateQuoteUri($product))->assertStatus(400)->json('message');

    FuelPrice::query()->update(['status' => FuelPriceStatus::Active]);

    /** Una tarifa borrada no se aplica nunca: si era la única del par, el par se queda sin tarifa. */
    FreightRate::query()->delete();

    $sinTarifa = asUser($user)->getJson(freightRateQuoteUri($product))->assertStatus(400)->json('message');

    expect([$fueraDeZona, $productoInactivo, $sinPrecioVigente, $sinTarifa])->toBe([
        'El punto indicado no pertenece a ninguna zona registrada',
        'El producto seleccionado no está activo',
        'No existe un precio vigente para el combustible indicado',
        'No existe tarifa cotizada para ese producto en esa zona',
    ]);
});

it('responde 422 cuando falta un parámetro obligatorio de la cotización', function (string $missing) {
    ['product' => $product] = freightRateApiScenario(40.00, [[35.00, 0.454120]]);

    asUser(userWithRole(UserRole::Pilot))
        ->getJson(freightRateQuoteUri($product, [$missing => null]))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$missing]);
})->with(['lat', 'lng', 'productId', 'fuelType']);

it('responde 422 con un parámetro fuera de rango en la cotización', function (string $key, mixed $value) {
    ['product' => $product] = freightRateApiScenario(40.00, [[35.00, 0.454120]]);

    asUser(userWithRole(UserRole::Pilot))
        ->getJson(freightRateQuoteUri($product, [$key => $value]))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$key]);
})->with([
    'latitud imposible' => ['lat', 200],
    'longitud imposible' => ['lng', 500],
    'combustible inexistente' => ['fuelType', 'gasolina'],
    'producto inexistente' => ['productId', 99999],
    'libras en cero' => ['pounds', 0],
]);

it('no persiste nada al cotizar', function () {
    ['product' => $product] = freightRateApiScenario(40.00, [[35.00, 0.454120]]);

    $antes = FreightRate::withTrashed()->count();

    asUser(userWithRole(UserRole::Carrier))
        ->getJson(freightRateQuoteUri($product, ['pounds' => 45000]))
        ->assertOk();

    expect(FreightRate::withTrashed()->count())->toBe($antes);
});
