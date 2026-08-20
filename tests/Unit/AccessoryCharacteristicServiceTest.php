<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\AccessoryCharacteristic\AccessoryCharacteristicServiceInterface;
use App\Models\Accessory;
use App\Models\AccessoryCharacteristic;
use App\Models\User;
use App\Services\AccessoryCharacteristic\AccessoryCharacteristicService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

function accessoryCharacteristicService(): AccessoryCharacteristicServiceInterface
{
    return app(AccessoryCharacteristicServiceInterface::class);
}

function accessoryCharacteristicServiceAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * The listing always returns its collection wrapped in an array, like the vehicle expenses.
 *
 * @param  array<string, mixed>  $filters
 * @return LengthAwarePaginator<int, AccessoryCharacteristic>|Collection<int, AccessoryCharacteristic>
 */
function accessoryCharacteristicsOf(array $filters)
{
    return accessoryCharacteristicService()->getAccessoryCharacteristics($filters)['characteristics'];
}

/**
 * The payload the service expects, in the snake_case of the store request.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function accessoryCharacteristicServiceData(int $accessoryId, array $overrides = []): array
{
    return array_merge([
        'accessory_id' => $accessoryId,
        'name' => 'placa',
        'value' => 'P-123ABC',
    ], $overrides);
}

it('resuelve la implementación de características registrada en el provider', function () {
    expect(accessoryCharacteristicService())->toBeInstanceOf(AccessoryCharacteristicService::class);
});

/*
|--------------------------------------------------------------------------
| Base de datos y modelo
|--------------------------------------------------------------------------
*/

it('crea la tabla accessory_characteristics con sus columnas', function () {
    expect(Schema::hasTable('accessory_characteristics'))->toBeTrue()
        ->and(Schema::getColumnListing('accessory_characteristics'))->toEqualCanonicalizing([
            'id',
            'accessory_id',
            'name',
            'value',
            'registered_by',
            'created_at',
            'updated_at',
        ]);
});

it('normaliza el nombre trimando, colapsando espacios y pasando a mayúsculas', function (string $entrada, string $esperado) {
    expect(AccessoryCharacteristic::normalizeName($entrada))->toBe($esperado);
})->with([
    'con espacios de sobra' => ['  placa   trasera ', 'PLACA TRASERA'],
    'ya normalizado' => ['PLACA TRASERA', 'PLACA TRASERA'],
    'con saltos de línea' => ["placa\n\ttrasera", 'PLACA TRASERA'],
    'vacío' => ['   ', ''],
]);

it('normaliza el valor recortándolo y nada más', function (string $entrada, string $esperado) {
    expect(AccessoryCharacteristic::normalizeValue($entrada))->toBe($esperado);
})->with([
    /** Asimétrico con el nombre: la caja del contenido del usuario no se toca. */
    'con espacios alrededor' => ['  Diésel ', 'Diésel'],
    'con espacios internos' => ['  Acero   inoxidable ', 'Acero   inoxidable'],
    'ya normalizado' => ['P-123ABC', 'P-123ABC'],
    'vacío' => ['   ', ''],
]);

it('expone las relaciones con el accesorio y con quien la capturó', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = AccessoryCharacteristic::factory()->create([
        'accessory_id' => $accessory->id,
        'registered_by' => $admin->id,
    ]);

    expect($characteristic->accessory->id)->toBe($accessory->id)
        ->and($characteristic->registeredBy->id)->toBe($admin->id);
});

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('persiste la característica normalizando el nombre y recortando el valor', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    $characteristic = accessoryCharacteristicService()->createAccessoryCharacteristic(
        accessoryCharacteristicServiceData($accessory->id, ['name' => '  placa   trasera ', 'value' => '  Diésel ']),
        $admin,
    );

    expect($characteristic->name)->toBe('PLACA TRASERA')
        ->and($characteristic->value)->toBe('Diésel')
        ->and($characteristic->accessory_id)->toBe($accessory->id)
        ->and($characteristic->relationLoaded('registeredBy'))->toBeTrue();

    $this->assertDatabaseHas('accessory_characteristics', [
        'id' => $characteristic->id,
        'accessory_id' => $accessory->id,
        'name' => 'PLACA TRASERA',
        'value' => 'Diésel',
    ]);
});

it('registra como responsable al usuario recibido, no al del body', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $otro = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $otro->id]);

    $characteristic = accessoryCharacteristicService()->createAccessoryCharacteristic(
        accessoryCharacteristicServiceData($accessory->id, ['registered_by' => $otro->id]),
        $admin,
    );

    expect($characteristic->registered_by)->toBe($admin->id);
});

