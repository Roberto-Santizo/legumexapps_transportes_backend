<?php

use App\Enums\UserRole;
use App\Enums\VehicleExpenseCategory;
use App\Enums\VehicleExpenseNature;
use App\Enums\VehicleStatus;
use App\Interfaces\Storage\FileStorageServiceInterface;
use App\Models\Carrier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Doubles\InMemoryFileStorageService;
use Tests\TestCase;

/**
 * Every vehicle expenses endpoint as method and URI, for the middleware datasets.
 *
 * @return array<string, array{string, string}>
 */
function vehicleExpenseEndpoints(): array
{
    return [
        'index' => ['GET', '/api/vehicle-expenses'],
        'store' => ['POST', '/api/vehicle-expenses'],
        'show' => ['GET', '/api/vehicle-expenses/1'],
        'update' => ['PATCH', '/api/vehicle-expenses/1'],
        'destroy' => ['DELETE', '/api/vehicle-expenses/1'],
    ];
}

/**
 * The three write endpoints, the ones a manager may not reach.
 *
 * @return array<string, array{string, string}>
 */
function vehicleExpenseWriteEndpoints(): array
{
    return [
        'store' => ['POST', '/api/vehicle-expenses'],
        'update' => ['PATCH', '/api/vehicle-expenses/1'],
        'destroy' => ['DELETE', '/api/vehicle-expenses/1'],
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
 * A valid store payload, in the snake_case the endpoint expects.
 *
 * @return array<string, mixed>
 */
function validExpensePayload(int $vehicleId, array $overrides = []): array
{
    return array_merge([
        'vehicle_id' => $vehicleId,
        'category' => VehicleExpenseCategory::Tires->value,
        'nature' => VehicleExpenseNature::Preventive->value,
        'amount' => 1250.5,
        'expense_date' => now()->subDay()->format('Y-m-d'),
        'description' => 'Cuatro llantas nuevas, taller El Rodaje, factura A-9912',
        'is_invoiced' => false,
    ], $overrides);
}

/**
 * Create expenses of a vehicle without spawning a vehicle and a user per row.
 *
 * @return Collection<int, VehicleExpense>
 */
function seedExpenses(Vehicle $vehicle, User $registeredBy, int $count, array $overrides = [])
{
    return VehicleExpense::factory()->count($count)->create(array_merge([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $registeredBy->id,
    ], $overrides));
}

/**
 * The ids returned inside the data key of a listing response.
 *
 * @return array<int, int>
 */
function listedExpenseIds(array $data): array
{
    return collect($data)->pluck('id')->all();
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth y role
|--------------------------------------------------------------------------
*/

it('rechaza con 401 cualquier endpoint de gastos sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(vehicleExpenseEndpoints());

it('rechaza con 403 a un piloto en cualquier endpoint de gastos', function (string $method, string $uri) {
    asUser(userWithRole(UserRole::Pilot))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(vehicleExpenseEndpoints());

it('rechaza con 403 a un manager en los endpoints de escritura de gastos', function (string $method, string $uri) {
    asUser(userWithRole(UserRole::Manager))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(vehicleExpenseWriteEndpoints());

it('deja leer a un manager los gastos de cualquier empresa', function () {
    $vehicle = Vehicle::factory()->create();
    $expense = seedExpenses($vehicle, $vehicle->carrier->owner, 3)->first();

    $manager = userWithRole(UserRole::Manager);

    asUser($manager)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}")
        ->assertOk()
        ->assertJsonCount(3, 'data');

    asUser($manager)->getJson("/api/vehicle-expenses/{$expense->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $expense->id);
});

it('no exige empresa transportista a un administrador', function () {
    $vehicle = Vehicle::factory()->create();
    seedExpenses($vehicle, $vehicle->carrier->owner, 2);

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('rechaza con 403 a un carrier sin empresa que pide gastos', function () {
    $vehicle = Vehicle::factory()->create();

    asUser(userWithRole(UserRole::Carrier))->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}")
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No perteneces a ninguna empresa transportista',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| GET /api/vehicle-expenses — vehicleId obligatorio
|--------------------------------------------------------------------------
*/

it('rechaza con 422 el listado de gastos sin vehicleId', function () {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)->getJson('/api/vehicle-expenses')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vehicleId'])
        ->assertJsonPath('errors.vehicleId.0', 'El vehículo es obligatorio')
        ->assertJsonStructure(['message', 'errors' => ['vehicleId']]);
});

it('rechaza con 422 un vehicleId que no es entero', function () {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)->getJson('/api/vehicle-expenses?vehicleId=abc')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vehicleId'])
        ->assertJsonPath('errors.vehicleId.0', 'El vehículo debe ser un número entero');
});

it('devuelve 404 y no un listado vacío con un vehicleId inexistente', function () {
    asUser(userWithRole(UserRole::Administrator))->getJson('/api/vehicle-expenses?vehicleId=99999')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El vehículo no existe',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| GET /api/vehicle-expenses — contenido y orden
|--------------------------------------------------------------------------
*/

it('devuelve solo los gastos del vehículo pedido', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);
    $otro = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $propios = seedExpenses($vehicle, $carrier->owner, 2);
    seedExpenses($otro, $carrier->owner, 3);

    $response = asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}");

    $response->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Gastos obtenidos correctamente',
        ])
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'statusCode',
            'message',
            'totalAmount',
            'data' => [['id', 'vehicleId', 'category', 'nature', 'amount', 'expenseDate', 'description', 'registeredBy', 'createdAt']],
        ]);

    expect(listedExpenseIds($response->json('data')))->toEqualCanonicalizing($propios->pluck('id')->all());
});

it('ordena los gastos por fecha descendente', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $antiguo = seedExpenses($vehicle, $carrier->owner, 1, ['expense_date' => '2026-01-01'])->first();
    $reciente = seedExpenses($vehicle, $carrier->owner, 1, ['expense_date' => '2026-03-01'])->first();
    $medio = seedExpenses($vehicle, $carrier->owner, 1, ['expense_date' => '2026-02-01'])->first();

    $response = asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}");

    expect(listedExpenseIds($response->assertOk()->json('data')))
        ->toBe([$reciente->id, $medio->id, $antiguo->id]);
});

it('desempata por id descendente dos gastos del mismo día', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $primero = seedExpenses($vehicle, $carrier->owner, 1, ['expense_date' => '2026-02-01'])->first();
    $segundo = seedExpenses($vehicle, $carrier->owner, 1, ['expense_date' => '2026-02-01'])->first();

    $response = asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}");

    expect(listedExpenseIds($response->assertOk()->json('data')))->toBe([$segundo->id, $primero->id]);
});

