<?php

use App\Enums\UserRole;
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
        ->and($vehicle->image)->toEndWith('.png');

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
        ->and($updated->image)->toEndWith('.jpg');

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
