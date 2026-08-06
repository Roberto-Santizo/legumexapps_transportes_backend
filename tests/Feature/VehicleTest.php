<?php

use App\Enums\UserRole;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Interfaces\Storage\FileStorageServiceInterface;
use App\Interfaces\Storage\ImageProcessorServiceInterface;
use App\Models\Carrier;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Doubles\InMemoryFileStorageService;
use Tests\Doubles\StaticImageProcessorService;
use Tests\TestCase;

/**
 * Every vehicles endpoint as method and URI, for the middleware datasets.
 *
 * @return array<string, array{string, string}>
 */
function vehicleEndpoints(): array
{
    return [
        'index' => ['GET', '/api/vehicles'],
        'store' => ['POST', '/api/vehicles'],
        'show' => ['GET', '/api/vehicles/1'],
        'update' => ['PATCH', '/api/vehicles/1'],
        'destroy' => ['DELETE', '/api/vehicles/1'],
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
 * A valid store payload, with the image as an uploaded file.
 *
 * @return array<string, mixed>
 */
function validVehiclePayload(array $overrides = []): array
{
    return array_merge([
        'plate' => 'P123ABC',
        'brand' => 'Kenworth',
        'model' => 'T680',
        'year' => 2020,
        'capacity' => 15000.5,
        'type' => VehicleType::Truck->value,
        'image' => UploadedFile::fake()->image('camion.png'),
    ], $overrides);
}

/**
 * The ids returned inside the data key of a listing response.
 *
 * @return array<int, int>
 */
function listedVehicleIds(array $data): array
{
    return collect($data)->pluck('id')->all();
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth, role y carrier.required
|--------------------------------------------------------------------------
*/

it('rechaza con 401 cualquier endpoint de vehículos sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(vehicleEndpoints());

it('rechaza con 403 a un piloto en cualquier endpoint de vehículos', function (string $method, string $uri) {
    asUser(userWithRole(UserRole::Pilot))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(vehicleEndpoints());

it('rechaza con 403 a un manager en cualquier endpoint de vehículos', function (string $method, string $uri) {
    asUser(userWithRole(UserRole::Manager))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(vehicleEndpoints());

it('bloquea con carrier.required a un carrier sin empresa', function (string $method, string $uri) {
    asUser(userWithRole(UserRole::Carrier))->json($method, $uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'Debes estar vinculado a un transportista para acceder a este recurso',
            'data' => null,
        ]);
})->with(vehicleEndpoints());

it('deja pasar carrier.required a un administrador sin empresa', function () {
    Vehicle::factory()->count(2)->create();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/vehicles')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('rechaza con 403 a un administrador que intenta registrar un vehículo', function () {
    Carrier::factory()->create();

    asUser(userWithRole(UserRole::Administrator))
        ->post('/api/vehicles', validVehiclePayload())
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);

    $this->assertDatabaseCount('vehicles', 0);
});

/*
|--------------------------------------------------------------------------
| POST /api/vehicles
|--------------------------------------------------------------------------
*/

it('registra el vehículo de un carrier y devuelve 201 con el recurso', function () {
    $carrier = Carrier::factory()->create(['name' => 'Transportes del Norte']);

    asUser($carrier->owner)->post('/api/vehicles', validVehiclePayload())
        ->assertCreated()
        ->assertJson([
            'statusCode' => 201,
            'message' => 'Vehículo registrado correctamente',
            'data' => [
                'plate' => 'P123ABC',
                'brand' => 'Kenworth',
                'model' => 'T680',
                'year' => 2020,
                'capacity' => '15000.50',
                'type' => 'truck',
                'status' => 'active',
                'carrierName' => 'Transportes del Norte',
            ],
        ])
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => ['id', 'plate', 'brand', 'model', 'year', 'capacity', 'type', 'image', 'status', 'carrierName'],
        ]);

    $this->assertDatabaseHas('vehicles', [
        'carrier_id' => $carrier->id,
        'plate' => 'P123ABC',
        'brand' => 'Kenworth',
        'model' => 'T680',
        'year' => 2020,
        'capacity' => 15000.5,
        'type' => 'truck',
        'status' => 'active',
    ]);
});

it('persiste la placa en mayúsculas aunque llegue en minúsculas', function () {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)->post('/api/vehicles', validVehiclePayload(['plate' => 'p123abc']))
        ->assertCreated()
        ->assertJsonPath('data.plate', 'P123ABC');

    $this->assertDatabaseHas('vehicles', ['carrier_id' => $carrier->id, 'plate' => 'P123ABC']);
});

it('ignora el status enviado al registrar y nace siempre en active', function () {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)
        ->post('/api/vehicles', validVehiclePayload(['status' => VehicleStatus::UnderRepair->value]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('vehicles', ['plate' => 'P123ABC', 'status' => 'active']);
});

it('valida los campos obligatorios al registrar un vehículo', function (string $field) {
    $carrier = Carrier::factory()->create();

    $payload = validVehiclePayload();
    unset($payload[$field]);

    asUser($carrier->owner)->post('/api/vehicles', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    $this->assertDatabaseCount('vehicles', 0);
})->with(['plate', 'brand', 'model', 'year', 'capacity', 'type', 'image']);

it('rechaza con 422 los valores inválidos al registrar un vehículo', function (array $overrides, string $field) {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)->post('/api/vehicles', validVehiclePayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    $this->assertDatabaseCount('vehicles', 0);
})->with([
    'tipo fuera del enum' => [['type' => 'helicoptero'], 'type'],
    'año anterior a 1900' => [['year' => 1800], 'year'],
    'año no entero' => [['year' => 'dos mil'], 'year'],
    'capacidad negativa' => [['capacity' => -5], 'capacity'],
    'capacidad no numérica' => [['capacity' => 'mucha'], 'capacity'],
    'placa demasiado larga' => [['plate' => 'P123ABC456DEF789'], 'plate'],
]);

it('rechaza con 422 una imagen que no es jpg, jpeg ni png', function () {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)->post('/api/vehicles', validVehiclePayload([
        'image' => UploadedFile::fake()->create('documento.pdf', 10, 'application/pdf'),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image']);

    $this->assertDatabaseCount('vehicles', 0);
});

it('rechaza con 422 una imagen de más de 3 MB al crear el vehículo', function () {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)->post('/api/vehicles', validVehiclePayload([
        'image' => UploadedFile::fake()->create('grande.jpg', 4096, 'image/jpeg'),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image'])
        ->assertJsonPath('errors.image.0', 'La imagen no puede pesar más de 3 MB');

    $this->assertDatabaseCount('vehicles', 0);

    expect(Storage::allFiles())->toBeEmpty();
});

it('rechaza con 422 una imagen de más de 3 MB al actualizar el vehículo', function () {
    $vehicle = Vehicle::factory()->create();

    asUser($vehicle->carrier->owner)->patch("/api/vehicles/{$vehicle->id}", [
        'image' => UploadedFile::fake()->create('grande.jpg', 4096, 'image/jpeg'),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image'])
        ->assertJsonPath('errors.image.0', 'La imagen no puede pesar más de 3 MB');

    expect(Storage::allFiles())->toBeEmpty();
});

it('acepta una imagen de 3 MB justos al crear el vehículo, porque el límite es inclusivo', function () {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)->post('/api/vehicles', validVehiclePayload([
        'image' => UploadedFile::fake()->image('justa.png', 800, 800)->size(3072),
    ]))
        ->assertCreated();

    $this->assertDatabaseCount('vehicles', 1);
});

it('sube la imagen y guarda la key con el prefijo del dominio', function () {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)
        ->post('/api/vehicles', validVehiclePayload(['image' => UploadedFile::fake()->image('camion.jpg', 1600, 900)]))
        ->assertCreated();

    $key = Vehicle::query()->value('image');

    expect($key)->toMatch('#^vehicles/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.jpg$#');

    Storage::assertExists($key);
});

it('recorta a 800x800 la imagen que sube al disco', function () {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)
        ->post('/api/vehicles', validVehiclePayload(['image' => UploadedFile::fake()->image('camion.jpg', 1600, 900)]))
        ->assertCreated();

    $size = getimagesizefromstring(Storage::get(Vehicle::query()->value('image')));

    expect($size[0])->toBe(800)
        ->and($size[1])->toBe(800);
});

it('devuelve la imagen como URL absoluta, no como la key cruda', function () {
    $carrier = Carrier::factory()->create();

    $image = asUser($carrier->owner)
        ->post('/api/vehicles', validVehiclePayload())
        ->assertCreated()
        ->json('data.image');

    expect($image)->toStartWith('http')
        ->toEndWith(Vehicle::query()->value('image'));
});

it('devuelve image en null cuando el vehículo no tiene imagen', function () {
    $vehicle = Vehicle::factory()->create(['image' => null]);

    asUser($vehicle->carrier->owner)
        ->getJson("/api/vehicles/{$vehicle->id}")
        ->assertOk()
        ->assertJsonPath('data.image', null);
});

it('devuelve 400 y no crea la fila cuando el almacenamiento falla', function () {
    app()->instance(FileStorageServiceInterface::class, new InMemoryFileStorageService(failing: true));

    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)
        ->post('/api/vehicles', validVehiclePayload())
        ->assertBadRequest()
        ->assertJson(['message' => 'No se pudo almacenar la imagen']);

    $this->assertDatabaseCount('vehicles', 0);
});

it('devuelve 400 sin escribir en el disco cuando el procesado falla', function () {
    app()->instance(ImageProcessorServiceInterface::class, new StaticImageProcessorService(failing: true));

    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)
        ->post('/api/vehicles', validVehiclePayload())
        ->assertBadRequest()
        ->assertJson(['message' => 'No se pudo procesar la imagen']);

    $this->assertDatabaseCount('vehicles', 0);

    /** El procesado va antes que la subida: nada llegó al bucket. */
    expect(Storage::allFiles())->toBeEmpty();
});

it('rechaza con 400 una placa que ya usa un vehículo no desactivado', function (VehicleStatus $status, bool $mismaEmpresa) {
    $carrier = Carrier::factory()->create();
    $dueña = $mismaEmpresa ? $carrier : Carrier::factory()->create();

    Vehicle::factory()->create([
        'carrier_id' => $dueña->id,
        'plate' => 'P123ABC',
        'status' => $status,
    ]);

    asUser($carrier->owner)->post('/api/vehicles', validVehiclePayload(['plate' => 'p123abc']))
        ->assertBadRequest()
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'La placa ya está registrada en un vehículo que no está desactivado',
            'data' => null,
        ]);

    $this->assertDatabaseCount('vehicles', 1);
})->with([
    'activo de la misma empresa' => [VehicleStatus::Active, true],
    'activo de otra empresa' => [VehicleStatus::Active, false],
    'en reparación de la misma empresa' => [VehicleStatus::UnderRepair, true],
    'en reparación de otra empresa' => [VehicleStatus::UnderRepair, false],
]);

it('permite registrar una placa cuyos únicos portadores están desactivados', function () {
    $otra = Carrier::factory()->create();
    $carrier = Carrier::factory()->create();

    Vehicle::factory()->create([
        'carrier_id' => $otra->id,
        'plate' => 'P123ABC',
        'status' => VehicleStatus::Inactive,
    ]);

    asUser($carrier->owner)->post('/api/vehicles', validVehiclePayload(['plate' => 'P123ABC']))
        ->assertCreated();

    expect(Vehicle::query()->where('plate', '=', 'P123ABC')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| GET /api/vehicles
|--------------------------------------------------------------------------
*/

it('devuelve a un carrier solo los vehículos de su empresa', function () {
    $carrier = Carrier::factory()->create();
    $otra = Carrier::factory()->create();

    $propios = Vehicle::factory()->count(2)->create(['carrier_id' => $carrier->id]);
    $ajeno = Vehicle::factory()->create(['carrier_id' => $otra->id]);

    $response = asUser($carrier->owner)->getJson('/api/vehicles');

    $response->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Vehículos obtenidos correctamente',
        ])
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => [['id', 'plate', 'brand', 'model', 'year', 'capacity', 'type', 'image', 'status', 'carrierName']],
        ]);

    expect(listedVehicleIds($response->json('data')))
        ->toEqualCanonicalizing($propios->pluck('id')->all())
        ->not->toContain($ajeno->id);
});

it('devuelve a un administrador los vehículos de todas las empresas', function () {
    $vehicles = collect([
        Vehicle::factory()->create(),
        Vehicle::factory()->create(),
        Vehicle::factory()->create(),
    ]);

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/vehicles');

    $response->assertOk()->assertJsonCount(3, 'data');

    expect(listedVehicleIds($response->json('data')))->toEqualCanonicalizing($vehicles->pluck('id')->all());
});

it('incluye los vehículos desactivados y en reparación cuando no se filtra por status', function () {
    $carrier = Carrier::factory()->create();

    foreach (VehicleStatus::cases() as $status) {
        Vehicle::factory()->create(['carrier_id' => $carrier->id, 'status' => $status]);
    }

    $response = asUser($carrier->owner)->getJson('/api/vehicles');

    $response->assertOk()->assertJsonCount(3, 'data');

    expect(collect($response->json('data'))->pluck('status')->all())
        ->toEqualCanonicalizing(['active', 'inactive', 'under_repair']);
});

it('filtra el listado por un status válido del enum', function () {
    $carrier = Carrier::factory()->create();

    Vehicle::factory()->count(2)->create(['carrier_id' => $carrier->id, 'status' => VehicleStatus::Active]);
    $enReparacion = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'status' => VehicleStatus::UnderRepair]);

    asUser($carrier->owner)->getJson('/api/vehicles?status=under_repair')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $enReparacion->id)
        ->assertJsonPath('data.0.status', 'under_repair');
});

