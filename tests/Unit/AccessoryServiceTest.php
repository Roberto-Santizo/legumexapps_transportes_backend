<?php

use App\Enums\AccessoryStatus;
use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\Accessory\AccessoryServiceInterface;
use App\Models\Accessory;
use App\Models\User;
use App\Services\Accessory\AccessoryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

function accessoryService(): AccessoryServiceInterface
{
    return app(AccessoryServiceInterface::class);
}

function accessoryServiceAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * The payload the service expects, with the keys already in camelCase.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function accessoryServiceData(array $overrides = []): array
{
    return array_merge([
        'name' => 'gato hidraulico 20 ton',
        'code' => 'acc-0001',
        'price' => 1500.5,
        'purchaseDate' => '2024-01-15',
        'annualDepreciation' => 10,
    ], $overrides);
}

it('resuelve la implementación de accesorios registrada en el provider', function () {
    expect(accessoryService())->toBeInstanceOf(AccessoryService::class);
});

/*
|--------------------------------------------------------------------------
| Base de datos y modelo
|--------------------------------------------------------------------------
*/

it('crea la tabla accessories con sus columnas', function () {
    expect(Schema::hasTable('accessories'))->toBeTrue()
        ->and(Schema::getColumnListing('accessories'))->toEqualCanonicalizing([
            'id',
            'name',
            'code',
            'description',
            'price',
            'purchase_date',
            'annual_depreciation',
            'status',
            'registered_by',
            'created_at',
            'updated_at',
        ]);
});

it('normaliza el nombre trimando, colapsando espacios y pasando a mayúsculas', function (string $entrada, string $esperado) {
    expect(Accessory::normalizeName($entrada))->toBe($esperado);
})->with([
    'con espacios de sobra' => ['  gato   hidraulico ', 'GATO HIDRAULICO'],
    'ya normalizado' => ['GATO HIDRAULICO', 'GATO HIDRAULICO'],
    'con saltos de línea' => ["gato\n\thidraulico", 'GATO HIDRAULICO'],
    'vacío' => ['   ', ''],
]);

it('normaliza el código sin colapsar sus espacios internos', function (string $entrada, string $esperado) {
    expect(Accessory::normalizeCode($entrada))->toBe($esperado);
})->with([
    'con espacios alrededor' => ['  acc-0001 ', 'ACC-0001'],
    'ya normalizado' => ['ACC-0001', 'ACC-0001'],
    /** A 100 y A100 son dos códigos distintos: el código es un identificador, no una frase. */
    'con espacio interno' => ['a 100', 'A 100'],
    'vacío' => ['   ', ''],
]);

it('castea el dinero a decimal de dos, la fecha de compra a fecha y el estado al enum', function () {
    $accessory = Accessory::factory()->underRepair()->create([
        'price' => 1500.5,
        'annual_depreciation' => 10,
        'purchase_date' => '2024-01-15',
    ])->fresh();

    expect($accessory->price)->toBe('1500.50')
        ->and($accessory->annual_depreciation)->toBe('10.00')
        ->and($accessory->purchase_date->format('Y-m-d'))->toBe('2024-01-15')
        ->and($accessory->status)->toBe(AccessoryStatus::UnderRepair);
});

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('persiste el accesorio normalizando el nombre y el código', function () {
    $admin = accessoryServiceAdmin();

    $accessory = accessoryService()->createAccessory(accessoryServiceData([
        'name' => '  gato   hidraulico 20 ton ',
        'code' => ' acc-0001 ',
    ]), $admin);

    expect($accessory->name)->toBe('GATO HIDRAULICO 20 TON')
        ->and($accessory->code)->toBe('ACC-0001')
        ->and($accessory->description)->toBeNull()
        ->and(Accessory::query()->whereKey($accessory->id)->exists())->toBeTrue();
});

it('guarda la descripción cuando llega y la deja en null cuando no', function () {
    $admin = accessoryServiceAdmin();

    $conDescripcion = accessoryService()->createAccessory(accessoryServiceData(['description' => 'el de la bodega']), $admin);
    $sinDescripcion = accessoryService()->createAccessory(accessoryServiceData([
        'name' => 'lona de carga',
        'code' => 'acc-0002',
    ]), $admin);

    expect($conDescripcion->description)->toBe('el de la bodega')
        ->and($sinDescripcion->description)->toBeNull();
});

