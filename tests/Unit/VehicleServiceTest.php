<?php

use App\Enums\UserRole;
use App\Enums\VehicleCondition;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Vehicle\VehicleServiceInterface;
use App\Models\Carrier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Vehicle\VehicleService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function vehicleService(): VehicleServiceInterface
{
    return app(VehicleServiceInterface::class);
}

function adminUser(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * A valid payload for createVehicle(), with the image as an uploaded file.
 *
 * @return array<string, mixed>
 */
function vehicleServicePayload(array $overrides = []): array
{
    return array_merge([
        'plate' => 'P123ABC',
        'brand' => 'Kenworth',
        'model' => 'T680',
        'year' => 2020,
        'capacity' => 15000.5,
        'type' => VehicleType::Truck->value,
        'condition' => VehicleCondition::Used->value,
        'kilometers_per_gallon' => 12.5,
        'purchase_price' => 185000,
        'monthly_insurance_cost' => 1250.75,
        'mileage' => 120000,
        'engine_number' => 'MOT12345',
        'image' => UploadedFile::fake()->image('camion.png'),
    ], $overrides);
}

it('resuelve la implementación registrada en el provider', function () {
    expect(vehicleService())->toBeInstanceOf(VehicleService::class);
});

/*
|--------------------------------------------------------------------------
| Base de datos y modelo
|--------------------------------------------------------------------------
*/

it('crea la tabla vehicles con sus columnas', function () {
    expect(Schema::hasTable('vehicles'))->toBeTrue()
        ->and(Schema::getColumnListing('vehicles'))->toEqualCanonicalizing([
            'id',
            'carrier_id',
            'plate',
            'brand',
            'model',
            'year',
            'capacity',
            'type',
            'condition',
            'kilometers_per_gallon',
            'purchase_price',
            'monthly_insurance_cost',
            'mileage',
            'engine_number',
            'image',
            'status',
            'created_at',
            'updated_at',
        ]);
});

it('no impide en base dos vehículos con la misma placa', function () {
    Vehicle::factory()->create(['plate' => 'P123ABC']);
    Vehicle::factory()->create(['plate' => 'P123ABC']);

    expect(Vehicle::query()->where('plate', '=', 'P123ABC')->count())->toBe(2);
});

it('expone la empresa dueña del vehículo y sus vehículos', function () {
    $carrier = Carrier::factory()->has(Vehicle::factory()->count(3))->create();

    expect($carrier->vehicles)->toHaveCount(3)
        ->and($carrier->vehicles->first())->toBeInstanceOf(Vehicle::class)
        ->and($carrier->vehicles->first()->carrier)->toBeInstanceOf(Carrier::class)
        ->and($carrier->vehicles->first()->carrier->id)->toBe($carrier->id);
});

it('nace en active un vehículo creado sin status explícito', function () {
    $carrier = Carrier::factory()->create();

    $vehicle = Vehicle::create([
        'carrier_id' => $carrier->id,
        'plate' => 'P123ABC',
        'brand' => 'Kenworth',
        'model' => 'T680',
        'year' => 2020,
        'capacity' => 15000.5,
        'type' => VehicleType::Truck,
    ]);

    expect($vehicle->fresh()->status)->toBe(VehicleStatus::Active);
});

it('castea el tipo y el estado a sus enums', function () {
    $vehicle = Vehicle::factory()->create([
        'type' => VehicleType::Trailer,
        'status' => VehicleStatus::UnderRepair,
    ])->fresh();

    expect($vehicle->type)->toBeInstanceOf(VehicleType::class)
        ->and($vehicle->type)->toBe(VehicleType::Trailer)
        ->and($vehicle->status)->toBeInstanceOf(VehicleStatus::class)
        ->and($vehicle->status)->toBe(VehicleStatus::UnderRepair)
        ->and($vehicle->year)->toBeInt();
});

it('arrastra los vehículos al borrar su empresa', function () {
    $carrier = Carrier::factory()->has(Vehicle::factory()->count(2))->create();

    $carrier->delete();

    $this->assertDatabaseCount('vehicles', 0);
});

/*
|--------------------------------------------------------------------------
| getVehicles()
|--------------------------------------------------------------------------
*/

it('devuelve una colección con todos los vehículos del ámbito sin limit', function () {
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->count(12)->create(['carrier_id' => $carrier->id]);

    $vehicles = vehicleService()->getVehicles($carrier->owner, []);

    expect($vehicles)->toBeInstanceOf(Collection::class)
        ->and($vehicles)->toHaveCount(12);
});

it('devuelve una colección cuando limit no es numérico', function () {
    Vehicle::factory()->count(12)->create();

    $vehicles = vehicleService()->getVehicles(adminUser(), ['limit' => 'abc']);

    expect($vehicles)->toBeInstanceOf(Collection::class)
        ->and($vehicles)->toHaveCount(12);
});

it('devuelve un paginador de vehículos cuando limit es numérico', function () {
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->count(12)->create(['carrier_id' => $carrier->id]);

    $vehicles = vehicleService()->getVehicles($carrier->owner, ['limit' => '10']);

    expect($vehicles)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($vehicles->perPage())->toBe(10)
        ->and($vehicles->total())->toBe(12)
        ->and($vehicles->lastPage())->toBe(2);
});

it('acota el tamaño de página de vehículos al rango permitido', function (string $limit, int $expected) {
    Vehicle::factory()->count(2)->create();

    expect(vehicleService()->getVehicles(adminUser(), ['limit' => $limit])->perPage())->toBe($expected);
})->with([
    'por debajo del mínimo' => ['1', 10],
    'dentro del rango' => ['25', 25],
    'por encima del máximo' => ['500', 100],
]);

it('restringe el listado de un carrier a los vehículos de su empresa', function () {
    $carrier = Carrier::factory()->create();
    $propios = Vehicle::factory()->count(2)->create(['carrier_id' => $carrier->id]);
    Vehicle::factory()->count(3)->create();

    expect(vehicleService()->getVehicles($carrier->owner, [])->pluck('id')->all())
        ->toEqualCanonicalizing($propios->pluck('id')->all());
});

it('devuelve al administrador los vehículos de todas las empresas', function () {
    Vehicle::factory()->count(2)->create();
    Vehicle::factory()->count(3)->create();

    expect(vehicleService()->getVehicles(adminUser(), []))->toHaveCount(5);
});

it('aplica el filtro de status solo cuando pertenece al enum', function (?string $status, int $expected) {
    $carrier = Carrier::factory()->create();

    Vehicle::factory()->count(2)->create(['carrier_id' => $carrier->id, 'status' => VehicleStatus::Active]);
    Vehicle::factory()->create(['carrier_id' => $carrier->id, 'status' => VehicleStatus::UnderRepair]);
    Vehicle::factory()->create(['carrier_id' => $carrier->id, 'status' => VehicleStatus::Inactive]);

    expect(vehicleService()->getVehicles($carrier->owner, ['status' => $status]))->toHaveCount($expected);
})->with([
    'sin filtro' => [null, 4],
    'activos' => ['active', 2],
    'en reparación' => ['under_repair', 1],
    'desactivados' => ['inactive', 1],
    'valor fuera del enum' => ['cualquiercosa', 4],
]);

it('aplica el carrierId solo para un administrador y solo si es numérico', function () {
    $unaEmpresa = Carrier::factory()->create();
    $otraEmpresa = Carrier::factory()->create();

    Vehicle::factory()->count(2)->create(['carrier_id' => $unaEmpresa->id]);
    Vehicle::factory()->count(3)->create(['carrier_id' => $otraEmpresa->id]);

    $admin = adminUser();

    expect(vehicleService()->getVehicles($admin, ['carrierId' => (string) $unaEmpresa->id]))->toHaveCount(2)
        ->and(vehicleService()->getVehicles($admin, ['carrierId' => 'abc']))->toHaveCount(5);
});

it('ignora el carrierId que manda un carrier', function () {
    $carrier = Carrier::factory()->create();
    $otra = Carrier::factory()->create();

    $propios = Vehicle::factory()->count(2)->create(['carrier_id' => $carrier->id]);
    Vehicle::factory()->count(3)->create(['carrier_id' => $otra->id]);

    expect(vehicleService()->getVehicles($carrier->owner, ['carrierId' => (string) $otra->id])->pluck('id')->all())
        ->toEqualCanonicalizing($propios->pluck('id')->all());
});

it('precarga la empresa de cada vehículo con dos consultas', function () {
    Vehicle::factory()->count(20)->create();

    $admin = adminUser();

    DB::enableQueryLog();

    $vehicles = vehicleService()->getVehicles($admin, []);

    /** Leer el nombre de la empresa es justo lo que dispararía el N+1 sin with('carrier'). */
    $names = $vehicles->map(fn (Vehicle $vehicle) => $vehicle->carrier->name);

    expect($names)->toHaveCount(20)
        ->and(DB::getQueryLog())->toHaveCount(2);

    DB::disableQueryLog();
});

/*
|--------------------------------------------------------------------------
| getVehicleById()
|--------------------------------------------------------------------------
*/

it('devuelve el vehículo buscado por id', function () {
    $vehicle = Vehicle::factory()->create();

    expect(vehicleService()->getVehicleById(adminUser(), $vehicle->id))
        ->toBeInstanceOf(Vehicle::class)
        ->id->toBe($vehicle->id);
});

it('lanza NotFoundError al buscar un vehículo inexistente', function () {
    expect(fn () => vehicleService()->getVehicleById(adminUser(), 99999))
        ->toThrow(NotFoundError::class, 'El vehículo no existe');
});

it('lanza ForbiddenError cuando un carrier alcanza un vehículo ajeno', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = Vehicle::factory()->create();

    expect(fn () => vehicleService()->getVehicleById($carrier->owner, $ajeno->id))
        ->toThrow(ForbiddenError::class, 'No puedes acceder a un vehículo que no pertenece a tu empresa transportista');
});