it('ignora sin error un status que no pertenece al enum', function () {
    $carrier = Carrier::factory()->create();

    Vehicle::factory()->count(3)->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->getJson('/api/vehicles?status=cualquiercosa')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('filtra por carrierId cuando lo pide un administrador', function () {
    $unaEmpresa = Carrier::factory()->create();
    $otraEmpresa = Carrier::factory()->create();

    $suyos = Vehicle::factory()->count(2)->create(['carrier_id' => $unaEmpresa->id]);
    Vehicle::factory()->count(3)->create(['carrier_id' => $otraEmpresa->id]);

    $response = asUser(userWithRole(UserRole::Administrator))
        ->getJson("/api/vehicles?carrierId={$unaEmpresa->id}");

    $response->assertOk()->assertJsonCount(2, 'data');

    expect(listedVehicleIds($response->json('data')))->toEqualCanonicalizing($suyos->pluck('id')->all());
});

it('ignora el carrierId cuando lo manda un carrier', function () {
    $carrier = Carrier::factory()->create();
    $otra = Carrier::factory()->create();

    $propios = Vehicle::factory()->count(2)->create(['carrier_id' => $carrier->id]);
    Vehicle::factory()->count(3)->create(['carrier_id' => $otra->id]);

    $response = asUser($carrier->owner)->getJson("/api/vehicles?carrierId={$otra->id}");

    $response->assertOk()->assertJsonCount(2, 'data');

    expect(listedVehicleIds($response->json('data')))->toEqualCanonicalizing($propios->pluck('id')->all());
});

it('ignora sin error un carrierId que no es numérico', function () {
    Vehicle::factory()->count(3)->create();

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/vehicles?carrierId=abc')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('devuelve el nombre de la empresa de cada vehículo del listado', function () {
    $unaEmpresa = Carrier::factory()->create(['name' => 'Transportes del Norte']);
    $otraEmpresa = Carrier::factory()->create(['name' => 'Transportes del Sur']);

    Vehicle::factory()->create(['carrier_id' => $unaEmpresa->id]);
    Vehicle::factory()->create(['carrier_id' => $otraEmpresa->id]);

    $response = asUser(userWithRole(UserRole::Administrator))->getJson('/api/vehicles');

    expect(collect($response->assertOk()->json('data'))->pluck('carrierName')->all())
        ->toEqualCanonicalizing(['Transportes del Norte', 'Transportes del Sur']);
});

it('no dispara N+1 al listar vehículos de muchas empresas', function () {
    Vehicle::factory()->count(20)->create();

    /** El token se emite antes de escuchar: sus claims consultan la empresa del usuario. */
    $token = JWTAuth::fromUser(userWithRole(UserRole::Administrator));

    resetAuthState();

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withToken($token)->getJson('/api/vehicles')->assertOk()->assertJsonCount(20, 'data');

    $sobreVehiculos = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "vehicles"'));
    $sobreEmpresas = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "carriers"'));

    expect($sobreVehiculos)->toHaveCount(1)
        ->and($sobreEmpresas)->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Paginación
|--------------------------------------------------------------------------
*/

it('devuelve todos los vehículos y ninguna clave de paginación sin limit', function () {
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->count(12)->create(['carrier_id' => $carrier->id]);

    $response = asUser($carrier->owner)->getJson('/api/vehicles');

    $response->assertOk()->assertJsonCount(12, 'data');

    expect($response->json())->not->toHaveKeys(['total', 'currentPage', 'lastPage']);
});

it('devuelve el sobre paginado de vehículos con limit numérico', function () {
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->count(12)->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->getJson('/api/vehicles?limit=10')
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
            'data' => [['id', 'plate', 'brand', 'model', 'year', 'capacity', 'type', 'image', 'status', 'carrierName']],
            'total',
            'currentPage',
            'lastPage',
        ]);
});

it('devuelve todos los vehículos sin error cuando limit no es numérico', function () {
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->count(12)->create(['carrier_id' => $carrier->id]);

    $response = asUser($carrier->owner)->getJson('/api/vehicles?limit=abc');

    $response->assertOk()->assertJsonCount(12, 'data');

    expect($response->json())->not->toHaveKey('total');
});

it('acota el limit inferior de vehículos a 10 por página', function () {
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->count(12)->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->getJson('/api/vehicles?limit=3')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('lastPage', 2);
});

it('acota el limit superior de vehículos a 100 por página', function () {
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->count(101)->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->getJson('/api/vehicles?limit=500')
        ->assertOk()
        ->assertJsonCount(100, 'data')
        ->assertJsonPath('total', 101)
        ->assertJsonPath('lastPage', 2);
});

it('aplica a la vez el filtro de status y la paginación', function () {
    $carrier = Carrier::factory()->create();

    Vehicle::factory()->count(12)->create(['carrier_id' => $carrier->id, 'status' => VehicleStatus::UnderRepair]);
    Vehicle::factory()->count(5)->create(['carrier_id' => $carrier->id, 'status' => VehicleStatus::Active]);

    $response = asUser($carrier->owner)->getJson('/api/vehicles?status=under_repair&limit=10');

    $response->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('total', 12)
        ->assertJsonPath('lastPage', 2);

    expect(collect($response->json('data'))->pluck('status')->unique()->values()->all())->toBe(['under_repair']);
});

/*
|--------------------------------------------------------------------------
| GET /api/vehicles/{vehicle}
|--------------------------------------------------------------------------
*/

it('devuelve a un carrier un vehículo de su empresa', function () {
    $carrier = Carrier::factory()->create(['name' => 'Transportes del Norte']);
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'plate' => 'P123ABC',
        'status' => VehicleStatus::Active,
    ]);

    asUser($carrier->owner)->getJson("/api/vehicles/{$vehicle->id}")
        ->assertOk()
        ->assertExactJson([
            'statusCode' => 200,
            'message' => 'Vehículo obtenido correctamente',
            'data' => [
                'id' => $vehicle->id,
                'plate' => 'P123ABC',
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'year' => $vehicle->year,
                'capacity' => $vehicle->capacity,
                'type' => $vehicle->type->value,
                'image' => null,
                'status' => 'active',
                'carrierName' => 'Transportes del Norte',
            ],
        ]);
});