it('ignora el status del body: el accesorio nace siempre activo', function () {
    $accessory = accessoryService()->createAccessory(
        accessoryServiceData(['status' => AccessoryStatus::UnderRepair->value]),
        accessoryServiceAdmin(),
    );

    expect($accessory->status)->toBe(AccessoryStatus::Active)
        ->and($accessory->fresh()->status)->toBe(AccessoryStatus::Active);
});

it('registra al usuario recibido como responsable del alta', function () {
    $admin = accessoryServiceAdmin();

    $accessory = accessoryService()->createAccessory(accessoryServiceData(), $admin);

    expect($accessory->registered_by)->toBe($admin->id)
        ->and($accessory->registeredBy->name)->toBe($admin->name)
        ->and($accessory->relationLoaded('registeredBy'))->toBeTrue();
});

it('rechaza un nombre que ya existe aunque llegue en otra caja', function () {
    $admin = accessoryServiceAdmin();
    accessoryService()->createAccessory(accessoryServiceData(['name' => 'GATO HIDRAULICO 20 TON']), $admin);

    accessoryService()->createAccessory(accessoryServiceData([
        'name' => 'Gato   Hidraulico 20 Ton',
        'code' => 'acc-0002',
    ]), $admin);
})->throws(BadRequestError::class, 'Ya existe un accesorio con ese nombre');

it('rechaza un código que ya existe aunque llegue en otra caja', function () {
    $admin = accessoryServiceAdmin();
    accessoryService()->createAccessory(accessoryServiceData(['code' => 'ACC-0001']), $admin);

    accessoryService()->createAccessory(accessoryServiceData([
        'name' => 'lona de carga',
        'code' => ' acc-0001 ',
    ]), $admin);
})->throws(BadRequestError::class, 'Ya existe un accesorio con ese código');

it('no libera el código de un accesorio dado de baja', function () {
    $admin = accessoryServiceAdmin();
    $accessory = accessoryService()->createAccessory(accessoryServiceData(['code' => 'ACC-0001']), $admin);

    accessoryService()->deleteAccessory($accessory->id);

    /** A diferencia de la placa de un vehículo, un inactive conserva su código para siempre. */
    expect(fn () => accessoryService()->createAccessory(accessoryServiceData([
        'name' => 'lona de carga',
        'code' => 'acc-0001',
    ]), $admin))->toThrow(BadRequestError::class, 'Ya existe un accesorio con ese código');

    expect(Accessory::query()->count())->toBe(1);
});

