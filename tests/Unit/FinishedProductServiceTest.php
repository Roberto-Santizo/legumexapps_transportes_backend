<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\FinishedProduct\FinishedProductServiceInterface;
use App\Models\Client;
use App\Models\FinishedProduct;
use App\Models\User;
use App\Services\FinishedProduct\FinishedProductService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

function finishedProductService(): FinishedProductServiceInterface
{
    return app(FinishedProductServiceInterface::class);
}

function finishedProductServiceAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * The payload the service expects, straight from the validated request.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function finishedProductServiceData(array $overrides = []): array
{
    return array_merge([
        'code' => ' bro-iqf-10 ',
        'name' => 'brócoli  florete ',
        'presentation' => 10,
        'boxesPerPallet' => 96.5,
        'clientId' => $overrides['clientId'] ?? Client::factory()->create()->id,
    ], $overrides);
}

it('resuelve la implementación registrada en el provider', function () {
    expect(finishedProductService())->toBeInstanceOf(FinishedProductService::class);
});

it('crea la tabla finished_products con sus columnas', function () {
    expect(Schema::getColumnListing('finished_products'))->toEqualCanonicalizing([
        'id', 'code', 'name', 'presentation', 'boxes_per_pallet', 'client_id',
        'registered_by', 'created_at', 'updated_at', 'deleted_at',
    ]);
});

it('normaliza el código con trim y mayúsculas, sin colapsar espacios internos', function (string $entrada, string $esperado) {
    expect(FinishedProduct::normalizeCode($entrada))->toBe($esperado);
})->with([
    'con espacios alrededor' => [' bro-iqf-10 ', 'BRO-IQF-10'],
    'con tilde' => ['ñoño', 'ÑOÑO'],
    'con espacio en medio' => ['a b', 'A B'],
]);

it('normaliza el nombre solo a mayúsculas, sin trim ni colapso', function (string $entrada, string $esperado) {
    expect(FinishedProduct::normalizeName($entrada))->toBe($esperado);
})->with([
    'con tilde' => ['Brócoli florete', 'BRÓCOLI FLORETE'],
    'con espacios' => ['  arveja   china ', '  ARVEJA   CHINA '],
]);

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('crea el producto normalizado, con el autor y las relaciones cargadas', function () {
    $admin = finishedProductServiceAdmin();

    $product = finishedProductService()->createFinishedProduct(finishedProductServiceData(), $admin);

    expect($product->code)->toBe('BRO-IQF-10')
        ->and($product->name)->toBe('BRÓCOLI  FLORETE ')
        ->and($product->presentation)->toBe('10.00')
        ->and($product->boxes_per_pallet)->toBe('96.50')
        ->and($product->registered_by)->toBe($admin->id)
        ->and($product->relationLoaded('client'))->toBeTrue()
        ->and($product->relationLoaded('registeredBy'))->toBeTrue();
});

it('lanza 400 con un código ocupado, también por una fila borrada', function (bool $borrado) {
    $factory = FinishedProduct::factory();
    ($borrado ? $factory->trashed() : $factory)->create(['code' => 'BRO-IQF-10']);

    expect(fn () => finishedProductService()->createFinishedProduct(finishedProductServiceData(), finishedProductServiceAdmin()))
        ->toThrow(BadRequestError::class, 'Ya existe un producto terminado con ese código, que puede haber sido eliminado');
})->with(['activo' => false, 'borrado' => true]);

it('lanza 400 con un cliente borrado', function () {
    $client = Client::factory()->trashed()->create();

    expect(fn () => finishedProductService()->createFinishedProduct(finishedProductServiceData(['clientId' => $client->id]), finishedProductServiceAdmin()))
        ->toThrow(BadRequestError::class, 'El cliente seleccionado ya fue eliminado');
});

it('lanza 404 con un cliente inexistente en una llamada directa', function () {
    expect(fn () => finishedProductService()->createFinishedProduct(finishedProductServiceData(['clientId' => 99999]), finishedProductServiceAdmin()))
        ->toThrow(NotFoundError::class, 'El cliente no existe');
});

/*
|--------------------------------------------------------------------------
| Lectura
|--------------------------------------------------------------------------
*/

it('devuelve el producto por id y lanza 404 si no existe o está borrado', function () {
    $product = FinishedProduct::factory()->create();
    $borrado = FinishedProduct::factory()->trashed()->create();

    expect(finishedProductService()->getFinishedProductById($product->id)->id)->toBe($product->id)
        ->and(fn () => finishedProductService()->getFinishedProductById($borrado->id))
        ->toThrow(NotFoundError::class, 'El producto terminado no existe')
        ->and(fn () => finishedProductService()->getFinishedProductById(99999))
        ->toThrow(NotFoundError::class, 'El producto terminado no existe');
});

it('lista sin borrados, ordenado por id, como Collection sin limit', function () {
    $activos = FinishedProduct::factory()->count(3)->create();
    FinishedProduct::factory()->trashed()->create();

    $result = finishedProductService()->getFinishedProducts([]);

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result->pluck('id')->all())->toBe($activos->pluck('id')->all());
});

