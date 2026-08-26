<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\ShippingLine\ShippingLineServiceInterface;
use App\Models\ShippingLine;
use App\Models\User;
use App\Services\ShippingLine\ShippingLineService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

function shippingLineService(): ShippingLineServiceInterface
{
    return app(ShippingLineServiceInterface::class);
}

function shippingLineServiceAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * The payload the service expects, straight from the validated request.
 *
 * A single key: this domain has no `code`.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shippingLineServiceData(array $overrides = []): array
{
    return array_merge([
        'name' => 'maersk line',
    ], $overrides);
}

it('resuelve la implementación de navieras registrada en el provider', function () {
    expect(shippingLineService())->toBeInstanceOf(ShippingLineService::class);
});

/*
|--------------------------------------------------------------------------
| Base de datos y modelo
|--------------------------------------------------------------------------
*/

it('crea la tabla shipping_lines con sus columnas, incluida deleted_at', function () {
    expect(Schema::hasTable('shipping_lines'))->toBeTrue()
        ->and(Schema::getColumnListing('shipping_lines'))->toEqualCanonicalizing([
            'id',
            'name',
            'registered_by',
            'created_at',
            'updated_at',
            'deleted_at',
        ]);
});

it('no expone ninguna columna de código: el nombre es el único campo de negocio', function () {
    expect(Schema::hasColumn('shipping_lines', 'code'))->toBeFalse()
        ->and(method_exists(ShippingLine::class, 'normalizeCode'))->toBeFalse();
});

it('normaliza el nombre trimando, colapsando espacios y pasando a mayúsculas', function (string $entrada, string $esperado) {
    expect(ShippingLine::normalizeName($entrada))->toBe($esperado);
})->with([
    'con espacios de sobra' => ['  maersk   line ', 'MAERSK LINE'],
    'ya normalizado' => ['MAERSK LINE', 'MAERSK LINE'],
    'con saltos de línea' => ["maersk\n\tline", 'MAERSK LINE'],
    'vacío' => ['   ', ''],
]);

/*
|--------------------------------------------------------------------------
| create()
|--------------------------------------------------------------------------
*/

it('persiste la naviera normalizando el nombre', function () {
    $admin = shippingLineServiceAdmin();

    $shippingLine = shippingLineService()->create($admin, shippingLineServiceData(['name' => '  maersk   line ']));

    expect($shippingLine->name)->toBe('MAERSK LINE')
        ->and($shippingLine->deleted_at)->toBeNull()
        ->and(ShippingLine::query()->whereKey($shippingLine->id)->exists())->toBeTrue();
});

it('registra al usuario recibido como responsable del alta', function () {
    $admin = shippingLineServiceAdmin();

    $shippingLine = shippingLineService()->create($admin, shippingLineServiceData());

    expect($shippingLine->registered_by)->toBe($admin->id)
        ->and($shippingLine->registeredBy->name)->toBe($admin->name);
});