/*
|--------------------------------------------------------------------------
| createVehicle()
|--------------------------------------------------------------------------
*/

it('persiste el vehículo en la empresa del usuario, activo y con la placa en mayúsculas', function () {
    $carrier = Carrier::factory()->create();

    $vehicle = vehicleService()->createVehicle(vehicleServicePayload(['plate' => 'p123abc']), $carrier->owner);

    expect($vehicle)->toBeInstanceOf(Vehicle::class)
        ->and($vehicle->carrier_id)->toBe($carrier->id)
        ->and($vehicle->plate)->toBe('P123ABC')
        ->and($vehicle->status)->toBe(VehicleStatus::Active)
        ->and($vehicle->type)->toBe(VehicleType::Truck)
        ->and($vehicle->image)->toStartWith('vehicles/')->toEndWith('.png');

    $this->assertDatabaseHas('vehicles', [
        'carrier_id' => $carrier->id,
        'plate' => 'P123ABC',
        'status' => 'active',
    ]);
});

it('lanza ForbiddenError cuando el usuario no pertenece a ninguna empresa', function () {
    $user = User::factory()->create(['role' => UserRole::Carrier]);

    expect(fn () => vehicleService()->createVehicle(vehicleServicePayload(), $user))
        ->toThrow(ForbiddenError::class, 'Necesitas pertenecer a una empresa transportista para registrar un vehículo');

    $this->assertDatabaseCount('vehicles', 0);
});