it('rechaza con 403 a un carrier que pide un vehículo de otra empresa', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = Vehicle::factory()->create();

    asUser($carrier->owner)->getJson("/api/vehicles/{$ajeno->id}")
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No puedes acceder a un vehículo que no pertenece a tu empresa transportista',
            'data' => null,
        ]);
});

it('devuelve a un administrador el vehículo de cualquier empresa', function () {
    $vehicle = Vehicle::factory()->create();

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/vehicles/{$vehicle->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $vehicle->id)
        ->assertJsonPath('data.carrierName', $vehicle->carrier->name);
});

it('devuelve 404 al pedir un vehículo que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))->getJson('/api/vehicles/99999')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El vehículo no existe',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| PUT|PATCH /api/vehicles/{vehicle}
|--------------------------------------------------------------------------
*/

it('actualiza los datos del vehículo propio', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'brand' => 'Hino',
        'type' => VehicleType::Van,
    ]);

    $response = asUser($carrier->owner)->patch("/api/vehicles/{$vehicle->id}", [
        'brand' => 'Volvo',
        'model' => 'FH-16',
        'year' => 2024,
        'capacity' => 32000,
        'type' => VehicleType::Trailer->value,
        'image' => UploadedFile::fake()->image('nuevo.jpg'),
    ]);

    $response->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Vehículo actualizado correctamente',
            'data' => [
                'id' => $vehicle->id,
                'brand' => 'Volvo',
                'model' => 'FH-16',
                'year' => 2024,
                'capacity' => '32000.00',
                'type' => 'trailer',
            ],
        ]);

    expect($response->json('data.image'))->toEndWith('.jpg');

    $this->assertDatabaseHas('vehicles', [
        'id' => $vehicle->id,
        'brand' => 'Volvo',
        'model' => 'FH-16',
        'year' => 2024,
        'capacity' => 32000,
        'type' => 'trailer',
    ]);
});