/*
|--------------------------------------------------------------------------
| GET /api/vehicle-expenses — totalAmount y paginación
|--------------------------------------------------------------------------
*/

it('devuelve totalAmount con dos decimales y sin claves de paginación sin limit', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    seedExpenses($vehicle, $carrier->owner, 3, ['amount' => 100.50]);

    $response = asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}");

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('totalAmount', '301.50');

    expect($response->json())->not->toHaveKeys(['total', 'currentPage', 'lastPage']);
});

it('suma en totalAmount todos los gastos filtrados y no los de la página', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    seedExpenses($vehicle, $carrier->owner, 12, ['amount' => 100.50]);

    asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}&limit=10")
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJson([
            'statusCode' => 200,
            'totalAmount' => '1206.00',
            'total' => 12,
            'currentPage' => 1,
            'lastPage' => 2,
        ])
        ->assertJsonStructure(['statusCode', 'message', 'data', 'totalAmount', 'total', 'currentPage', 'lastPage']);
});

it('deja totalAmount en cero cuando el vehículo no tiene gastos', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('totalAmount', '0.00');
});

it('acota el limit inferior de gastos a 10 por página', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    seedExpenses($vehicle, $carrier->owner, 12);

    asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}&limit=5")
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('total', 12)
        ->assertJsonPath('lastPage', 2);
});

it('acota el limit superior de gastos a 100 por página', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    seedExpenses($vehicle, $carrier->owner, 101);

    asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}&limit=500")
        ->assertOk()
        ->assertJsonCount(100, 'data')
        ->assertJsonPath('total', 101)
        ->assertJsonPath('lastPage', 2);
});

it('devuelve todos los gastos sin paginar cuando limit no es numérico', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    seedExpenses($vehicle, $carrier->owner, 12);

    $response = asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}&limit=abc");

    $response->assertOk()->assertJsonCount(12, 'data');

    expect($response->json())->not->toHaveKey('total');
});

/*
|--------------------------------------------------------------------------
| GET /api/vehicle-expenses — filtros tolerantes
|--------------------------------------------------------------------------
*/

it('filtra los gastos por categoría e ignora una categoría inexistente', function (?string $category, int $esperados) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    seedExpenses($vehicle, $carrier->owner, 2, ['category' => VehicleExpenseCategory::Tires]);
    seedExpenses($vehicle, $carrier->owner, 3, ['category' => VehicleExpenseCategory::Brakes]);

    $query = $category === null ? '' : "&category={$category}";

    asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}{$query}")
        ->assertOk()
        ->assertJsonCount($esperados, 'data');
})->with([
    'sin filtro' => [null, 5],
    'llantas' => ['tires', 2],
    'frenos' => ['brakes', 3],
    'fuera del enum' => ['inexistente', 5],
    'vacío' => ['', 5],
]);