it('lanza BadRequestError cuando la placa la usa un vehículo no desactivado', function (VehicleStatus $status) {
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->create(['plate' => 'P123ABC', 'status' => $status]);

    expect(fn () => vehicleService()->createVehicle(vehicleServicePayload(), $carrier->owner))
        ->toThrow(BadRequestError::class, 'La placa ya está registrada en un vehículo que no está desactivado');

    $this->assertDatabaseCount('vehicles', 1);
})->with([
    'activo' => VehicleStatus::Active,
    'en reparación' => VehicleStatus::UnderRepair,
]);

it('permite reutilizar la placa de un vehículo desactivado', function () {
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->create(['plate' => 'P123ABC', 'status' => VehicleStatus::Inactive]);

    $vehicle = vehicleService()->createVehicle(vehicleServicePayload(), $carrier->owner);

    expect($vehicle->plate)->toBe('P123ABC')
        ->and(Vehicle::query()->where('plate', '=', 'P123ABC')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| updateVehicle()
|--------------------------------------------------------------------------
*/

it('actualiza solo los campos enviados', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'brand' => 'Hino',
        'model' => 'Serie 300',
        'image' => 'anterior.png',
    ]);

    $updated = vehicleService()->updateVehicle(['brand' => 'Volvo'], $vehicle->id, $carrier->owner);

    expect($updated->brand)->toBe('Volvo')
        ->and($updated->model)->toBe('Serie 300')
        ->and($updated->image)->toBe('anterior.png')
        ->and($updated->status)->toBe(VehicleStatus::Active);
});