it('cambia el status del vehículo propio a under_repair', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->patchJson("/api/vehicles/{$vehicle->id}", [
        'status' => VehicleStatus::UnderRepair->value,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'under_repair');

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'under_repair']);
});

it('actualiza la placa a una libre y la persiste en mayúsculas', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'plate' => 'P123ABC']);

    asUser($carrier->owner)->patchJson("/api/vehicles/{$vehicle->id}", ['plate' => 'z999xyz'])
        ->assertOk()
        ->assertJsonPath('data.plate', 'Z999XYZ');

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'plate' => 'Z999XYZ']);
});

it('rechaza con 400 la placa que ya usa un vehículo activo de otra empresa', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'plate' => 'P123ABC']);

    Vehicle::factory()->create(['plate' => 'Z999XYZ', 'status' => VehicleStatus::Active]);

    asUser($carrier->owner)->patchJson("/api/vehicles/{$vehicle->id}", ['plate' => 'Z999XYZ'])
        ->assertBadRequest()
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'La placa ya está registrada en un vehículo que no está desactivado',
            'data' => null,
        ]);

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'plate' => 'P123ABC']);
});

it('acepta que el vehículo reenvíe su propia placa sin colisionar consigo mismo', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'plate' => 'P123ABC']);

    asUser($carrier->owner)->patchJson("/api/vehicles/{$vehicle->id}", [
        'plate' => 'p123abc',
        'brand' => 'Volvo',
    ])
        ->assertOk()
        ->assertJsonPath('data.plate', 'P123ABC')
        ->assertJsonPath('data.brand', 'Volvo');
});