it('filtra los gastos por naturaleza e ignora una naturaleza inexistente', function (?string $nature, int $esperados) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    seedExpenses($vehicle, $carrier->owner, 2, ['nature' => VehicleExpenseNature::Preventive]);
    seedExpenses($vehicle, $carrier->owner, 4, ['nature' => VehicleExpenseNature::Corrective]);

    $query = $nature === null ? '' : "&nature={$nature}";

    asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}{$query}")
        ->assertOk()
        ->assertJsonCount($esperados, 'data');
})->with([
    'sin filtro' => [null, 6],
    'preventivos' => ['preventive', 2],
    'correctivos' => ['corrective', 4],
    'fuera del enum' => ['inexistente', 6],
]);

it('acota los gastos por dateFrom y dateTo, ambos inclusive', function (string $query, int $esperados) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    foreach (['2026-01-01', '2026-01-15', '2026-01-31'] as $date) {
        seedExpenses($vehicle, $carrier->owner, 1, ['expense_date' => $date]);
    }

    asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}{$query}")
        ->assertOk()
        ->assertJsonCount($esperados, 'data');
})->with([
    'sin cotas' => ['', 3],
    'desde incluye su propio día' => ['&dateFrom=2026-01-15', 2],
    'hasta incluye su propio día' => ['&dateTo=2026-01-15', 2],
    'rango cerrado inclusive' => ['&dateFrom=2026-01-01&dateTo=2026-01-31', 3],
    'rango que deja fuera los extremos' => ['&dateFrom=2026-01-02&dateTo=2026-01-30', 1],
    'fecha malformada se ignora' => ['&dateFrom=2026-13-45', 3],
    'fecha en otro formato se ignora' => ['&dateTo=15/01/2026', 3],
    'fecha vacía se ignora' => ['&dateFrom=', 3],
]);

it('aplica a la vez los filtros, la paginación y el acumulado', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    seedExpenses($vehicle, $carrier->owner, 12, [
        'category' => VehicleExpenseCategory::Tires,
        'amount' => 100.00,
    ]);
    seedExpenses($vehicle, $carrier->owner, 5, [
        'category' => VehicleExpenseCategory::Brakes,
        'amount' => 999.99,
    ]);

    asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}&category=tires&limit=10")
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('total', 12)
        ->assertJsonPath('lastPage', 2)
        ->assertJsonPath('totalAmount', '1200.00');
});

/*
|--------------------------------------------------------------------------
| POST /api/vehicle-expenses
|--------------------------------------------------------------------------
*/

it('registra un gasto de un carrier y devuelve 201 con el recurso', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->postJson('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'expense_date' => '2026-08-12',
    ]))
        ->assertCreated()
        ->assertJson([
            'statusCode' => 201,
            'message' => 'Gasto registrado correctamente',
            'data' => [
                'vehicleId' => $vehicle->id,
                'category' => 'tires',
                'nature' => 'preventive',
                'amount' => '1250.50',
                'expenseDate' => '12-08-2026',
                'description' => 'Cuatro llantas nuevas, taller El Rodaje, factura A-9912',
                'registeredBy' => $carrier->owner->name,
            ],
        ])
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => ['id', 'vehicleId', 'category', 'nature', 'amount', 'expenseDate', 'description', 'isInvoiced', 'invoiceUrl', 'invoiceType', 'registeredBy', 'createdAt'],
        ]);

    $this->assertDatabaseHas('vehicle_expenses', [
        'vehicle_id' => $vehicle->id,
        'category' => 'tires',
        'nature' => 'preventive',
        'amount' => 1250.50,
        'expense_date' => '2026-08-12',
        'registered_by' => $carrier->owner->id,
    ]);
});

it('registra un gasto de un administrador sobre el vehículo de cualquier empresa', function () {
    $vehicle = Vehicle::factory()->create();
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->postJson('/api/vehicle-expenses', validExpensePayload($vehicle->id))
        ->assertCreated()
        ->assertJsonPath('data.registeredBy', $admin->name);

    $this->assertDatabaseHas('vehicle_expenses', [
        'vehicle_id' => $vehicle->id,
        'registered_by' => $admin->id,
    ]);
});

it('valida los campos obligatorios al registrar un gasto', function (string $field, string $message) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $payload = validExpensePayload($vehicle->id);
    unset($payload[$field]);

    asUser($carrier->owner)->postJson('/api/vehicle-expenses', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field])
        ->assertJsonPath("errors.{$field}.0", $message);

    $this->assertDatabaseCount('vehicle_expenses', 0);
})->with([
    'vehicle_id' => ['vehicle_id', 'El vehículo es obligatorio'],
    'category' => ['category', 'La categoría del gasto es obligatoria'],
    'nature' => ['nature', 'La naturaleza del gasto es obligatoria'],
    'amount' => ['amount', 'El monto es obligatorio'],
    'expense_date' => ['expense_date', 'La fecha del gasto es obligatoria'],
    'description' => ['description', 'La descripción es obligatoria'],
]);