it('actualiza el status y la imagen del vehículo', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $updated = vehicleService()->updateVehicle([
        'status' => VehicleStatus::UnderRepair->value,
        'image' => UploadedFile::fake()->image('nueva.jpg'),
    ], $vehicle->id, $carrier->owner);

    expect($updated->status)->toBe(VehicleStatus::UnderRepair)
        ->and($updated->image)->toStartWith('vehicles/')->toEndWith('.jpg');

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'under_repair']);
});

it('normaliza a mayúsculas la placa actualizada', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'plate' => 'P123ABC']);

    expect(vehicleService()->updateVehicle(['plate' => 'z999xyz'], $vehicle->id, $carrier->owner)->plate)
        ->toBe('Z999XYZ');
});

it('no considera conflicto que el vehículo reenvíe su propia placa', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'plate' => 'P123ABC']);

    expect(vehicleService()->updateVehicle(['plate' => 'p123abc'], $vehicle->id, $carrier->owner)->plate)
        ->toBe('P123ABC');
});

it('lanza BadRequestError al mover la placa a una ya ocupada', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'plate' => 'P123ABC']);
    Vehicle::factory()->create(['plate' => 'Z999XYZ', 'status' => VehicleStatus::Active]);

    expect(fn () => vehicleService()->updateVehicle(['plate' => 'Z999XYZ'], $vehicle->id, $carrier->owner))
        ->toThrow(BadRequestError::class, 'La placa ya está registrada en un vehículo que no está desactivado');

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'plate' => 'P123ABC']);
});

it('lanza BadRequestError al reactivar un vehículo cuya placa ya está ocupada', function (array $payload) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'plate' => 'P123ABC',
        'status' => VehicleStatus::Inactive,
    ]);
    Vehicle::factory()->create(['plate' => 'P123ABC', 'status' => VehicleStatus::Active]);

    expect(fn () => vehicleService()->updateVehicle($payload, $vehicle->id, $carrier->owner))
        ->toThrow(BadRequestError::class, 'No puedes reactivar este vehículo: su placa ya está registrada en otro vehículo que no está desactivado');

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'inactive']);
})->with([
    'solo el status' => [['status' => 'active']],
    'reenviando su propia placa' => [['status' => 'active', 'plate' => 'p123abc']],
    'volviendo a under_repair' => [['status' => 'under_repair']],
]);

it('reactiva el vehículo cuando su placa sigue libre', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'plate' => 'P123ABC',
        'status' => VehicleStatus::Inactive,
    ]);

    expect(vehicleService()->updateVehicle(['status' => 'active'], $vehicle->id, $carrier->owner)->status)
        ->toBe(VehicleStatus::Active);
});

it('no revalida la placa de un vehículo desactivado que sigue desactivado', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'plate' => 'P123ABC',
        'status' => VehicleStatus::Inactive,
    ]);
    Vehicle::factory()->create(['plate' => 'P123ABC', 'status' => VehicleStatus::Active]);

    $updated = vehicleService()->updateVehicle(['brand' => 'Volvo'], $vehicle->id, $carrier->owner);

    expect($updated->brand)->toBe('Volvo')
        ->and($updated->status)->toBe(VehicleStatus::Inactive);
});

it('lanza ForbiddenError cuando un carrier actualiza un vehículo ajeno', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = Vehicle::factory()->create(['brand' => 'Intacta']);

    expect(fn () => vehicleService()->updateVehicle(['brand' => 'Secuestrada'], $ajeno->id, $carrier->owner))
        ->toThrow(ForbiddenError::class, 'No puedes acceder a un vehículo que no pertenece a tu empresa transportista');

    $this->assertDatabaseHas('vehicles', ['id' => $ajeno->id, 'brand' => 'Intacta']);
});