it('rechaza con 400 reactivar un vehículo cuya placa ya tomó otro vehículo no desactivado', function (array $payload) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'plate' => 'P123ABC',
        'status' => VehicleStatus::Inactive,
    ]);

    Vehicle::factory()->create(['plate' => 'P123ABC', 'status' => VehicleStatus::Active]);

    asUser($carrier->owner)->patchJson("/api/vehicles/{$vehicle->id}", $payload)
        ->assertBadRequest()
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'No puedes reactivar este vehículo: su placa ya está registrada en otro vehículo que no está desactivado',
            'data' => null,
        ]);

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'inactive']);
})->with([
    'solo el status' => [['status' => 'active']],
    'reenviando su propia placa' => [['status' => 'active', 'plate' => 'p123abc']],
    'volviendo a under_repair' => [['status' => 'under_repair']],
]);

it('permite reactivar un vehículo cuya placa sigue libre', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'plate' => 'P123ABC',
        'status' => VehicleStatus::Inactive,
    ]);

    asUser($carrier->owner)->patchJson("/api/vehicles/{$vehicle->id}", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'active']);
});

it('permite actualizar un vehículo desactivado que sigue desactivado aunque su placa esté tomada', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'plate' => 'P123ABC',
        'status' => VehicleStatus::Inactive,
    ]);

    Vehicle::factory()->create(['plate' => 'P123ABC', 'status' => VehicleStatus::Active]);

    asUser($carrier->owner)->patchJson("/api/vehicles/{$vehicle->id}", ['brand' => 'Volvo'])
        ->assertOk()
        ->assertJsonPath('data.brand', 'Volvo')
        ->assertJsonPath('data.status', 'inactive');
});

