<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\FinishedProduct;
use App\Models\Trip;
use App\Models\TripFinishedProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * The four routes of the domain as method and URI, for the middleware datasets.
 *
 * @return array<string, array{string, string}>
 */
function tripFinishedProductEndpoints(): array
{
    return [
        'index' => ['GET', '/api/trip-finished-products?tripId=1'],
        'store' => ['POST', '/api/trip-finished-products'],
        'update' => ['PATCH', '/api/trip-finished-products/1'],
        'destroy' => ['DELETE', '/api/trip-finished-products/1'],
    ];
}

/**
 * The five roles `role:administrator,export` keeps out of the three writing routes.
 *
 * @return array<string, UserRole>
 */
function tripFinishedProductNonWriterRoles(): array
{
    return [
        'manager' => UserRole::Manager,
        'carrier' => UserRole::Carrier,
        'pilot' => UserRole::Pilot,
        'user' => UserRole::User,
        'shipment' => UserRole::Shipment,
    ];
}

/**
 * The ten keys `TripFinishedProductResource` promises, in order.
 *
 * @return array<int, string>
 */
function tripFinishedProductKeys(): array
{
    return [
        'id', 'tripId', 'finishedProductId', 'code', 'name',
        'presentation', 'boxesPerPallet', 'boxes', 'registeredByName', 'createdAt',
    ];
}

/**
 * A finished product of the same client as the given trip.
 *
 * @param  array<string, mixed>  $attributes
 */
function tripFinishedProductSku(Trip $trip, array $attributes = []): FinishedProduct
{
    return FinishedProduct::factory()->create(['client_id' => $trip->client_id, ...$attributes]);
}

/**
 * A line of the given trip, with a fresh SKU of its client.
 *
 * @param  array<string, mixed>  $attributes
 */
function tripFinishedProductLine(Trip $trip, array $attributes = []): TripFinishedProduct
{
    return TripFinishedProduct::factory()->create([
        'trip_id' => $trip->id,
        'finished_product_id' => tripFinishedProductSku($trip)->id,
        ...$attributes,
    ]);
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

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

it('expone exactamente cuatro rutas', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/trip-finished-products'));

    expect($routes)->toHaveCount(4);
});