it('permite a un administrador actualizar el vehículo de cualquier empresa', function () {
    $vehicle = Vehicle::factory()->create(['brand' => 'Hino']);

    expect(vehicleService()->updateVehicle(['brand' => 'Corregida'], $vehicle->id, adminUser())->brand)
        ->toBe('Corregida');
});

it('lanza NotFoundError al actualizar un vehículo inexistente', function () {
    expect(fn () => vehicleService()->updateVehicle(['brand' => 'Fantasma'], 99999, adminUser()))
        ->toThrow(NotFoundError::class, 'El vehículo no existe');
});

/*
|--------------------------------------------------------------------------
| deleteVehicle()
|--------------------------------------------------------------------------
*/

it('desactiva el vehículo sin borrar la fila', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $deactivated = vehicleService()->deleteVehicle($vehicle->id, $carrier->owner);

    expect($deactivated->status)->toBe(VehicleStatus::Inactive);

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'inactive']);
    $this->assertDatabaseCount('vehicles', 1);
});

it('deja igual un vehículo que ya estaba desactivado', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'status' => VehicleStatus::Inactive]);

    expect(vehicleService()->deleteVehicle($vehicle->id, $carrier->owner)->status)->toBe(VehicleStatus::Inactive);

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'inactive']);
});

it('lanza ForbiddenError cuando un carrier desactiva un vehículo ajeno', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = Vehicle::factory()->create(['status' => VehicleStatus::Active]);

    expect(fn () => vehicleService()->deleteVehicle($ajeno->id, $carrier->owner))
        ->toThrow(ForbiddenError::class, 'No puedes acceder a un vehículo que no pertenece a tu empresa transportista');

    $this->assertDatabaseHas('vehicles', ['id' => $ajeno->id, 'status' => 'active']);
});

it('lanza NotFoundError al desactivar un vehículo inexistente', function () {
    expect(fn () => vehicleService()->deleteVehicle(99999, adminUser()))
        ->toThrow(NotFoundError::class, 'El vehículo no existe');
});

/*
|--------------------------------------------------------------------------
| SPEC 13 — Modelo y casts de la ficha
|--------------------------------------------------------------------------
*/

it('castea la condición al enum, el kilometraje a entero y el dinero a cadena de dos decimales', function () {
    $vehicle = Vehicle::factory()->create([
        'condition' => VehicleCondition::New,
        'kilometers_per_gallon' => 12.5,
        'purchase_price' => 185000,
        'monthly_insurance_cost' => 1250.75,
        'mileage' => 120000,
    ])->fresh();

    expect($vehicle->condition)->toBeInstanceOf(VehicleCondition::class)
        ->and($vehicle->condition)->toBe(VehicleCondition::New)
        ->and($vehicle->mileage)->toBeInt()->toBe(120000)
        ->and($vehicle->kilometers_per_gallon)->toBeString()->toBe('12.50')
        ->and($vehicle->purchase_price)->toBeString()->toBe('185000.00')
        ->and($vehicle->monthly_insurance_cost)->toBeString()->toBe('1250.75');
});

it('persiste con Vehicle::create las seis columnas de la ficha, que están en el fillable', function () {
    $carrier = Carrier::factory()->create();

    $vehicle = Vehicle::create([
        'carrier_id' => $carrier->id,
        'plate' => 'P123ABC',
        'brand' => 'Kenworth',
        'model' => 'T680',
        'year' => 2020,
        'capacity' => 15000.5,
        'type' => VehicleType::Truck,
        'condition' => VehicleCondition::New,
        'kilometers_per_gallon' => 12.5,
        'purchase_price' => 185000,
        'monthly_insurance_cost' => 1250.75,
        'mileage' => 42000,
        'engine_number' => 'MOT12345',
    ])->fresh();

    expect($vehicle->condition)->toBe(VehicleCondition::New)
        ->and($vehicle->mileage)->toBe(42000)
        ->and($vehicle->engine_number)->toBe('MOT12345');

    $this->assertDatabaseHas('vehicles', [
        'plate' => 'P123ABC',
        'condition' => 'new',
        'kilometers_per_gallon' => 12.5,
        'purchase_price' => 185000,
        'monthly_insurance_cost' => 1250.75,
        'mileage' => 42000,
        'engine_number' => 'MOT12345',
    ]);
});

