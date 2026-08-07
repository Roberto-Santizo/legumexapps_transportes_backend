<?php

use App\Enums\FuelPriceStatus;
use App\Enums\FuelType;
use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\FuelPrice\FuelPriceServiceInterface;
use App\Models\FuelPrice;
use App\Models\User;
use App\Services\FuelPrice\FuelPriceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

function fuelPriceService(): FuelPriceServiceInterface
{
    return app(FuelPriceServiceInterface::class);
}

function fuelPriceAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * A valid payload for create().
 *
 * @return array<string, mixed>
 */
function fuelPriceServicePayload(array $overrides = []): array
{
    return array_merge([
        'fuelType' => FuelType::Diesel->value,
        'price' => 34.50,
    ], $overrides);
}

/**
 * How many rows of the given fuel type are currently in effect.
 */
function activeCountForType(FuelType $type): int
{
    return FuelPrice::query()
        ->where('fuel_type', '=', $type->value)
        ->where('status', '=', FuelPriceStatus::Active->value)
        ->count();
}

it('resuelve la implementación registrada en el provider', function () {
    expect(fuelPriceService())->toBeInstanceOf(FuelPriceService::class);
});

/*
|--------------------------------------------------------------------------
| Base de datos y modelo
|--------------------------------------------------------------------------
*/

it('crea la tabla fuel_prices con sus columnas', function () {
    expect(Schema::hasTable('fuel_prices'))->toBeTrue()
        ->and(Schema::getColumnListing('fuel_prices'))->toEqualCanonicalizing([
            'id',
            'fuel_type',
            'price',
            'status',
            'registered_by',
            'created_at',
            'updated_at',
        ]);
});

it('nace en active un precio creado sin status explícito', function () {
    $fuelPrice = FuelPrice::create([
        'fuel_type' => FuelType::Diesel,
        'price' => 34.50,
        'registered_by' => fuelPriceAdmin()->id,
    ]);

    expect($fuelPrice->fresh()->status)->toBe(FuelPriceStatus::Active);
});

it('castea el tipo, el estado y el precio', function () {
    $fuelPrice = FuelPrice::factory()->inactive()->create([
        'fuel_type' => FuelType::DieselPremium,
        'price' => 40.7,
    ])->fresh();

    expect($fuelPrice->fuel_type)->toBe(FuelType::DieselPremium)
        ->and($fuelPrice->status)->toBe(FuelPriceStatus::Inactive)
        ->and($fuelPrice->price)->toBe('40.70');
});

it('expone al usuario que registró el precio', function () {
    $admin = fuelPriceAdmin();

    $fuelPrice = FuelPrice::factory()->create(['registered_by' => $admin->id]);

    expect($fuelPrice->registeredBy)->toBeInstanceOf(User::class)
        ->and($fuelPrice->registeredBy->id)->toBe($admin->id);
});