it('carga la relación del registrador en el alta', function () {
    expect(shippingLineService()->create(shippingLineServiceAdmin(), shippingLineServiceData())->relationLoaded('registeredBy'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Guarda privada: ensureNameIsAvailable()
|--------------------------------------------------------------------------
*/

it('rechaza un nombre que ya existe aunque llegue en otra caja o con espacios de sobra', function (string $name) {
    $admin = shippingLineServiceAdmin();
    shippingLineService()->create($admin, shippingLineServiceData(['name' => 'MAERSK LINE']));

    shippingLineService()->create($admin, shippingLineServiceData(['name' => $name]));
})->with([
    'en minúsculas' => 'maersk line',
    'con espacios de sobra' => '  maersk   line ',
])->throws(BadRequestError::class, 'Ya existe una naviera con ese nombre, que puede haber sido eliminada');

it('sigue viendo el nombre de una naviera borrada al comprobar la disponibilidad', function () {
    ShippingLine::factory()->trashed()->create(['name' => 'MAERSK LINE']);

    expect(fn () => shippingLineService()->create(shippingLineServiceAdmin(), shippingLineServiceData(['name' => 'maersk line'])))
        ->toThrow(BadRequestError::class, 'Ya existe una naviera con ese nombre, que puede haber sido eliminada');

    expect(ShippingLine::withTrashed()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| update()
|--------------------------------------------------------------------------
*/

it('actualiza el nombre normalizándolo', function () {
    $shippingLine = ShippingLine::factory()->create(['name' => 'MAERSK LINE']);

    $updated = shippingLineService()->update($shippingLine->id, ['name' => '  hapag   lloyd ']);

    expect($updated->id)->toBe($shippingLine->id)
        ->and($updated->name)->toBe('HAPAG LLOYD');
});

it('acepta un body vacío como no-op', function () {
    $shippingLine = ShippingLine::factory()->create();

    expect(shippingLineService()->update($shippingLine->id, [])->name)->toBe($shippingLine->name);
});

it('acepta en la edición el propio nombre de la fila gracias al ignoreId', function () {
    $shippingLine = ShippingLine::factory()->create(['name' => 'MAERSK LINE']);

    expect(shippingLineService()->update($shippingLine->id, ['name' => '  maersk   line '])->name)->toBe('MAERSK LINE');
});

it('rechaza en la edición el nombre de otra fila, esté viva o borrada', function (string $estado) {
    $shippingLine = ShippingLine::factory()->create(['name' => 'MAERSK LINE']);

    $estado === 'viva'
        ? ShippingLine::factory()->create(['name' => 'HAPAG LLOYD'])
        : ShippingLine::factory()->trashed()->create(['name' => 'HAPAG LLOYD']);

    expect(fn () => shippingLineService()->update($shippingLine->id, ['name' => 'hapag lloyd']))
        ->toThrow(BadRequestError::class, 'Ya existe una naviera con ese nombre, que puede haber sido eliminada');

    expect($shippingLine->fresh()->name)->toBe('MAERSK LINE');
})->with(['viva', 'borrada']);

it('no reescribe al responsable del alta al editar', function () {
    $shippingLine = ShippingLine::factory()->create();

    expect(shippingLineService()->update($shippingLine->id, ['name' => 'otro nombre'])->registered_by)->toBe($shippingLine->registered_by);
});

it('carga la relación del registrador en la edición', function () {
    $shippingLine = ShippingLine::factory()->create();

    expect(shippingLineService()->update($shippingLine->id, ['name' => 'otro nombre'])->relationLoaded('registeredBy'))->toBeTrue();
});

it('lanza NotFoundError al editar un id inexistente', function () {
    shippingLineService()->update(9999, ['name' => 'maersk line']);
})->throws(NotFoundError::class, 'La naviera no existe');

it('lanza BadRequestError al editar una naviera ya borrada', function () {
    $shippingLine = ShippingLine::factory()->trashed()->create();

    shippingLineService()->update($shippingLine->id, ['name' => 'hapag lloyd']);
})->throws(BadRequestError::class, 'La naviera ya fue eliminada');

/*
|--------------------------------------------------------------------------
| destroy()
|--------------------------------------------------------------------------
*/

it('borra lógicamente la naviera dejando la fila en la tabla', function () {
    $shippingLine = ShippingLine::factory()->create();

    $deleted = shippingLineService()->destroy($shippingLine->id);

    expect($deleted->id)->toBe($shippingLine->id)
        ->and($deleted->trashed())->toBeTrue()
        ->and(ShippingLine::query()->whereKey($shippingLine->id)->exists())->toBeFalse()
        ->and(ShippingLine::withTrashed()->whereKey($shippingLine->id)->exists())->toBeTrue();
});

it('no libera el nombre de la naviera que acaba de borrar', function () {
    $admin = shippingLineServiceAdmin();
    $shippingLine = shippingLineService()->create($admin, shippingLineServiceData(['name' => 'maersk line']));

    shippingLineService()->destroy($shippingLine->id);

    expect(fn () => shippingLineService()->create($admin, shippingLineServiceData(['name' => 'MAERSK LINE'])))
        ->toThrow(BadRequestError::class, 'Ya existe una naviera con ese nombre, que puede haber sido eliminada');
});

it('lanza BadRequestError en el segundo borrado, que no es idempotente', function () {
    $shippingLine = ShippingLine::factory()->create();

    shippingLineService()->destroy($shippingLine->id);
    shippingLineService()->destroy($shippingLine->id);
})->throws(BadRequestError::class, 'La naviera ya fue eliminada');

it('lanza NotFoundError sobre un id inexistente', function (string $method) {
    shippingLineService()->{$method}(9999);
})->with(['getShippingLineById', 'destroy'])->throws(NotFoundError::class, 'La naviera no existe');

/*
|--------------------------------------------------------------------------
| getShippingLineById()
|--------------------------------------------------------------------------
*/

it('devuelve la naviera por id con su registrador cargado', function () {
    $shippingLine = ShippingLine::factory()->create();

    $found = shippingLineService()->getShippingLineById($shippingLine->id);

    expect($found->id)->toBe($shippingLine->id)
        ->and($found->relationLoaded('registeredBy'))->toBeTrue();
});

it('lanza NotFoundError, y no BadRequestError, al consultar una naviera borrada', function () {
    $shippingLine = ShippingLine::factory()->trashed()->create();

    shippingLineService()->getShippingLineById($shippingLine->id);
})->throws(NotFoundError::class, 'La naviera no existe');

/*
|--------------------------------------------------------------------------
| getShippingLines(): listado, búsqueda y paginación
|--------------------------------------------------------------------------
*/

it('devuelve la colección completa sin limit y pagina con él', function () {
    ShippingLine::factory()->count(3)->create();

    expect(shippingLineService()->getShippingLines([]))->toBeInstanceOf(Collection::class)
        ->and(shippingLineService()->getShippingLines(['limit' => null]))->toBeInstanceOf(Collection::class)
        ->and(shippingLineService()->getShippingLines(['limit' => 'abc']))->toBeInstanceOf(Collection::class)
        ->and(shippingLineService()->getShippingLines(['limit' => '10']))->toBeInstanceOf(LengthAwarePaginator::class);
});

it('acota el tamaño de página a [10, 100]', function (string $limit, int $esperado) {
    expect(shippingLineService()->getShippingLines(['limit' => $limit])->perPage())->toBe($esperado);
})->with([
    'por debajo' => ['1', 10],
    'en el mínimo' => ['10', 10],
    'dentro' => ['25', 25],
    'en el máximo' => ['100', 100],
    'por encima' => ['500', 100],
]);

it('deja fuera del listado a las navieras borradas', function () {
    ShippingLine::factory()->count(2)->create();
    ShippingLine::factory()->trashed()->count(3)->create();

    expect(shippingLineService()->getShippingLines([]))->toHaveCount(2)
        ->and(shippingLineService()->getShippingLines(['limit' => '10'])->total())->toBe(2);
});

it('busca con LIKE sobre el nombre, normalizando el término', function (string $search, int $esperados) {
    ShippingLine::factory()->create(['name' => 'MAERSK LINE']);
    ShippingLine::factory()->create(['name' => 'HAPAG LLOYD']);

    expect(shippingLineService()->getShippingLines(['search' => $search]))->toHaveCount($esperados);
})->with([
    'nombre completo en minúsculas' => ['maersk line', 1],
    'trozo del nombre' => ['maer', 1],
    'nombre con espacios de sobra' => ['  hapag   lloyd ', 1],
    'trozo compartido por ninguno' => ['inexistente', 0],
    'letra que comparten los dos' => ['L', 2],
    'en blanco' => ['   ', 2],
]);

it('devuelve el catálogo completo cuando la búsqueda no viene', function () {
    ShippingLine::factory()->count(2)->create();

    expect(shippingLineService()->getShippingLines(['search' => null]))->toHaveCount(2)
        ->and(shippingLineService()->getShippingLines([]))->toHaveCount(2);
});

it('devuelve las navieras ordenadas por id ascendente', function () {
    $shippingLines = ShippingLine::factory()->count(5)->create();

    $ids = $shippingLines->pluck('id')->sort()->values()->all();

    expect(shippingLineService()->getShippingLines([])->pluck('id')->all())->toBe($ids);
});

it('devuelve una colección vacía cuando el catálogo está vacío', function () {
    expect(shippingLineService()->getShippingLines([]))->toBeInstanceOf(Collection::class)->toHaveCount(0);
});

it('carga el responsable del alta con el listado, sin N+1', function () {
    ShippingLine::factory()->count(3)->create();

    expect(shippingLineService()->getShippingLines([])->every(fn (ShippingLine $shippingLine) => $shippingLine->relationLoaded('registeredBy')))->toBeTrue();
});