it('rechaza con 422 los valores inválidos al registrar un gasto', function (array $overrides, string $field) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->postJson('/api/vehicle-expenses', validExpensePayload($vehicle->id, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    $this->assertDatabaseCount('vehicle_expenses', 0);
})->with([
    'categoría fuera del enum' => [['category' => 'motor_fundido'], 'category'],
    'naturaleza fuera del enum' => [['nature' => 'urgente'], 'nature'],
    'monto cero' => [['amount' => 0], 'amount'],
    'monto negativo' => [['amount' => -5], 'amount'],
    'monto no numérico' => [['amount' => 'mucho'], 'amount'],
    'monto por encima del máximo' => [['amount' => 100000000], 'amount'],
    'fecha malformada' => [['expense_date' => '31-31-2026'], 'expense_date'],
    'descripción demasiado larga' => [['description' => str_repeat('a', 1001)], 'description'],
    'vehículo no entero' => [['vehicle_id' => 'abc'], 'vehicle_id'],
]);

it('rechaza con 422 un gasto con fecha de mañana', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->postJson('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'expense_date' => now()->addDay()->format('Y-m-d'),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expense_date'])
        ->assertJsonPath('errors.expense_date.0', 'La fecha del gasto no puede ser futura');

    $this->assertDatabaseCount('vehicle_expenses', 0);
});

it('acepta un gasto con la fecha de hoy', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->postJson('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'expense_date' => now()->format('Y-m-d'),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.expenseDate', now()->format('d-m-Y'));
});

it('acepta el monto mínimo de un centavo', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->postJson('/api/vehicle-expenses', validExpensePayload($vehicle->id, ['amount' => 0.01]))
        ->assertCreated()
        ->assertJsonPath('data.amount', '0.01');
});

it('deja registered_by con el usuario autenticado aunque el body mande otro', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);
    $otro = userWithRole(UserRole::Administrator);

    asUser($carrier->owner)->postJson('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'registered_by' => $otro->id,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.registeredBy', $carrier->owner->name);

    $this->assertDatabaseHas('vehicle_expenses', ['registered_by' => $carrier->owner->id]);
    $this->assertDatabaseMissing('vehicle_expenses', ['registered_by' => $otro->id]);
});

it('acepta gastos sobre un vehículo desactivado', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'status' => VehicleStatus::Inactive,
    ]);

    asUser($carrier->owner)->postJson('/api/vehicle-expenses', validExpensePayload($vehicle->id))
        ->assertCreated()
        ->assertJsonPath('data.vehicleId', $vehicle->id);
});

it('acepta la misma categoría con las dos naturalezas', function (string $nature) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->postJson('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'category' => VehicleExpenseCategory::Brakes->value,
        'nature' => $nature,
    ]))
        ->assertCreated()
        ->assertJson([
            'data' => [
                'category' => 'brakes',
                'nature' => $nature,
            ],
        ]);
})->with(['preventive', 'corrective']);

it('acepta las 22 categorías del enum', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    foreach (VehicleExpenseCategory::cases() as $category) {
        asUser($carrier->owner)->postJson('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
            'category' => $category->value,
        ]))->assertCreated();
    }

    $this->assertDatabaseCount('vehicle_expenses', 22);
});

it('devuelve 404 al registrar un gasto sobre un vehículo inexistente', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->postJson('/api/vehicle-expenses', validExpensePayload(99999))
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El vehículo no existe',
            'data' => null,
        ]);

    $this->assertDatabaseCount('vehicle_expenses', 0);
});

/*
|--------------------------------------------------------------------------
| GET /api/vehicle-expenses/{vehicleExpense}
|--------------------------------------------------------------------------
*/

it('devuelve el gasto buscado con la forma exacta del recurso', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
        'category' => VehicleExpenseCategory::OilChange,
        'nature' => VehicleExpenseNature::Corrective,
        'amount' => 340.75,
        'expense_date' => '2026-07-04',
        'description' => 'Cambio de aceite y filtro',
    ]);

    asUser($carrier->owner)->getJson("/api/vehicle-expenses/{$expense->id}")
        ->assertOk()
        ->assertExactJson([
            'statusCode' => 200,
            'message' => 'Gasto obtenido correctamente',
            'data' => [
                'id' => $expense->id,
                'vehicleId' => $vehicle->id,
                'category' => 'oil_change',
                'nature' => 'corrective',
                'amount' => '340.75',
                'expenseDate' => '04-07-2026',
                'description' => 'Cambio de aceite y filtro',
                'isInvoiced' => false,
                'invoiceUrl' => null,
                'invoiceType' => null,
                'registeredBy' => $carrier->owner->name,
                'createdAt' => $expense->created_at->format('d-m-Y h:i:s A'),
            ],
        ]);
});