it('no impide en base dos filas activas del mismo tipo, porque la regla vive en el service', function () {
    FuelPrice::factory()->count(2)->active()->create(['fuel_type' => FuelType::Diesel]);

    expect(activeCountForType(FuelType::Diesel))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| getFuelPrices()
|--------------------------------------------------------------------------
*/

it('devuelve una colección con todos los precios sin limit', function () {
    FuelPrice::factory()->count(12)->create();

    $fuelPrices = fuelPriceService()->getFuelPrices([]);

    expect($fuelPrices)->toBeInstanceOf(Collection::class)
        ->and($fuelPrices)->toHaveCount(12);
});

it('devuelve una colección de precios cuando limit no es numérico', function () {
    FuelPrice::factory()->count(12)->create();

    $fuelPrices = fuelPriceService()->getFuelPrices(['limit' => 'abc']);

    expect($fuelPrices)->toBeInstanceOf(Collection::class)
        ->and($fuelPrices)->toHaveCount(12);
});

it('devuelve un paginador de precios cuando limit es numérico', function () {
    FuelPrice::factory()->count(12)->create();

    $fuelPrices = fuelPriceService()->getFuelPrices(['limit' => '10']);

    expect($fuelPrices)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($fuelPrices->perPage())->toBe(10)
        ->and($fuelPrices->total())->toBe(12)
        ->and($fuelPrices->lastPage())->toBe(2);
});

it('acota el tamaño de página de precios al rango permitido', function (string $limit, int $expected) {
    FuelPrice::factory()->count(2)->create();

    expect(fuelPriceService()->getFuelPrices(['limit' => $limit])->perPage())->toBe($expected);
})->with([
    'por debajo del mínimo' => ['1', 10],
    'dentro del rango' => ['25', 25],
    'por encima del máximo' => ['500', 100],
]);

it('aplica el filtro de fuelType solo cuando pertenece al enum', function (?string $fuelType, int $expected) {
    FuelPrice::factory()->count(2)->create(['fuel_type' => FuelType::Diesel]);
    FuelPrice::factory()->create(['fuel_type' => FuelType::Regular]);
    FuelPrice::factory()->create(['fuel_type' => FuelType::Premium]);

    expect(fuelPriceService()->getFuelPrices(['fuelType' => $fuelType]))->toHaveCount($expected);
})->with([
    'sin filtro' => [null, 4],
    'diésel' => ['diesel', 2],
    'regular' => ['regular', 1],
    'valor fuera del enum' => ['gasolina', 4],
]);

it('aplica el filtro de status solo cuando pertenece al enum', function (?string $status, int $expected) {
    FuelPrice::factory()->count(2)->active()->create();
    FuelPrice::factory()->count(3)->inactive()->create();

    expect(fuelPriceService()->getFuelPrices(['status' => $status]))->toHaveCount($expected);
})->with([
    'sin filtro' => [null, 5],
    'vigentes' => ['active', 2],
    'históricos' => ['inactive', 3],
    'valor fuera del enum' => ['vencido', 5],
]);

it('combina los filtros de tipo y de estado', function () {
    $buscado = FuelPrice::factory()->inactive()->create(['fuel_type' => FuelType::Diesel]);

    FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel]);
    FuelPrice::factory()->inactive()->create(['fuel_type' => FuelType::Regular]);

    $fuelPrices = fuelPriceService()->getFuelPrices(['fuelType' => 'diesel', 'status' => 'inactive']);

    expect($fuelPrices->pluck('id')->all())->toBe([$buscado->id]);
});

it('ordena el histórico por fecha y desempata por id', function () {
    $instante = now();

    $viejo = FuelPrice::factory()->create(['created_at' => $instante->copy()->subDay()]);
    $primero = FuelPrice::factory()->create(['created_at' => $instante]);
    $segundo = FuelPrice::factory()->create(['created_at' => $instante]);

    expect(fuelPriceService()->getFuelPrices([])->pluck('id')->all())
        ->toBe([$segundo->id, $primero->id, $viejo->id]);
});

it('carga la relación del registrador al listar', function () {
    FuelPrice::factory()->count(3)->create();

    $fuelPrices = fuelPriceService()->getFuelPrices([]);

    expect($fuelPrices->first()->relationLoaded('registeredBy'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| getFuelPriceById()
|--------------------------------------------------------------------------
*/

it('devuelve el precio que coincide con el id, esté vigente o no', function (bool $active) {
    $fuelPrice = FuelPrice::factory()->{$active ? 'active' : 'inactive'}()->create();

    $found = fuelPriceService()->getFuelPriceById($fuelPrice->id);

    expect($found)->toBeInstanceOf(FuelPrice::class)
        ->and($found->id)->toBe($fuelPrice->id)
        ->and($found->relationLoaded('registeredBy'))->toBeTrue();
})->with([
    'vigente' => [true],
    'del histórico' => [false],
]);

it('lanza NotFoundError al buscar un precio inexistente', function () {
    expect(fn () => fuelPriceService()->getFuelPriceById(99999))
        ->toThrow(NotFoundError::class, 'El precio de combustible no existe');
});

/*
|--------------------------------------------------------------------------
| getCurrentByType()
|--------------------------------------------------------------------------
*/

it('devuelve la única fila vigente del tipo pedido', function () {
    FuelPrice::factory()->count(2)->inactive()->create(['fuel_type' => FuelType::Diesel]);
    $vigente = FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel]);
    FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Regular]);

    expect(fuelPriceService()->getCurrentByType('diesel')->id)->toBe($vigente->id);
});