it('lanza NotFoundError al dar de alta sobre un accesorio inexistente', function () {
    accessoryCharacteristicService()->createAccessoryCharacteristic(
        accessoryCharacteristicServiceData(9999),
        accessoryCharacteristicServiceAdmin(),
    );
})->throws(NotFoundError::class, 'El accesorio no existe');

it('da de alta sobre un accesorio sin mirar su estado', function (string $estado) {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->{$estado}()->create(['registered_by' => $admin->id]);

    $characteristic = accessoryCharacteristicService()->createAccessoryCharacteristic(
        accessoryCharacteristicServiceData($accessory->id),
        $admin,
    );

    expect($characteristic->accessory_id)->toBe($accessory->id);
})->with(['active', 'inactive', 'underRepair']);

it('rechaza un nombre que el mismo accesorio ya tiene aunque llegue en otra caja', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    accessoryCharacteristicService()->createAccessoryCharacteristic(accessoryCharacteristicServiceData($accessory->id), $admin);

    expect(fn () => accessoryCharacteristicService()->createAccessoryCharacteristic(
        accessoryCharacteristicServiceData($accessory->id, ['name' => '  Placa ']),
        $admin,
    ))->toThrow(BadRequestError::class, 'El accesorio ya tiene una característica con ese nombre');

    expect(AccessoryCharacteristic::query()->count())->toBe(1);
});

it('acepta el mismo nombre en dos accesorios distintos', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $uno = Accessory::factory()->create(['registered_by' => $admin->id]);
    $otro = Accessory::factory()->create(['registered_by' => $admin->id]);

    accessoryCharacteristicService()->createAccessoryCharacteristic(accessoryCharacteristicServiceData($uno->id), $admin);
    accessoryCharacteristicService()->createAccessoryCharacteristic(accessoryCharacteristicServiceData($otro->id), $admin);

    expect(AccessoryCharacteristic::query()->where('name', '=', 'PLACA')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Detalle
|--------------------------------------------------------------------------
*/

it('devuelve la característica por id con su responsable cargado', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = AccessoryCharacteristic::factory()->create([
        'accessory_id' => $accessory->id,
        'registered_by' => $admin->id,
    ]);

    $encontrada = accessoryCharacteristicService()->getAccessoryCharacteristicById($characteristic->id);

    expect($encontrada->id)->toBe($characteristic->id)
        ->and($encontrada->relationLoaded('registeredBy'))->toBeTrue();
});

it('lanza NotFoundError sobre un id de característica inexistente', function (string $method) {
    accessoryCharacteristicService()->{$method}(9999);
})->with(['getAccessoryCharacteristicById', 'deleteAccessoryCharacteristic'])->throws(NotFoundError::class, 'La característica no existe');

it('lanza NotFoundError al editar un id de característica inexistente', function () {
    accessoryCharacteristicService()->updateAccessoryCharacteristic(['value' => 'otro'], 9999);
})->throws(NotFoundError::class, 'La característica no existe');

/*
|--------------------------------------------------------------------------
| Edición
|--------------------------------------------------------------------------
*/

it('cambia solo el campo que llega y deja el otro intacto', function (string $campo, string $entrada, string $esperado, string $intacto) {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = AccessoryCharacteristic::factory()->create([
        'accessory_id' => $accessory->id,
        'registered_by' => $admin->id,
        'name' => 'PLACA',
        'value' => 'P-123ABC',
    ]);

    $actualizada = accessoryCharacteristicService()->updateAccessoryCharacteristic([$campo => $entrada], $characteristic->id);

    $otro = $campo === 'name' ? 'value' : 'name';

    expect($actualizada->{$campo})->toBe($esperado)
        ->and($actualizada->{$otro})->toBe($intacto);
})->with([
    'solo el nombre' => ['name', '  placa   trasera ', 'PLACA TRASERA', 'P-123ABC'],
    'solo el valor' => ['value', '  P-999XYZ ', 'P-999XYZ', 'PLACA'],
]);

it('ignora en silencio un accessory_id que llegue en el update', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $uno = Accessory::factory()->create(['registered_by' => $admin->id]);
    $otro = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = AccessoryCharacteristic::factory()->create([
        'accessory_id' => $uno->id,
        'registered_by' => $admin->id,
    ]);

    $actualizada = accessoryCharacteristicService()->updateAccessoryCharacteristic([
        'accessory_id' => $otro->id,
        'value' => 'otro valor',
    ], $characteristic->id);

    expect($actualizada->accessory_id)->toBe($uno->id)
        ->and($actualizada->value)->toBe('otro valor');

    $this->assertDatabaseHas('accessory_characteristics', [
        'id' => $characteristic->id,
        'accessory_id' => $uno->id,
    ]);
});