it('devuelve 404 al buscar un gasto inexistente', function () {
    asUser(userWithRole(UserRole::Administrator))->getJson('/api/vehicle-expenses/99999')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El gasto no existe',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| PATCH /api/vehicle-expenses/{vehicleExpense}
|--------------------------------------------------------------------------
*/

it('actualiza un solo campo del gasto y deja los demás intactos', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
        'category' => VehicleExpenseCategory::Tires,
        'nature' => VehicleExpenseNature::Preventive,
        'amount' => 100.00,
        'expense_date' => '2026-07-04',
        'description' => 'Descripción original',
    ]);

    asUser($carrier->owner)->patchJson("/api/vehicle-expenses/{$expense->id}", ['amount' => 250.25])
        ->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Gasto actualizado correctamente',
            'data' => [
                'id' => $expense->id,
                'amount' => '250.25',
                'category' => 'tires',
                'nature' => 'preventive',
                'expenseDate' => '04-07-2026',
                'description' => 'Descripción original',
            ],
        ]);

    $this->assertDatabaseHas('vehicle_expenses', [
        'id' => $expense->id,
        'amount' => 250.25,
        'category' => 'tires',
        'nature' => 'preventive',
        'expense_date' => '2026-07-04',
        'description' => 'Descripción original',
    ]);
});

it('actualiza todos los campos editables del gasto', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    asUser($carrier->owner)->patchJson("/api/vehicle-expenses/{$expense->id}", [
        'category' => VehicleExpenseCategory::Clutch->value,
        'nature' => VehicleExpenseNature::Corrective->value,
        'amount' => 4321.10,
        'expense_date' => '2026-06-30',
        'description' => 'Embrague nuevo',
    ])
        ->assertOk()
        ->assertJson([
            'data' => [
                'category' => 'clutch',
                'nature' => 'corrective',
                'amount' => '4321.10',
                'expenseDate' => '30-06-2026',
                'description' => 'Embrague nuevo',
            ],
        ]);
});

it('rechaza con 422 los valores inválidos al actualizar un gasto', function (array $payload, string $field) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    asUser($carrier->owner)->patchJson("/api/vehicle-expenses/{$expense->id}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'categoría fuera del enum' => [['category' => 'motor_fundido'], 'category'],
    'naturaleza fuera del enum' => [['nature' => 'urgente'], 'nature'],
    'monto cero' => [['amount' => 0], 'amount'],
    'fecha futura' => [['expense_date' => '2027-01-01'], 'expense_date'],
    'descripción demasiado larga' => [['description' => str_repeat('a', 1001)], 'description'],
]);

it('no mueve el gasto de vehículo aunque el body mande el vehicle_id', function (string $key) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);
    $otro = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    asUser($carrier->owner)->patchJson("/api/vehicle-expenses/{$expense->id}", [
        $key => $otro->id,
        'description' => 'Intento de mudanza',
    ])
        ->assertOk()
        ->assertJsonPath('data.vehicleId', $vehicle->id)
        ->assertJsonPath('data.description', 'Intento de mudanza');

    $this->assertDatabaseHas('vehicle_expenses', [
        'id' => $expense->id,
        'vehicle_id' => $vehicle->id,
    ]);
})->with(['vehicle_id', 'vehicleId']);

it('deja registered_by con el usuario original cuando edita un administrador', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->patchJson("/api/vehicle-expenses/{$expense->id}", [
        'description' => 'Corregido por administración',
        'registered_by' => $admin->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.registeredBy', $carrier->owner->name);

    $this->assertDatabaseHas('vehicle_expenses', [
        'id' => $expense->id,
        'registered_by' => $carrier->owner->id,
        'description' => 'Corregido por administración',
    ]);
});

it('responde 200 y no cambia nada con un cuerpo vacío', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
        'amount' => 777.77,
        'description' => 'Sin tocar',
    ]);

    asUser($carrier->owner)->patchJson("/api/vehicle-expenses/{$expense->id}", [])
        ->assertOk()
        ->assertJsonPath('data.amount', '777.77')
        ->assertJsonPath('data.description', 'Sin tocar');

    expect($expense->fresh()->updated_at->eq($expense->updated_at))->toBeTrue();
});

it('devuelve 404 al actualizar un gasto inexistente', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->patchJson('/api/vehicle-expenses/99999', ['description' => 'Fantasma'])
        ->assertNotFound()
        ->assertJsonPath('message', 'El gasto no existe');
});

/*
|--------------------------------------------------------------------------
| DELETE /api/vehicle-expenses/{vehicleExpense}
|--------------------------------------------------------------------------
*/

it('borra de verdad el gasto y devuelve 200 con el recurso eliminado', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    asUser($carrier->owner)->deleteJson("/api/vehicle-expenses/{$expense->id}")
        ->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Gasto eliminado correctamente',
            'data' => ['id' => $expense->id],
        ]);

    $this->assertDatabaseMissing('vehicle_expenses', ['id' => $expense->id]);
    $this->assertDatabaseCount('vehicle_expenses', 0);
});