it('lanza NotFoundError cuando el tipo no tiene precio vigente', function () {
    FuelPrice::factory()->count(2)->inactive()->create(['fuel_type' => FuelType::Premium]);

    expect(fn () => fuelPriceService()->getCurrentByType('premium'))
        ->toThrow(NotFoundError::class, 'No existe un precio vigente para el combustible indicado');
});

/*
|--------------------------------------------------------------------------
| create()
|--------------------------------------------------------------------------
*/

it('persiste el precio nuevo como vigente y a nombre del usuario recibido', function () {
    $admin = fuelPriceAdmin();

    $fuelPrice = fuelPriceService()->create($admin, fuelPriceServicePayload());

    expect($fuelPrice)->toBeInstanceOf(FuelPrice::class)
        ->and($fuelPrice->fuel_type)->toBe(FuelType::Diesel)
        ->and($fuelPrice->status)->toBe(FuelPriceStatus::Active)
        ->and($fuelPrice->relationLoaded('registeredBy'))->toBeTrue();

    $this->assertDatabaseHas('fuel_prices', [
        'id' => $fuelPrice->id,
        'fuel_type' => 'diesel',
        'price' => 34.50,
        'status' => 'active',
        'registered_by' => $admin->id,
    ]);
});

it('desplaza al histórico el vigente anterior del mismo tipo', function () {
    $anterior = FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel]);

    $nuevo = fuelPriceService()->create(fuelPriceAdmin(), fuelPriceServicePayload());

    expect($anterior->fresh()->status)->toBe(FuelPriceStatus::Inactive)
        ->and($nuevo->status)->toBe(FuelPriceStatus::Active)
        ->and(activeCountForType(FuelType::Diesel))->toBe(1);
});

it('no toca los vigentes de los demás tipos al registrar', function () {
    $regular = FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Regular]);
    $premium = FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Premium]);

    fuelPriceService()->create(fuelPriceAdmin(), fuelPriceServicePayload());

    expect($regular->fresh()->status)->toBe(FuelPriceStatus::Active)
        ->and($premium->fresh()->status)->toBe(FuelPriceStatus::Active);
});

it('deja como mucho un vigente por tipo tras varias altas seguidas', function () {
    $admin = fuelPriceAdmin();

    foreach ([FuelType::Diesel, FuelType::Diesel, FuelType::Regular, FuelType::Diesel] as $type) {
        fuelPriceService()->create($admin, fuelPriceServicePayload(['fuelType' => $type->value]));
    }

    expect(activeCountForType(FuelType::Diesel))->toBe(1)
        ->and(activeCountForType(FuelType::Regular))->toBe(1)
        ->and(FuelPrice::query()->count())->toBe(4);
});

/*
|--------------------------------------------------------------------------
| update()
|--------------------------------------------------------------------------
*/

it('actualiza solo el precio de la fila vigente', function () {
    $admin = fuelPriceAdmin();

    $fuelPrice = FuelPrice::factory()->active()->create([
        'fuel_type' => FuelType::Diesel,
        'price' => 34.50,
        'registered_by' => $admin->id,
    ]);

    $updated = fuelPriceService()->update($fuelPrice->id, ['price' => 35.00]);

    expect($updated->price)->toBe('35.00')
        ->and($updated->fuel_type)->toBe(FuelType::Diesel)
        ->and($updated->status)->toBe(FuelPriceStatus::Active)
        ->and($updated->registered_by)->toBe($admin->id);

    $this->assertDatabaseHas('fuel_prices', [
        'id' => $fuelPrice->id,
        'price' => 35.00,
        'fuel_type' => 'diesel',
        'status' => 'active',
        'registered_by' => $admin->id,
    ]);
});

it('lanza NotFoundError al actualizar un precio inexistente', function () {
    expect(fn () => fuelPriceService()->update(99999, ['price' => 35.00]))
        ->toThrow(NotFoundError::class, 'El precio de combustible no existe');
});