it('rechaza con 403 a un carrier que actualiza un vehículo de otra empresa', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = Vehicle::factory()->create(['brand' => 'Intacta']);

    asUser($carrier->owner)->patchJson("/api/vehicles/{$ajeno->id}", ['brand' => 'Secuestrada'])
        ->assertForbidden()
        ->assertJson(['message' => 'No puedes acceder a un vehículo que no pertenece a tu empresa transportista']);

    $this->assertDatabaseHas('vehicles', ['id' => $ajeno->id, 'brand' => 'Intacta']);
});

it('no sube ningún archivo cuando el carrier manda una imagen a un vehículo ajeno', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = Vehicle::factory()->create();

    asUser($carrier->owner)->patch("/api/vehicles/{$ajeno->id}", ['image' => UploadedFile::fake()->image('camion.jpg')])
        ->assertForbidden();

    /** El ámbito se comprueba antes de subir: si no, cualquier usuario autenticado podría llenar el bucket a base de 403. */
    expect(Storage::allFiles())->toBeEmpty();
});

it('al reemplazar la imagen sube la nueva y borra la anterior del disco', function () {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)->post('/api/vehicles', validVehiclePayload())->assertCreated();

    $vehicle = Vehicle::query()->first();
    $anterior = $vehicle->image;

    asUser($carrier->owner)->patch("/api/vehicles/{$vehicle->id}", ['image' => UploadedFile::fake()->image('nuevo.jpg')])
        ->assertOk();

    $nueva = $vehicle->fresh()->image;

    expect($nueva)->not->toBe($anterior)
        ->toStartWith('vehicles/')
        ->toEndWith('.jpg');

    Storage::assertExists($nueva);
    Storage::assertMissing($anterior);
});