it('devuelve 404 en el segundo borrado del mismo gasto', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    asUser($carrier->owner)->deleteJson("/api/vehicle-expenses/{$expense->id}")->assertOk();

    asUser($carrier->owner)->deleteJson("/api/vehicle-expenses/{$expense->id}")
        ->assertNotFound()
        ->assertJsonPath('message', 'El gasto no existe');
});

it('borra un gasto de cualquier empresa siendo administrador', function () {
    $expense = VehicleExpense::factory()->create();

    asUser(userWithRole(UserRole::Administrator))->deleteJson("/api/vehicle-expenses/{$expense->id}")
        ->assertOk();

    $this->assertDatabaseMissing('vehicle_expenses', ['id' => $expense->id]);
});

/*
|--------------------------------------------------------------------------
| Ámbito del carrier
|--------------------------------------------------------------------------
*/

it('rechaza con 403 a un carrier que lista o registra sobre un vehículo ajeno', function (string $method, string $uri, bool $conPayload) {
    $carrier = Carrier::factory()->create();
    $ajeno = Vehicle::factory()->create();

    $uri = str_replace('{vehicle}', (string) $ajeno->id, $uri);
    $payload = $conPayload ? validExpensePayload($ajeno->id) : [];

    asUser($carrier->owner)->json($method, $uri, $payload)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No puedes acceder a un vehículo que no pertenece a tu empresa transportista',
            'data' => null,
        ]);
})->with([
    'index' => ['GET', '/api/vehicle-expenses?vehicleId={vehicle}', false],
    'store' => ['POST', '/api/vehicle-expenses', true],
]);

it('rechaza con 403 y no 404 a un carrier que toca un gasto ajeno', function (string $method, array $payload) {
    $carrier = Carrier::factory()->create();
    $ajeno = VehicleExpense::factory()->create();

    asUser($carrier->owner)->json($method, "/api/vehicle-expenses/{$ajeno->id}", $payload)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No puedes acceder a un gasto que no pertenece a tu empresa transportista',
            'data' => null,
        ]);

    $this->assertDatabaseHas('vehicle_expenses', ['id' => $ajeno->id]);
})->with([
    'show' => ['GET', []],
    'update' => ['PATCH', ['description' => 'Secuestrada']],
    'destroy' => ['DELETE', []],
]);

it('deja fuera de los gastos a un piloto aunque su empresa tenga el vehículo', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser(userWithRole(UserRole::Pilot))->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'No tienes permisos para acceder a este recurso');
});

/*
|--------------------------------------------------------------------------
| Factura: alta
|--------------------------------------------------------------------------
*/

it('rechaza con 422 un alta sin is_invoiced, aunque los otros seis campos sean válidos', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $payload = validExpensePayload($vehicle->id);
    unset($payload['is_invoiced']);

    asUser($carrier->owner)->postJson('/api/vehicle-expenses', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_invoiced'])
        ->assertJsonPath('errors.is_invoiced.0', 'Debes indicar si el gasto fue facturado');

    expect(VehicleExpense::query()->count())->toBe(0);
});

it('rechaza con 422 un alta facturada sin archivo', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->postJson('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'is_invoiced' => true,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['invoice'])
        ->assertJsonPath('errors.invoice.0', 'La factura es obligatoria cuando el gasto fue facturado');

    expect(VehicleExpense::query()->count())->toBe(0);
});

it('registra el gasto facturado con los tres formatos aceptados y guarda la key bajo invoices/', function (UploadedFile $file, string $extension) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->post('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'is_invoiced' => true,
        'invoice' => $file,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.isInvoiced', true)
        ->assertJsonPath('data.invoiceType', $extension);

    $key = VehicleExpense::query()->value('invoice');

    expect($key)->toMatch('#^invoices/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.'.$extension.'$#');

    Storage::assertExists($key);
})->with([
    'jpg' => [fn () => UploadedFile::fake()->image('factura.jpg'), 'jpg'],
    'png' => [fn () => UploadedFile::fake()->image('factura.png'), 'png'],
    'pdf' => [fn () => UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf'), 'pdf'],
]);

it('guarda la key y no la URL en la columna invoice', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->post('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'is_invoiced' => true,
        'invoice' => UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf'),
    ]))->assertCreated();

    expect(VehicleExpense::query()->value('invoice'))->toStartWith('invoices/')
        ->not->toContain('http');
});

it('normaliza a jpg la extensión de un jpeg, nunca a jpeg', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->post('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'is_invoiced' => true,
        'invoice' => UploadedFile::fake()->create('factura.exe', 40, 'image/jpeg'),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.invoiceType', 'jpg');

    expect(VehicleExpense::query()->value('invoice'))->toEndWith('.jpg');
});