it('lanza BadRequestError al actualizar una fila del histórico', function () {
    $fuelPrice = FuelPrice::factory()->inactive()->create(['price' => 33.00]);

    expect(fn () => fuelPriceService()->update($fuelPrice->id, ['price' => 35.00]))
        ->toThrow(BadRequestError::class, 'Solo se puede modificar el precio vigente');

    $this->assertDatabaseHas('fuel_prices', ['id' => $fuelPrice->id, 'price' => 33.00]);
});

/*
|--------------------------------------------------------------------------
| deactivate()
|--------------------------------------------------------------------------
*/

it('pasa a inactive la fila vigente y deja el tipo sin vigente', function () {
    $fuelPrice = FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel]);

    $deactivated = fuelPriceService()->deactivate($fuelPrice->id);

    expect($deactivated->status)->toBe(FuelPriceStatus::Inactive)
        ->and(activeCountForType(FuelType::Diesel))->toBe(0);

    $this->assertDatabaseHas('fuel_prices', ['id' => $fuelPrice->id, 'status' => 'inactive']);

    expect(fn () => fuelPriceService()->getCurrentByType('diesel'))->toThrow(NotFoundError::class);
});

it('no asciende ninguna fila del histórico al desactivar', function () {
    $historico = FuelPrice::factory()->count(3)->inactive()->create(['fuel_type' => FuelType::Diesel]);
    $vigente = FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel]);

    fuelPriceService()->deactivate($vigente->id);

    expect(activeCountForType(FuelType::Diesel))->toBe(0)
        ->and($historico->every(fn (FuelPrice $row) => $row->fresh()->status === FuelPriceStatus::Inactive))->toBeTrue();
});

it('lanza NotFoundError al desactivar un precio inexistente', function () {
    expect(fn () => fuelPriceService()->deactivate(99999))
        ->toThrow(NotFoundError::class, 'El precio de combustible no existe');
});

it('lanza BadRequestError al desactivar una fila del histórico', function () {
    $fuelPrice = FuelPrice::factory()->inactive()->create();

    expect(fn () => fuelPriceService()->deactivate($fuelPrice->id))
        ->toThrow(BadRequestError::class, 'Solo se puede modificar el precio vigente');
});

/*
|--------------------------------------------------------------------------
| destroy()
|--------------------------------------------------------------------------
*/

it('borra de verdad la fila vigente y devuelve el modelo en memoria', function () {
    $fuelPrice = FuelPrice::factory()->active()->create([
        'fuel_type' => FuelType::Diesel,
        'price' => 34.50,
    ]);

    $deleted = fuelPriceService()->destroy($fuelPrice->id);

    expect($deleted)->toBeInstanceOf(FuelPrice::class)
        ->and($deleted->id)->toBe($fuelPrice->id)
        ->and($deleted->price)->toBe('34.50')
        ->and($deleted->exists)->toBeFalse();

    $this->assertDatabaseMissing('fuel_prices', ['id' => $fuelPrice->id]);
});

it('no asciende ninguna fila del histórico al borrar la vigente', function () {
    $historico = FuelPrice::factory()->count(3)->inactive()->create(['fuel_type' => FuelType::Diesel]);
    $vigente = FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel]);

    fuelPriceService()->destroy($vigente->id);

    expect(activeCountForType(FuelType::Diesel))->toBe(0)
        ->and(FuelPrice::query()->count())->toBe($historico->count());
});

it('lanza NotFoundError al borrar un precio inexistente', function () {
    expect(fn () => fuelPriceService()->destroy(99999))
        ->toThrow(NotFoundError::class, 'El precio de combustible no existe');
});

it('lanza BadRequestError al borrar una fila del histórico y la deja intacta', function () {
    $fuelPrice = FuelPrice::factory()->inactive()->create();

    expect(fn () => fuelPriceService()->destroy($fuelPrice->id))
        ->toThrow(BadRequestError::class, 'Solo se puede modificar el precio vigente');

    $this->assertDatabaseHas('fuel_prices', ['id' => $fuelPrice->id]);
});