it('al actualizar sin imagen deja la key y el archivo intactos', function () {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)->post('/api/vehicles', validVehiclePayload())->assertCreated();

    $vehicle = Vehicle::query()->first();
    $key = $vehicle->image;

    asUser($carrier->owner)->patchJson("/api/vehicles/{$vehicle->id}", ['brand' => 'Hino'])->assertOk();

    expect($vehicle->fresh()->image)->toBe($key);

    Storage::assertExists($key);
});

it('permite a un administrador actualizar el vehículo de cualquier empresa', function () {
    $vehicle = Vehicle::factory()->create(['brand' => 'Hino']);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/vehicles/{$vehicle->id}", ['brand' => 'Corregida'])
        ->assertOk()
        ->assertJsonPath('data.brand', 'Corregida');

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'brand' => 'Corregida']);
});

it('valida los campos enviados al actualizar un vehículo', function (array $payload, string $field) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->patchJson("/api/vehicles/{$vehicle->id}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'status fuera del enum' => [['status' => 'destruido'], 'status'],
    'tipo fuera del enum' => [['type' => 'helicoptero'], 'type'],
    'placa vacía' => [['plate' => ''], 'plate'],
    'año anterior a 1900' => [['year' => 1800], 'year'],
    'capacidad negativa' => [['capacity' => -1], 'capacity'],
    'imagen que no es archivo' => [['image' => 'camion.png'], 'image'],
]);