it('tiene exactamente dos condiciones posibles', function () {
    expect(array_column(VehicleCondition::cases(), 'value'))->toBe(['new', 'used']);
});

it('no impide en base dos vehículos con el mismo número de motor', function () {
    Vehicle::factory()->create(['engine_number' => 'MOT12345']);
    Vehicle::factory()->create(['engine_number' => 'MOT12345']);

    expect(Vehicle::query()->where('engine_number', '=', 'MOT12345')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| SPEC 13 — Filtros nuevos de getVehicles()
|--------------------------------------------------------------------------
*/

it('aplica el filtro de condición solo cuando pertenece al enum', function (?string $condition, int $expected) {
    $carrier = Carrier::factory()->create();

    Vehicle::factory()->count(2)->create(['carrier_id' => $carrier->id, 'condition' => VehicleCondition::New]);
    Vehicle::factory()->count(3)->create(['carrier_id' => $carrier->id, 'condition' => VehicleCondition::Used]);

    expect(vehicleService()->getVehicles($carrier->owner, ['condition' => $condition]))->toHaveCount($expected);
})->with([
    'sin filtro' => [null, 5],
    'nuevos' => ['new', 2],
    'usados' => ['used', 3],
    'valor fuera del enum' => ['antiguo', 5],
]);

it('filtra por coincidencia parcial del número de motor, normalizando el término', function (?string $term, int $expected) {
    $carrier = Carrier::factory()->create();

    Vehicle::factory()->create(['carrier_id' => $carrier->id, 'engine_number' => 'XABC123']);
    Vehicle::factory()->create(['carrier_id' => $carrier->id, 'engine_number' => 'ZZZ999']);
    Vehicle::factory()->create(['carrier_id' => $carrier->id, 'engine_number' => null]);

    expect(vehicleService()->getVehicles($carrier->owner, ['engineNumber' => $term]))->toHaveCount($expected);
})->with([
    'sin filtro' => [null, 3],
    'cadena vacía' => ['', 3],
    'solo espacios' => ['   ', 3],
    'parcial en minúsculas' => ['abc', 1],
    'parcial en mayúsculas' => ['ABC', 1],
    'con espacios alrededor' => [' abc ', 1],
    'sin coincidencias' => ['nada', 0],
]);

it('combina los filtros de condición y número de motor con el de status', function () {
    $carrier = Carrier::factory()->create();

    $buscado = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'condition' => VehicleCondition::New,
        'status' => VehicleStatus::Active,
        'engine_number' => 'XABC123',
    ]);
    Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'condition' => VehicleCondition::Used,
        'status' => VehicleStatus::Active,
        'engine_number' => 'XABC123',
    ]);
    Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'condition' => VehicleCondition::New,
        'status' => VehicleStatus::Inactive,
        'engine_number' => 'XABC123',
    ]);
    Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'condition' => VehicleCondition::New,
        'status' => VehicleStatus::Active,
        'engine_number' => 'ZZZ999',
    ]);

    $vehicles = vehicleService()->getVehicles($carrier->owner, [
        'condition' => 'new',
        'engineNumber' => 'abc',
        'status' => 'active',
    ]);

    expect($vehicles->pluck('id')->all())->toBe([$buscado->id]);
});

/*
|--------------------------------------------------------------------------
| SPEC 13 — createVehicle() con la ficha
|--------------------------------------------------------------------------
*/

it('persiste los seis campos de la ficha y normaliza el número de motor al crear', function () {
    $carrier = Carrier::factory()->create();

    $vehicle = vehicleService()->createVehicle(vehicleServicePayload([
        'condition' => VehicleCondition::New->value,
        'kilometers_per_gallon' => 12.5,
        'purchase_price' => 185000,
        'monthly_insurance_cost' => 1250.75,
        'mileage' => 0,
        'engine_number' => 'abc123',
    ]), $carrier->owner)->fresh();

    expect($vehicle->condition)->toBe(VehicleCondition::New)
        ->and($vehicle->kilometers_per_gallon)->toBe('12.50')
        ->and($vehicle->purchase_price)->toBe('185000.00')
        ->and($vehicle->monthly_insurance_cost)->toBe('1250.75')
        ->and($vehicle->mileage)->toBe(0)
        ->and($vehicle->engine_number)->toBe('ABC123')
        ->and($vehicle->status)->toBe(VehicleStatus::Active);
});