it('guarda el archivo byte por byte, sin recortarlo ni recomprimirlo', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->post('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'is_invoiced' => true,
        'invoice' => UploadedFile::fake()->image('factura.png', 1600, 900),
    ]))->assertCreated();

    $size = getimagesizefromstring(Storage::get(VehicleExpense::query()->value('invoice')));

    expect($size[0])->toBe(1600)
        ->and($size[1])->toBe(900);
});

it('registra el gasto no facturado sin archivo y deja invoice en null', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->postJson('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'is_invoiced' => false,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.isInvoiced', false)
        ->assertJsonPath('data.invoiceUrl', null)
        ->assertJsonPath('data.invoiceType', null);

    expect(VehicleExpense::query()->value('invoice'))->toBeNull();
});

it('descarta en silencio el archivo cuando is_invoiced es falso y no sube nada al bucket', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->post('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'is_invoiced' => false,
        'invoice' => UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf'),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.isInvoiced', false)
        ->assertJsonPath('data.invoiceUrl', null);

    expect(VehicleExpense::query()->value('invoice'))->toBeNull()
        ->and(Storage::allFiles())->toBe([]);
});

it('no valida el archivo cuando is_invoiced es falso, ni siquiera uno prohibido o enorme', function (UploadedFile $file) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->post('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'is_invoiced' => false,
        'invoice' => $file,
    ]))->assertCreated();

    expect(Storage::allFiles())->toBe([]);
})->with([
    'ejecutable' => [fn () => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')],
    'demasiado grande' => [fn () => UploadedFile::fake()->create('enorme.pdf', 4096, 'application/pdf')],
]);

it('rechaza con 422 una factura de un tipo no permitido', function (UploadedFile $file) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->post('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'is_invoiced' => true,
        'invoice' => $file,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['invoice'])
        ->assertJsonPath('errors.invoice.0', 'La factura debe ser un archivo jpg, jpeg, png o pdf');

    expect(Storage::allFiles())->toBe([]);
})->with([
    'exe' => [fn () => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')],
    'docx' => [fn () => UploadedFile::fake()->create('factura.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
    'gif' => [fn () => UploadedFile::fake()->create('factura.gif', 10, 'image/gif')],
]);

it('rechaza con 422 una factura de más de 3 MB', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->post('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'is_invoiced' => true,
        'invoice' => UploadedFile::fake()->create('grande.pdf', 4096, 'application/pdf'),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['invoice'])
        ->assertJsonPath('errors.invoice.0', 'La factura no puede pesar más de 3 MB');
});

it('acepta una factura de 3072 KB justos, porque el límite es inclusivo', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->post('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'is_invoiced' => true,
        'invoice' => UploadedFile::fake()->create('justa.pdf', 3072, 'application/pdf'),
    ]))->assertCreated();
});

it('acepta las representaciones booleanas de is_invoiced que manda un formulario', function (mixed $value, bool $stored) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $payload = validExpensePayload($vehicle->id, ['is_invoiced' => $value]);

    if ($stored) {
        $payload['invoice'] = UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf');
    }

    asUser($carrier->owner)->post('/api/vehicle-expenses', $payload)
        ->assertCreated()
        ->assertJsonPath('data.isInvoiced', $stored);

    expect(VehicleExpense::query()->value('is_invoiced'))->toBe($stored);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    'uno como cadena' => ['1', true],
    'cero como cadena' => ['0', false],
]);

it('rechaza con 422 un is_invoiced que no es booleano', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->postJson('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'is_invoiced' => 'quizá',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_invoiced'])
        ->assertJsonPath('errors.is_invoiced.0', 'La facturación debe ser verdadero o falso');
});

it('no deja archivos subidos cuando un carrier registra sobre un vehículo ajeno', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = Vehicle::factory()->create();

    asUser($carrier->owner)->post('/api/vehicle-expenses', validExpensePayload($ajeno->id, [
        'is_invoiced' => true,
        'invoice' => UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf'),
    ]))
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes acceder a un vehículo que no pertenece a tu empresa transportista');

    expect(Storage::allFiles())->toBe([])
        ->and(VehicleExpense::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Factura: inmutabilidad
|--------------------------------------------------------------------------
*/

it('ignora en silencio is_invoiced en el PATCH y responde 200 sin cambiar el valor', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
        'is_invoiced' => false,
    ]);

    asUser($carrier->owner)->patchJson("/api/vehicle-expenses/{$expense->id}", ['is_invoiced' => true])
        ->assertOk()
        ->assertJsonPath('data.isInvoiced', false);

    expect($expense->fresh()->is_invoiced)->toBeFalse();
});