it('devuelve 404 al actualizar un vehículo que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->patchJson('/api/vehicles/99999', ['brand' => 'Fantasma'])
        ->assertNotFound()
        ->assertJson(['message' => 'El vehículo no existe']);
});

/*
|--------------------------------------------------------------------------
| DELETE /api/vehicles/{vehicle}
|--------------------------------------------------------------------------
*/

it('desactiva el vehículo sin borrar la fila', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->deleteJson("/api/vehicles/{$vehicle->id}")
        ->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Vehículo desactivado correctamente',
            'data' => [
                'id' => $vehicle->id,
                'status' => 'inactive',
            ],
        ]);

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'inactive']);
    $this->assertDatabaseCount('vehicles', 1);
});

it('deja el archivo en el disco al desactivar el vehículo', function () {
    $carrier = Carrier::factory()->create();

    asUser($carrier->owner)->post('/api/vehicles', validVehiclePayload())->assertCreated();

    $vehicle = Vehicle::query()->first();

    asUser($carrier->owner)->deleteJson("/api/vehicles/{$vehicle->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    /** La fila sigue viva y sigue apareciendo en los listados, así que su imagen tiene que seguir resolviendo. */
    Storage::assertExists($vehicle->image);
});

it('sigue mostrando en el listado el vehículo desactivado', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    asUser($carrier->owner)->deleteJson("/api/vehicles/{$vehicle->id}")->assertOk();

    asUser($carrier->owner)->getJson('/api/vehicles')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $vehicle->id)
        ->assertJsonPath('data.0.status', 'inactive');
});

it('libera la placa del vehículo desactivado para otra empresa', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'plate' => 'P123ABC']);

    asUser($carrier->owner)->deleteJson("/api/vehicles/{$vehicle->id}")->assertOk();

    $otra = Carrier::factory()->create();

    asUser($otra->owner)->post('/api/vehicles', validVehiclePayload(['plate' => 'P123ABC']))
        ->assertCreated()
        ->assertJsonPath('data.plate', 'P123ABC');

    expect(Vehicle::query()->where('plate', '=', 'P123ABC')->count())->toBe(2);
});

it('rechaza con 403 a un carrier que desactiva un vehículo de otra empresa', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = Vehicle::factory()->create(['status' => VehicleStatus::Active]);

    asUser($carrier->owner)->deleteJson("/api/vehicles/{$ajeno->id}")
        ->assertForbidden()
        ->assertJson(['message' => 'No puedes acceder a un vehículo que no pertenece a tu empresa transportista']);

    $this->assertDatabaseHas('vehicles', ['id' => $ajeno->id, 'status' => 'active']);
});

it('desactiva sin efectos adicionales un vehículo que ya estaba inactive', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'status' => VehicleStatus::Inactive]);

    asUser($carrier->owner)->deleteJson("/api/vehicles/{$vehicle->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'inactive']);
    $this->assertDatabaseCount('vehicles', 1);
});

it('devuelve 404 al desactivar un vehículo que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->deleteJson('/api/vehicles/99999')
        ->assertNotFound()
        ->assertJson(['message' => 'El vehículo no existe']);
});