it('no reescribe al responsable del alta al editar', function () {
    $original = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $original->id]);
    $characteristic = AccessoryCharacteristic::factory()->create([
        'accessory_id' => $accessory->id,
        'registered_by' => $original->id,
    ]);

    $otro = accessoryCharacteristicServiceAdmin();

    $actualizada = accessoryCharacteristicService()->updateAccessoryCharacteristic([
        'registered_by' => $otro->id,
        'value' => 'otro valor',
    ], $characteristic->id);

    expect($actualizada->registered_by)->toBe($original->id);
});

it('acepta un payload vacío como no-op que no toca la fila', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = AccessoryCharacteristic::factory()->create([
        'accessory_id' => $accessory->id,
        'registered_by' => $admin->id,
        'name' => 'PLACA',
        'value' => 'P-123ABC',
    ]);

    $actualizada = accessoryCharacteristicService()->updateAccessoryCharacteristic([], $characteristic->id);

    expect($actualizada->name)->toBe('PLACA')
        ->and($actualizada->value)->toBe('P-123ABC')
        ->and($actualizada->updated_at->equalTo($characteristic->updated_at))->toBeTrue();
});

it('acepta que la fila reenvíe su propio nombre y rechaza el de otra del mismo accesorio', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = AccessoryCharacteristic::factory()->create([
        'accessory_id' => $accessory->id,
        'registered_by' => $admin->id,
        'name' => 'PLACA',
    ]);
    AccessoryCharacteristic::factory()->create([
        'accessory_id' => $accessory->id,
        'registered_by' => $admin->id,
        'name' => 'COLOR',
    ]);

    expect(accessoryCharacteristicService()->updateAccessoryCharacteristic(['name' => ' placa '], $characteristic->id)->name)->toBe('PLACA');

    expect(fn () => accessoryCharacteristicService()->updateAccessoryCharacteristic(['name' => 'color'], $characteristic->id))
        ->toThrow(BadRequestError::class, 'El accesorio ya tiene una característica con ese nombre');

    expect($characteristic->fresh()->name)->toBe('PLACA');
});

it('valida la unicidad contra el accesorio guardado, no contra otro', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $uno = Accessory::factory()->create(['registered_by' => $admin->id]);
    $otro = Accessory::factory()->create(['registered_by' => $admin->id]);

    $characteristic = AccessoryCharacteristic::factory()->create([
        'accessory_id' => $uno->id,
        'registered_by' => $admin->id,
        'name' => 'COLOR',
    ]);
    AccessoryCharacteristic::factory()->create([
        'accessory_id' => $otro->id,
        'registered_by' => $admin->id,
        'name' => 'PLACA',
    ]);

    expect(accessoryCharacteristicService()->updateAccessoryCharacteristic(['name' => 'placa'], $characteristic->id)->name)->toBe('PLACA');
});

/*
|--------------------------------------------------------------------------
| Baja física
|--------------------------------------------------------------------------
*/

it('borra la fila de verdad y devuelve el modelo borrado', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    $characteristic = AccessoryCharacteristic::factory()->create([
        'accessory_id' => $accessory->id,
        'registered_by' => $admin->id,
        'name' => 'PLACA',
    ]);

    $borrada = accessoryCharacteristicService()->deleteAccessoryCharacteristic($characteristic->id);

    expect($borrada->id)->toBe($characteristic->id)
        ->and($borrada->name)->toBe('PLACA');

    $this->assertDatabaseMissing('accessory_characteristics', ['id' => $characteristic->id]);

    /** Sin SoftDeletes el segundo borrado ya no encuentra nada. */
    expect(fn () => accessoryCharacteristicService()->deleteAccessoryCharacteristic($characteristic->id))
        ->toThrow(NotFoundError::class, 'La característica no existe');
});