it('ignora en silencio un archivo mandado en el PATCH y no sube nada al bucket', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    asUser($carrier->owner)->patch("/api/vehicle-expenses/{$expense->id}", [
        'invoice' => UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf'),
    ])
        ->assertOk()
        ->assertJsonPath('data.invoiceUrl', null);

    expect($expense->fresh()->invoice)->toBeNull()
        ->and(Storage::allFiles())->toBe([]);
});

it('deja la facturación intacta al editar el monto o la descripción', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->invoiced()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    $key = $expense->invoice;

    asUser($carrier->owner)->patchJson("/api/vehicle-expenses/{$expense->id}", [
        'amount' => 999.99,
        'description' => 'Descripción corregida',
    ])
        ->assertOk()
        ->assertJsonPath('data.isInvoiced', true);

    expect($expense->fresh()->is_invoiced)->toBeTrue()
        ->and($expense->fresh()->invoice)->toBe($key);
});

/*
|--------------------------------------------------------------------------
| Factura: salida del recurso
|--------------------------------------------------------------------------
*/

it('devuelve las tres claves de factura en el listado y en el detalle', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->invoiced()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}")
        ->assertOk()
        ->assertJsonStructure(['data' => [['isInvoiced', 'invoiceUrl', 'invoiceType']]])
        ->assertJsonPath('data.0.isInvoiced', true)
        ->assertJsonPath('data.0.invoiceType', 'pdf');

    asUser($carrier->owner)->getJson("/api/vehicle-expenses/{$expense->id}")
        ->assertOk()
        ->assertJsonPath('data.isInvoiced', true)
        ->assertJsonPath('data.invoiceType', 'pdf')
        ->assertJsonPath('data.invoiceUrl', fn (?string $url): bool => is_string($url) && str_contains($url, $expense->invoice));
});

it('devuelve isInvoiced como booleano JSON y no como 1 o 0', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->invoiced()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    $value = asUser($carrier->owner)->getJson("/api/vehicle-expenses/{$expense->id}")
        ->assertOk()
        ->json('data.isInvoiced');

    expect($value)->toBeBool()->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Factura: filtro isInvoiced
|--------------------------------------------------------------------------
*/

it('filtra el listado por isInvoiced y acumula solo lo filtrado en totalAmount', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    VehicleExpense::factory()->invoiced()->count(2)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
        'amount' => 100,
    ]);

    VehicleExpense::factory()->count(3)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
        'amount' => 10,
    ]);

    asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}&isInvoiced=true")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('totalAmount', '200.00');

    asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}&isInvoiced=false")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('totalAmount', '30.00');

    asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}")
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('totalAmount', '230.00');
});

it('ignora un isInvoiced ilegible y devuelve el listado completo', function (string $value) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    VehicleExpense::factory()->invoiced()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    asUser($carrier->owner)->getJson("/api/vehicle-expenses?vehicleId={$vehicle->id}&isInvoiced={$value}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
})->with(['quizá', 'vacío' => '', '2', 'null']);

/*
|--------------------------------------------------------------------------
| Factura: borrado
|--------------------------------------------------------------------------
*/

it('borra el objeto del bucket junto con el gasto facturado', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->post('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'is_invoiced' => true,
        'invoice' => UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf'),
    ]))->assertCreated();

    $expense = VehicleExpense::query()->firstOrFail();

    Storage::assertExists($expense->invoice);

    asUser($carrier->owner)->deleteJson("/api/vehicle-expenses/{$expense->id}")
        ->assertOk();

    Storage::assertMissing($expense->invoice);

    $this->assertDatabaseMissing('vehicle_expenses', ['id' => $expense->id]);
});

it('borra un gasto no facturado sin intentar tocar el bucket', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    asUser($carrier->owner)->deleteJson("/api/vehicle-expenses/{$expense->id}")
        ->assertOk();

    $this->assertDatabaseMissing('vehicle_expenses', ['id' => $expense->id]);
});

it('responde 200 al borrar aunque la limpieza del archivo falle', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->invoiced()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    /** La key nunca se subió, así que delete() devuelve false: la fila igual desaparece y la respuesta sigue siendo 200. */
    asUser($carrier->owner)->deleteJson("/api/vehicle-expenses/{$expense->id}")
        ->assertOk();

    $this->assertDatabaseMissing('vehicle_expenses', ['id' => $expense->id]);

    asUser($carrier->owner)->deleteJson("/api/vehicle-expenses/{$expense->id}")
        ->assertNotFound();
});

it('propaga como 400 el fallo del almacenamiento al subir la factura', function () {
    app()->instance(FileStorageServiceInterface::class, new InMemoryFileStorageService(failing: true));

    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->post('/api/vehicle-expenses', validExpensePayload($vehicle->id, [
        'is_invoiced' => true,
        'invoice' => UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf'),
    ]))
        ->assertStatus(400)
        ->assertJsonPath('message', 'No se pudo almacenar el archivo');

    expect(VehicleExpense::query()->count())->toBe(0);
});