it('filtra por search sobre código y nombre', function () {
    $porCodigo = FinishedProduct::factory()->create(['code' => 'XYZ-1', 'name' => 'ARVEJA']);
    $porNombre = FinishedProduct::factory()->create(['code' => 'ABC-1', 'name' => 'MEZCLA XYZ']);
    FinishedProduct::factory()->create(['code' => 'ABC-2', 'name' => 'EJOTE']);

    expect(finishedProductService()->getFinishedProducts(['search' => ' xyz '])->pluck('id')->all())
        ->toBe([$porCodigo->id, $porNombre->id]);
});

it('filtra por clientId e ignora uno no numérico', function () {
    $client = Client::factory()->create();
    $propio = FinishedProduct::factory()->create(['client_id' => $client->id]);
    FinishedProduct::factory()->count(2)->create();

    expect(finishedProductService()->getFinishedProducts(['clientId' => (string) $client->id])->pluck('id')->all())->toBe([$propio->id])
        ->and(finishedProductService()->getFinishedProducts(['clientId' => 'abc']))->toHaveCount(3);
});

it('pagina con limit numérico acotado a [10, 100] y no pagina con uno no numérico', function (?string $limit, ?int $perPage) {
    FinishedProduct::factory()->count(2)->create();

    $result = finishedProductService()->getFinishedProducts(['limit' => $limit]);

    if ($perPage === null) {
        expect($result)->toBeInstanceOf(Collection::class);
    } else {
        expect($result)->toBeInstanceOf(LengthAwarePaginator::class)
            ->and($result->perPage())->toBe($perPage);
    }
})->with([
    'sin limit' => [null, null],
    'no numérico' => ['abc', null],
    'por debajo' => ['5', 10],
    'en rango' => ['25', 25],
    'por encima' => ['500', 100],
]);

/*
|--------------------------------------------------------------------------
| Edición y borrado
|--------------------------------------------------------------------------
*/

it('actualiza solo los campos enviados y no toca registered_by', function () {
    $product = FinishedProduct::factory()->create(['code' => 'SKU-1', 'presentation' => 5]);
    $autor = $product->registered_by;
    $nuevo = Client::factory()->create();

    $updated = finishedProductService()->updateFinishedProduct($product->id, ['name' => ' ejote', 'clientId' => $nuevo->id]);

    expect($updated->name)->toBe(' EJOTE')
        ->and($updated->code)->toBe('SKU-1')
        ->and($updated->presentation)->toBe('5.00')
        ->and($updated->client_id)->toBe($nuevo->id)
        ->and($updated->client->id)->toBe($nuevo->id)
        ->and($updated->registered_by)->toBe($autor);
});

it('es un no-op con un payload vacío', function () {
    $product = FinishedProduct::factory()->create();
    $antes = $product->fresh()->only(['code', 'name', 'presentation', 'boxes_per_pallet', 'client_id']);

    finishedProductService()->updateFinishedProduct($product->id, []);

    expect($product->fresh()->only(['code', 'name', 'presentation', 'boxes_per_pallet', 'client_id']))->toBe($antes);
});

it('ignora la propia fila al validar el código y choca con la ajena', function () {
    $product = FinishedProduct::factory()->create(['code' => 'SKU-1']);
    FinishedProduct::factory()->trashed()->create(['code' => 'SKU-2']);

    expect(finishedProductService()->updateFinishedProduct($product->id, ['code' => 'sku-1'])->code)->toBe('SKU-1')
        ->and(fn () => finishedProductService()->updateFinishedProduct($product->id, ['code' => 'sku-2']))
        ->toThrow(BadRequestError::class, 'Ya existe un producto terminado con ese código, que puede haber sido eliminado');
});

it('lanza 400 al editar hacia un cliente borrado', function () {
    $product = FinishedProduct::factory()->create();
    $client = Client::factory()->trashed()->create();

    expect(fn () => finishedProductService()->updateFinishedProduct($product->id, ['clientId' => $client->id]))
        ->toThrow(BadRequestError::class, 'El cliente seleccionado ya fue eliminado');
});

it('lanza 404 con un id inexistente y 400 con uno borrado al escribir', function (string $metodo) {
    $borrado = FinishedProduct::factory()->trashed()->create();
    $args = fn (int $id) => $metodo === 'updateFinishedProduct' ? [$id, ['name' => 'x']] : [$id];

    expect(fn () => finishedProductService()->{$metodo}(...$args(99999)))
        ->toThrow(NotFoundError::class, 'El producto terminado no existe')
        ->and(fn () => finishedProductService()->{$metodo}(...$args($borrado->id)))
        ->toThrow(BadRequestError::class, 'El producto terminado ya fue eliminado');
})->with(['updateFinishedProduct', 'deleteFinishedProduct']);

it('borra lógicamente y devuelve el modelo ya borrado', function () {
    $product = FinishedProduct::factory()->create();

    $deleted = finishedProductService()->deleteFinishedProduct($product->id);

    expect($deleted->trashed())->toBeTrue()
        ->and(FinishedProduct::query()->find($product->id))->toBeNull()
        ->and(FinishedProduct::withTrashed()->find($product->id))->not->toBeNull();
});