it('no toca el accesorio ni las demás características al borrar una', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->active()->create(['registered_by' => $admin->id]);
    $characteristics = AccessoryCharacteristic::factory()->count(3)->create([
        'accessory_id' => $accessory->id,
        'registered_by' => $admin->id,
    ]);

    accessoryCharacteristicService()->deleteAccessoryCharacteristic($characteristics->first()->id);

    expect(AccessoryCharacteristic::query()->where('accessory_id', '=', $accessory->id)->count())->toBe(2)
        ->and(Accessory::query()->whereKey($accessory->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Listado
|--------------------------------------------------------------------------
*/

it('devuelve el listado envuelto en la clave characteristics', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    AccessoryCharacteristic::factory()->count(2)->create([
        'accessory_id' => $accessory->id,
        'registered_by' => $admin->id,
    ]);

    $resultado = accessoryCharacteristicService()->getAccessoryCharacteristics(['accessoryId' => $accessory->id]);

    expect($resultado)->toBeArray()
        ->and(array_keys($resultado))->toBe(['characteristics'])
        ->and($resultado['characteristics'])->toHaveCount(2);
});

it('lanza NotFoundError al listar un accesorio inexistente', function () {
    accessoryCharacteristicService()->getAccessoryCharacteristics(['accessoryId' => 9999]);
})->throws(NotFoundError::class, 'El accesorio no existe');

it('lista las características de un accesorio sea cual sea su estado', function (string $estado) {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->{$estado}()->create(['registered_by' => $admin->id]);
    AccessoryCharacteristic::factory()->count(3)->create([
        'accessory_id' => $accessory->id,
        'registered_by' => $admin->id,
    ]);

    expect(accessoryCharacteristicsOf(['accessoryId' => $accessory->id]))->toHaveCount(3);
})->with(['active', 'inactive', 'underRepair']);

it('devuelve una colección vacía cuando el accesorio no tiene características', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    expect(accessoryCharacteristicsOf(['accessoryId' => $accessory->id]))->toBeInstanceOf(Collection::class)->toHaveCount(0);
});

it('devuelve solo las del accesorio pedido, ordenadas por id ascendente', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $uno = Accessory::factory()->create(['registered_by' => $admin->id]);
    $otro = Accessory::factory()->create(['registered_by' => $admin->id]);

    $propias = AccessoryCharacteristic::factory()->count(4)->create([
        'accessory_id' => $uno->id,
        'registered_by' => $admin->id,
    ]);
    AccessoryCharacteristic::factory()->count(3)->create([
        'accessory_id' => $otro->id,
        'registered_by' => $admin->id,
    ]);

    $listado = accessoryCharacteristicsOf(['accessoryId' => $uno->id]);

    expect($listado->pluck('id')->all())->toBe($propias->pluck('id')->sort()->values()->all())
        ->and($listado->pluck('accessory_id')->unique()->all())->toBe([$uno->id]);
});

it('devuelve la colección completa sin limit y pagina con él', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    AccessoryCharacteristic::factory()->count(3)->create([
        'accessory_id' => $accessory->id,
        'registered_by' => $admin->id,
    ]);

    expect(accessoryCharacteristicsOf(['accessoryId' => $accessory->id]))->toBeInstanceOf(Collection::class)
        ->and(accessoryCharacteristicsOf(['accessoryId' => $accessory->id, 'limit' => null]))->toBeInstanceOf(Collection::class)
        ->and(accessoryCharacteristicsOf(['accessoryId' => $accessory->id, 'limit' => 'abc']))->toBeInstanceOf(Collection::class)
        ->and(accessoryCharacteristicsOf(['accessoryId' => $accessory->id, 'limit' => '10']))->toBeInstanceOf(LengthAwarePaginator::class);
});

it('acota el tamaño de página a [10, 100]', function (string $limit, int $esperado) {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);

    expect(accessoryCharacteristicsOf(['accessoryId' => $accessory->id, 'limit' => $limit])->perPage())->toBe($esperado);
})->with([
    'por debajo' => ['1', 10],
    'en el mínimo' => ['10', 10],
    'dentro' => ['25', 25],
    'en el máximo' => ['100', 100],
    'por encima' => ['500', 100],
]);

it('carga el responsable del alta con el listado, sin N+1', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    AccessoryCharacteristic::factory()->count(3)->create([
        'accessory_id' => $accessory->id,
        'registered_by' => $admin->id,
    ]);

    $listado = accessoryCharacteristicsOf(['accessoryId' => $accessory->id]);

    expect($listado->every(fn (AccessoryCharacteristic $characteristic) => $characteristic->relationLoaded('registeredBy')))->toBeTrue();
});

it('acepta un accessoryId que llega como texto numérico', function () {
    $admin = accessoryCharacteristicServiceAdmin();
    $accessory = Accessory::factory()->create(['registered_by' => $admin->id]);
    AccessoryCharacteristic::factory()->count(2)->create([
        'accessory_id' => $accessory->id,
        'registered_by' => $admin->id,
    ]);

    expect(accessoryCharacteristicsOf(['accessoryId' => (string) $accessory->id]))->toHaveCount(2);
});