it('rechaza con 401 las cuatro rutas sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(tripFinishedProductEndpoints());

it('rechaza con 403 las tres rutas de escritura a quien no es administrador ni export', function (UserRole $role) {
    $trip = Trip::factory()->create();
    $line = tripFinishedProductLine($trip);
    tripFinishedProductLine($trip);

    $forbidden = [
        'statusCode' => 403,
        'message' => 'No tienes permisos para acceder a este recurso',
        'data' => null,
    ];

    asUser(userWithRole($role))->postJson('/api/trip-finished-products', [
        'tripId' => $trip->id,
        'finishedProductId' => tripFinishedProductSku($trip)->id,
        'boxes' => 10,
    ])->assertForbidden()->assertExactJson($forbidden);

    asUser(userWithRole($role))->patchJson("/api/trip-finished-products/{$line->id}", ['boxes' => 5])
        ->assertForbidden()->assertExactJson($forbidden);

    asUser(userWithRole($role))->deleteJson("/api/trip-finished-products/{$line->id}")
        ->assertForbidden()->assertExactJson($forbidden);

    expect(TripFinishedProduct::count())->toBe(2)
        ->and($line->fresh()->boxes)->toBe($line->boxes);
})->with(tripFinishedProductNonWriterRoles());

it('deja escribir líneas al administrador y a export', function (UserRole $role) {
    $trip = Trip::factory()->create();
    tripFinishedProductLine($trip);

    $id = asUser(userWithRole($role))->postJson('/api/trip-finished-products', [
        'tripId' => $trip->id,
        'finishedProductId' => tripFinishedProductSku($trip)->id,
        'boxes' => 10,
    ])->assertCreated()->json('data.id');

    asUser(userWithRole($role))->patchJson("/api/trip-finished-products/{$id}", ['boxes' => 20])
        ->assertOk()
        ->assertJsonPath('data.boxes', 20);

    asUser(userWithRole($role))->deleteJson("/api/trip-finished-products/{$id}")
        ->assertOk();
})->with([
    'administrator' => UserRole::Administrator,
    'export' => UserRole::Export,
]);

/*
|--------------------------------------------------------------------------
| Listado
|--------------------------------------------------------------------------
*/

it('rechaza con 422 el listado sin tripId', function () {
    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/trip-finished-products')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['tripId'])
        ->assertJsonFragment(['El viaje es obligatorio']);

    expect(array_keys($response->json()))->toBe(['message', 'errors']);
});

it('responde 404 al listar un viaje inexistente o borrado', function () {
    $trashed = Trip::factory()->trashed()->create();

    foreach ([999999, $trashed->id] as $tripId) {
        asUser(userWithRole(UserRole::Administrator))->getJson("/api/trip-finished-products?tripId={$tripId}")
            ->assertNotFound()
            ->assertJsonPath('message', 'El viaje no existe');
    }
});

it('responde 403 al transportista fuera del ámbito del viaje', function () {
    $trip = Trip::factory()->assigned()->create();
    tripFinishedProductLine($trip);

    asUser(Carrier::factory()->create()->owner)->getJson("/api/trip-finished-products?tripId={$trip->id}")
        ->assertForbidden();
});

it('responde 403 al piloto que no tiene asignado el viaje', function () {
    $trip = Trip::factory()->assigned()->create();

    asUser(userWithRole(UserRole::Pilot))->getJson("/api/trip-finished-products?tripId={$trip->id}")
        ->assertForbidden();
});

it('lista las líneas a los siete lectores dentro de su ámbito', function (string $who) {
    $trip = Trip::factory()->assigned()->create();
    tripFinishedProductLine($trip);

    $reader = match ($who) {
        'pilot' => User::findOrFail($trip->pilot_id),
        'carrier' => User::findOrFail($trip->assigned_by),
        default => userWithRole(UserRole::from($who)),
    };

    asUser($reader)->getJson("/api/trip-finished-products?tripId={$trip->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Productos del viaje obtenidos correctamente')
        ->assertJsonCount(1, 'data');
})->with(['administrator', 'manager', 'export', 'user', 'shipment', 'pilot', 'carrier']);

it('lista en orden id ASC con las diez claves y nunca pagina', function () {
    $trip = Trip::factory()->create();
    $sku = tripFinishedProductSku($trip, ['code' => 'BRO-IQF-10', 'name' => 'BRÓCOLI FLORETE IQF', 'presentation' => 10, 'boxes_per_pallet' => 96.5]);
    $first = tripFinishedProductLine($trip, ['finished_product_id' => $sku->id, 'boxes' => 960]);
    $lines = collect(range(1, 11))->map(fn () => tripFinishedProductLine($trip));

    $response = asUser(userWithRole(UserRole::Administrator))->getJson("/api/trip-finished-products?tripId={$trip->id}&limit=10")
        ->assertOk()
        ->assertJsonCount(12, 'data')
        ->assertJsonMissingPath('total')
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.0.tripId', $trip->id)
        ->assertJsonPath('data.0.finishedProductId', $sku->id)
        ->assertJsonPath('data.0.code', 'BRO-IQF-10')
        ->assertJsonPath('data.0.name', 'BRÓCOLI FLORETE IQF')
        ->assertJsonPath('data.0.presentation', '10.00')
        ->assertJsonPath('data.0.boxesPerPallet', '96.50')
        ->assertJsonPath('data.0.boxes', 960)
        ->assertJsonPath('data.0.createdAt', $first->created_at->format('d-m-Y h:i:s A'));

    expect(array_keys($response->json('data.0')))->toBe(tripFinishedProductKeys())
        ->and(array_column($response->json('data'), 'id'))->toBe([$first->id, ...$lines->pluck('id')->all()]);
});

it('responde 200 con data vacío en un viaje sin líneas', function () {
    $trip = Trip::factory()->create();

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/trip-finished-products?tripId={$trip->id}")
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('sigue mostrando la línea de un producto terminado borrado después', function () {
    $trip = Trip::factory()->create();
    $sku = tripFinishedProductSku($trip, ['code' => 'ARV-CH-5', 'name' => 'ARVEJA CHINA']);
    tripFinishedProductLine($trip, ['finished_product_id' => $sku->id]);

    $sku->delete();

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/trip-finished-products?tripId={$trip->id}")
        ->assertOk()
        ->assertJsonPath('data.0.code', 'ARV-CH-5')
        ->assertJsonPath('data.0.name', 'ARVEJA CHINA');
});

it('no crece en consultas con el número de líneas', function () {
    $small = Trip::factory()->create();
    tripFinishedProductLine($small);

    $big = Trip::factory()->create();
    collect(range(1, 8))->each(fn () => tripFinishedProductLine($big));

    $admin = userWithRole(UserRole::Administrator);

    $count = function (int $tripId) use ($admin): int {
        asUser($admin);
        DB::flushQueryLog();
        DB::enableQueryLog();

        test()->getJson("/api/trip-finished-products?tripId={$tripId}")->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count($big->id))->toBe($count($small->id));
});

/*
|--------------------------------------------------------------------------
| Alta de línea
|--------------------------------------------------------------------------
*/

it('agrega una línea con 201 y el autor autenticado', function () {
    $admin = userWithRole(UserRole::Administrator);
    $trip = Trip::factory()->create();
    $sku = tripFinishedProductSku($trip);

    $response = asUser($admin)->postJson('/api/trip-finished-products', [
        'tripId' => $trip->id,
        'finishedProductId' => $sku->id,
        'boxes' => 120,
        'registeredBy' => userWithRole(UserRole::Administrator)->id,
    ])
        ->assertCreated()
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Producto agregado al viaje correctamente')
        ->assertJsonPath('data.boxes', 120)
        ->assertJsonPath('data.registeredByName', $admin->name);

    expect(array_keys($response->json('data')))->toBe(tripFinishedProductKeys());

    $this->assertDatabaseHas('trip_finished_products', [
        'trip_id' => $trip->id,
        'finished_product_id' => $sku->id,
        'boxes' => 120,
        'registered_by' => $admin->id,
    ]);
});

it('valida el alta de línea con 422', function (array $override, string $field) {
    $trip = Trip::factory()->create();

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/trip-finished-products', [
        'tripId' => $trip->id,
        'finishedProductId' => tripFinishedProductSku($trip)->id,
        'boxes' => 10,
        ...$override,
    ])->assertStatus(422)->assertJsonValidationErrors([$field]);
})->with([
    'sin tripId' => [['tripId' => null], 'tripId'],
    'tripId inexistente' => [['tripId' => 999999], 'tripId'],
    'sin producto' => [['finishedProductId' => null], 'finishedProductId'],
    'producto inexistente' => [['finishedProductId' => 999999], 'finishedProductId'],
    'sin cajas' => [['boxes' => null], 'boxes'],
    'cajas en cero' => [['boxes' => 0], 'boxes'],
    'cajas decimales' => [['boxes' => 1.5], 'boxes'],
    'cajas sobre el tope' => [['boxes' => 1000000], 'boxes'],
]);

it('aplica las cinco guardas del alta de línea en orden', function () {
    $admin = userWithRole(UserRole::Administrator);
    $post = fn (Trip $trip, FinishedProduct $sku) => asUser($admin)->postJson('/api/trip-finished-products', [
        'tripId' => $trip->id,
        'finishedProductId' => $sku->id,
        'boxes' => 10,
    ]);

    /** Borrado y en ruta con un producto borrado de otro cliente: gana el viaje borrado. */
    $trashed = Trip::factory()->inRoute()->trashed()->create();
    $post($trashed, FinishedProduct::factory()->trashed()->create())
        ->assertStatus(400)->assertJsonPath('message', 'El viaje ya fue eliminado');

    /** En ruta con un producto borrado: gana el estado del viaje. */
    $inRoute = Trip::factory()->inRoute()->create();
    $post($inRoute, FinishedProduct::factory()->trashed()->create())
        ->assertStatus(400)->assertJsonPath('message', 'Solo se pueden modificar los productos de un viaje pendiente');

    $pending = Trip::factory()->create();

    /** Borrado y de otro cliente: gana el borrado. */
    $post($pending, FinishedProduct::factory()->trashed()->create())
        ->assertStatus(400)->assertJsonPath('message', 'El producto terminado seleccionado ya fue eliminado');

    $post($pending, FinishedProduct::factory()->create())
        ->assertStatus(400)->assertJsonPath('message', 'El producto terminado no pertenece al cliente del viaje');

    $line = tripFinishedProductLine($pending);
    $post($pending, $line->finishedProduct)
        ->assertStatus(400)->assertJsonPath('message', 'El producto terminado ya está en el viaje');

    expect(TripFinishedProduct::count())->toBe(1);
});

it('rechaza con 400 el alta de línea en un viaje finalizado', function () {
    $trip = Trip::factory()->finished()->create();

    asUser(userWithRole(UserRole::Administrator))->postJson('/api/trip-finished-products', [
        'tripId' => $trip->id,
        'finishedProductId' => tripFinishedProductSku($trip)->id,
        'boxes' => 10,
    ])->assertStatus(400)->assertJsonPath('message', 'Solo se pueden modificar los productos de un viaje pendiente');
});

/*
|--------------------------------------------------------------------------
| Edición de línea
|--------------------------------------------------------------------------
*/

it('cambia solo las cajas e ignora tripId, finishedProductId y el autor', function () {
    $trip = Trip::factory()->create();
    $line = tripFinishedProductLine($trip, ['boxes' => 100]);
    $other = Trip::factory()->create();
    $editor = userWithRole(UserRole::Export);

    asUser($editor)->patchJson("/api/trip-finished-products/{$line->id}", [
        'boxes' => 250,
        'tripId' => $other->id,
        'finishedProductId' => tripFinishedProductSku($other)->id,
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Producto del viaje actualizado correctamente')
        ->assertJsonPath('data.boxes', 250)
        ->assertJsonPath('data.tripId', $trip->id)
        ->assertJsonPath('data.finishedProductId', $line->finished_product_id);

    $this->assertDatabaseHas('trip_finished_products', [
        'id' => $line->id,
        'trip_id' => $trip->id,
        'finished_product_id' => $line->finished_product_id,
        'boxes' => 250,
        'registered_by' => $line->registered_by,
    ]);
});

it('rechaza con 422 la edición de línea con el cuerpo vacío', function () {
    $line = tripFinishedProductLine(Trip::factory()->create());

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/trip-finished-products/{$line->id}", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['boxes'])
        ->assertJsonFragment(['Las cajas son obligatorias']);
});

it('responde 404 al editar o borrar una línea inexistente', function () {
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->patchJson('/api/trip-finished-products/999999', ['boxes' => 5])
        ->assertNotFound()->assertJsonPath('message', 'La línea de producto no existe');

    asUser($admin)->deleteJson('/api/trip-finished-products/999999')
        ->assertNotFound()->assertJsonPath('message', 'La línea de producto no existe');
});

it('rechaza con 400 editar o borrar líneas de un viaje que ya no está pendiente', function (string $state) {
    $trip = Trip::factory()->{$state}()->create();
    $line = tripFinishedProductLine($trip, ['boxes' => 100]);
    tripFinishedProductLine($trip);
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->patchJson("/api/trip-finished-products/{$line->id}", ['boxes' => 5])
        ->assertStatus(400)->assertJsonPath('message', 'Solo se pueden modificar los productos de un viaje pendiente');

    asUser($admin)->deleteJson("/api/trip-finished-products/{$line->id}")
        ->assertStatus(400)->assertJsonPath('message', 'Solo se pueden modificar los productos de un viaje pendiente');

    expect($line->fresh()->boxes)->toBe(100)
        ->and(TripFinishedProduct::count())->toBe(2);
})->with(['inRoute', 'finished']);

it('rechaza con 400 editar o borrar líneas de un viaje borrado', function () {
    $trip = Trip::factory()->create();
    $line = tripFinishedProductLine($trip);
    tripFinishedProductLine($trip);
    $trip->delete();
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->patchJson("/api/trip-finished-products/{$line->id}", ['boxes' => 5])
        ->assertStatus(400)->assertJsonPath('message', 'El viaje ya fue eliminado');

    asUser($admin)->deleteJson("/api/trip-finished-products/{$line->id}")
        ->assertStatus(400)->assertJsonPath('message', 'El viaje ya fue eliminado');
});

/*
|--------------------------------------------------------------------------
| Borrado de línea
|--------------------------------------------------------------------------
*/

it('rechaza con 400 borrar la única línea del viaje', function () {
    $line = tripFinishedProductLine(Trip::factory()->create());

    asUser(userWithRole(UserRole::Administrator))->deleteJson("/api/trip-finished-products/{$line->id}")
        ->assertStatus(400)
        ->assertJsonPath('message', 'El viaje debe tener al menos un producto terminado');

    $this->assertDatabaseHas('trip_finished_products', ['id' => $line->id]);
});

it('borra físicamente una de dos líneas con 200', function () {
    $trip = Trip::factory()->create();
    $line = tripFinishedProductLine($trip);
    $keep = tripFinishedProductLine($trip);

    asUser(userWithRole(UserRole::Administrator))->deleteJson("/api/trip-finished-products/{$line->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Producto eliminado del viaje correctamente')
        ->assertJsonPath('data.id', $line->id);

    $this->assertDatabaseMissing('trip_finished_products', ['id' => $line->id]);
    $this->assertDatabaseHas('trip_finished_products', ['id' => $keep->id]);
    expect($trip->fresh()->status)->toBe(TripStatus::Pending);
});