it('acepta dos códigos que solo difieren en los espacios internos', function () {
    $admin = accessoryServiceAdmin();
    accessoryService()->createAccessory(accessoryServiceData(['name' => 'gato a', 'code' => 'A 100']), $admin);
    accessoryService()->createAccessory(accessoryServiceData(['name' => 'gato b', 'code' => 'A100']), $admin);

    expect(Accessory::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Detalle
|--------------------------------------------------------------------------
*/

it('devuelve el accesorio por id sea cual sea su estado', function (string $estado) {
    $accessory = Accessory::factory()->{$estado}()->create();

    expect(accessoryService()->getAccessoryById($accessory->id)->id)->toBe($accessory->id);
})->with(['active', 'inactive', 'underRepair']);

it('carga el responsable del alta también en el detalle', function () {
    $accessory = Accessory::factory()->create();

    expect(accessoryService()->getAccessoryById($accessory->id)->relationLoaded('registeredBy'))->toBeTrue();
});

it('lanza NotFoundError sobre un id inexistente', function (string $method) {
    accessoryService()->{$method}(9999);
})->with(['getAccessoryById', 'deleteAccessory'])->throws(NotFoundError::class, 'El accesorio no existe');

it('lanza NotFoundError al editar un id inexistente', function () {
    accessoryService()->updateAccessory(['name' => 'gato hidraulico'], 9999);
})->throws(NotFoundError::class, 'El accesorio no existe');

/*
|--------------------------------------------------------------------------
| Edición
|--------------------------------------------------------------------------
*/

it('no altera el resto de campos cuando el update solo cambia el nombre', function () {
    $accessory = Accessory::factory()->create([
        'name' => 'GATO HIDRAULICO',
        'code' => 'ACC-0001',
        'description' => 'la de siempre',
        'price' => 1500,
        'purchase_date' => '2024-01-15',
        'annual_depreciation' => 10,
    ]);

    $updated = accessoryService()->updateAccessory(['name' => '  gato   nuevo '], $accessory->id);

    expect($updated->name)->toBe('GATO NUEVO')
        ->and($updated->code)->toBe('ACC-0001')
        ->and($updated->description)->toBe('la de siempre')
        ->and($updated->price)->toBe('1500.00')
        ->and($updated->purchase_date->format('Y-m-d'))->toBe('2024-01-15')
        ->and($updated->annual_depreciation)->toBe('10.00');
});

it('mueve el estado libremente entre los tres valores', function () {
    $accessory = Accessory::factory()->inactive()->create();
    $service = accessoryService();

    expect($service->updateAccessory(['status' => AccessoryStatus::Active->value], $accessory->id)->status)
        ->toBe(AccessoryStatus::Active)
        ->and($service->updateAccessory(['status' => AccessoryStatus::UnderRepair->value], $accessory->id)->status)
        ->toBe(AccessoryStatus::UnderRepair)
        ->and($service->updateAccessory(['status' => AccessoryStatus::Inactive->value], $accessory->id)->status)
        ->toBe(AccessoryStatus::Inactive);
});

it('borra la descripción cuando el update manda null', function () {
    $accessory = Accessory::factory()->create(['description' => 'la de siempre']);

    expect(accessoryService()->updateAccessory(['description' => null], $accessory->id)->description)->toBeNull();
});

it('rechaza en la edición el nombre de otro accesorio y acepta el propio', function () {
    $accessory = Accessory::factory()->create(['name' => 'GATO HIDRAULICO', 'code' => 'ACC-0001']);

    expect(accessoryService()->updateAccessory(['name' => 'gato hidraulico'], $accessory->id)->name)
        ->toBe('GATO HIDRAULICO');

    Accessory::factory()->create(['name' => 'LONA DE CARGA', 'code' => 'ACC-0002']);

    expect(fn () => accessoryService()->updateAccessory(['name' => 'lona de carga'], $accessory->id))
        ->toThrow(BadRequestError::class, 'Ya existe un accesorio con ese nombre');
});

it('rechaza en la edición el código de otro accesorio y acepta el propio', function () {
    $accessory = Accessory::factory()->create(['name' => 'GATO HIDRAULICO', 'code' => 'ACC-0001']);
    Accessory::factory()->inactive()->create(['name' => 'LONA DE CARGA', 'code' => 'ACC-0002']);

    expect(accessoryService()->updateAccessory(['code' => 'acc-0001'], $accessory->id)->code)->toBe('ACC-0001')
        ->and(accessoryService()->updateAccessory(['code' => 'acc-0003'], $accessory->id)->code)->toBe('ACC-0003');

    /** El estado de quien ocupa el código no se mira: un inactive tampoco lo suelta. */
    expect(fn () => accessoryService()->updateAccessory(['code' => 'acc-0002'], $accessory->id))
        ->toThrow(BadRequestError::class, 'Ya existe un accesorio con ese código');
});

it('no reescribe al responsable del alta al editar', function () {
    $accessory = Accessory::factory()->create();

    expect(accessoryService()->updateAccessory(['name' => 'otro nombre'], $accessory->id)->registered_by)
        ->toBe($accessory->registered_by);
});

it('acepta un body vacío como no-op', function () {
    $accessory = Accessory::factory()->create();

    $updated = accessoryService()->updateAccessory([], $accessory->id);

    expect($updated->name)->toBe($accessory->name)
        ->and($updated->code)->toBe($accessory->code)
        ->and($updated->status)->toBe($accessory->status);
});

/*
|--------------------------------------------------------------------------
| Baja lógica
|--------------------------------------------------------------------------
*/

it('da de baja sin borrar la fila y es idempotente', function () {
    $accessory = Accessory::factory()->active()->create();

    expect(accessoryService()->deleteAccessory($accessory->id)->status)->toBe(AccessoryStatus::Inactive)
        ->and(accessoryService()->deleteAccessory($accessory->id)->status)->toBe(AccessoryStatus::Inactive)
        ->and(Accessory::query()->whereKey($accessory->id)->count())->toBe(1);
});

it('da de baja también un accesorio que estaba en reparación', function () {
    $accessory = Accessory::factory()->underRepair()->create();

    expect(accessoryService()->deleteAccessory($accessory->id)->status)->toBe(AccessoryStatus::Inactive);
});

/*
|--------------------------------------------------------------------------
| Listado, filtros y paginación
|--------------------------------------------------------------------------
*/

it('devuelve la colección completa sin limit y pagina con él', function () {
    Accessory::factory()->count(3)->create();

    expect(accessoryService()->getAccessories([]))->toBeInstanceOf(Collection::class)
        ->and(accessoryService()->getAccessories(['limit' => null]))->toBeInstanceOf(Collection::class)
        ->and(accessoryService()->getAccessories(['limit' => 'abc']))->toBeInstanceOf(Collection::class)
        ->and(accessoryService()->getAccessories(['limit' => '10']))->toBeInstanceOf(LengthAwarePaginator::class);
});

it('acota el tamaño de página a [10, 100]', function (string $limit, int $esperado) {
    expect(accessoryService()->getAccessories(['limit' => $limit])->perPage())->toBe($esperado);
})->with([
    'por debajo' => ['5', 10],
    'en el mínimo' => ['10', 10],
    'dentro' => ['25', 25],
    'en el máximo' => ['100', 100],
    'por encima' => ['500', 100],
]);

it('filtra por estado e ignora cualquier valor fuera del enum', function () {
    Accessory::factory()->active()->create();
    Accessory::factory()->inactive()->create();
    Accessory::factory()->underRepair()->create();

    expect(accessoryService()->getAccessories(['status' => 'active']))->toHaveCount(1)
        ->and(accessoryService()->getAccessories(['status' => 'inactive']))->toHaveCount(1)
        ->and(accessoryService()->getAccessories(['status' => 'under_repair']))->toHaveCount(1)
        ->and(accessoryService()->getAccessories(['status' => 'perdido']))->toHaveCount(3)
        ->and(accessoryService()->getAccessories(['status' => null]))->toHaveCount(3)
        ->and(accessoryService()->getAccessories([]))->toHaveCount(3);
});

it('busca por nombre y por código normalizando el término antes del LIKE', function () {
    Accessory::factory()->create(['name' => 'GATO HIDRAULICO 20 TON', 'code' => 'ACC-0001']);
    Accessory::factory()->create(['name' => 'LONA DE CARGA 8X12', 'code' => 'LON-0002']);

    expect(accessoryService()->getAccessories(['search' => 'gato']))->toHaveCount(1)
        ->and(accessoryService()->getAccessories(['search' => 'GATO']))->toHaveCount(1)
        ->and(accessoryService()->getAccessories(['search' => 'acc-0001']))->toHaveCount(1)
        ->and(accessoryService()->getAccessories(['search' => 'ACC-0001']))->toHaveCount(1)
        ->and(accessoryService()->getAccessories(['search' => '  gato   hidraulico ']))->toHaveCount(1)
        ->and(accessoryService()->getAccessories(['search' => '   ']))->toHaveCount(2)
        ->and(accessoryService()->getAccessories(['search' => null]))->toHaveCount(2);
});

it('combina la búsqueda con el filtro de estado', function () {
    Accessory::factory()->active()->create(['name' => 'GATO HIDRAULICO', 'code' => 'ACC-0001']);
    Accessory::factory()->inactive()->create(['name' => 'GATO DE BOTELLA', 'code' => 'ACC-0002']);

    $activos = accessoryService()->getAccessories(['search' => 'gato', 'status' => 'active']);

    expect($activos)->toHaveCount(1)
        ->and($activos->first()->name)->toBe('GATO HIDRAULICO');
});

it('devuelve los accesorios ordenados por id ascendente', function () {
    $accessories = Accessory::factory()->count(5)->create();

    $ids = $accessories->pluck('id')->sort()->values()->all();

    expect(accessoryService()->getAccessories([])->pluck('id')->all())->toBe($ids);
});

it('carga el responsable del alta con el listado, sin N+1', function () {
    Accessory::factory()->count(3)->create();

    $accessories = accessoryService()->getAccessories([]);

    expect($accessories->every(fn (Accessory $accessory) => $accessory->relationLoaded('registeredBy')))->toBeTrue();
});