/*
|--------------------------------------------------------------------------
| SPEC 13 — updateVehicle() y la autorización del kilometraje
|--------------------------------------------------------------------------
*/

it('actualiza los cinco campos de la ficha que no son el kilometraje', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'condition' => VehicleCondition::Used,
        'mileage' => 120000,
    ]);

    $updated = vehicleService()->updateVehicle([
        'condition' => VehicleCondition::New->value,
        'kilometers_per_gallon' => 9.75,
        'purchase_price' => 250000,
        'monthly_insurance_cost' => 900.5,
        'engine_number' => 'xyz789',
    ], $vehicle->id, $carrier->owner)->fresh();

    expect($updated->condition)->toBe(VehicleCondition::New)
        ->and($updated->kilometers_per_gallon)->toBe('9.75')
        ->and($updated->purchase_price)->toBe('250000.00')
        ->and($updated->monthly_insurance_cost)->toBe('900.50')
        ->and($updated->engine_number)->toBe('XYZ789')
        ->and($updated->mileage)->toBe(120000);
});

it('deja que un administrador suba y baje el kilometraje', function (int $mileage) {
    $vehicle = Vehicle::factory()->create(['mileage' => 120000]);

    expect(vehicleService()->updateVehicle(['mileage' => $mileage], $vehicle->id, adminUser())->mileage)
        ->toBe($mileage);

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'mileage' => $mileage]);
})->with([
    'subiéndolo' => 150000,
    'bajándolo' => 500,
]);

it('lanza ForbiddenError cuando un carrier mueve el kilometraje', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'mileage' => 120000]);

    expect(fn () => vehicleService()->updateVehicle(['mileage' => 130000], $vehicle->id, $carrier->owner))
        ->toThrow(ForbiddenError::class, 'Solo un administrador puede modificar el kilometraje del vehículo');

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'mileage' => 120000]);
});

it('no aplica ningún otro campo del cuerpo cuando el kilometraje corta la edición', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'brand' => 'Hino',
        'mileage' => 120000,
        'image' => null,
    ]);

    expect(fn () => vehicleService()->updateVehicle([
        'brand' => 'Volvo',
        'mileage' => 130000,
        'image' => UploadedFile::fake()->image('nueva.jpg'),
    ], $vehicle->id, $carrier->owner))->toThrow(ForbiddenError::class);

    $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'brand' => 'Hino', 'mileage' => 120000, 'image' => null]);

    /** La comprobación va antes del storeImage(): nada llegó al bucket. */
    expect(Storage::allFiles())->toBeEmpty();
});

it('no considera un cambio que el carrier reenvíe el kilometraje que ya tiene el vehículo', function (mixed $mileage) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'brand' => 'Hino',
        'mileage' => 120000,
    ]);

    $updated = vehicleService()->updateVehicle(
        ['mileage' => $mileage, 'brand' => 'Volvo'],
        $vehicle->id,
        $carrier->owner,
    );

    expect($updated->mileage)->toBe(120000)
        ->and($updated->brand)->toBe('Volvo');
})->with([
    'como entero' => 120000,
    'como cadena' => '120000',
]);

it('normaliza a mayúsculas el número de motor actualizado', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id, 'engine_number' => 'MOT12345']);

    expect(vehicleService()->updateVehicle(['engine_number' => 'xyz789'], $vehicle->id, $carrier->owner)->engine_number)
        ->toBe('XYZ789');
});

it('no revalida el número de motor al reactivar un vehículo desactivado', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'plate' => 'P123ABC',
        'engine_number' => 'MOT12345',
        'status' => VehicleStatus::Inactive,
    ]);
    Vehicle::factory()->create([
        'plate' => 'Z999XYZ',
        'engine_number' => 'MOT12345',
        'status' => VehicleStatus::Active,
    ]);

    $updated = vehicleService()->updateVehicle(['status' => 'active'], $vehicle->id, $carrier->owner);

    expect($updated->status)->toBe(VehicleStatus::Active)
        ->and($updated->engine_number)->toBe('MOT12345');
});
