<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\Product\ProductServiceInterface;
use App\Models\Product;
use App\Models\User;
use App\Services\Product\ProductService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

function productService(): ProductServiceInterface
{
    return app(ProductServiceInterface::class);
}

function productServiceAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

it('resuelve la implementación de productos registrada en el provider', function () {
    expect(productService())->toBeInstanceOf(ProductService::class);
});

/*
|--------------------------------------------------------------------------
| Base de datos y modelo
|--------------------------------------------------------------------------
*/

it('crea la tabla products con sus columnas', function () {
    expect(Schema::hasTable('products'))->toBeTrue()
        ->and(Schema::getColumnListing('products'))->toEqualCanonicalizing([
            'id',
            'name',
            'status',
            'registered_by',
            'created_at',
            'updated_at',
        ]);
});

it('nace activo un producto creado sin status explícito', function () {
    $product = Product::create([
        'name' => 'BROCOLI',
        'registered_by' => productServiceAdmin()->id,
    ]);

    expect($product->fresh()->status)->toBeTrue();
});

it('castea el status a booleano', function (bool $status) {
    $product = Product::factory()->{$status ? 'active' : 'inactive'}()->create()->fresh();

    expect($product->status)->toBeBool()->toBe($status);
})->with([
    'activo' => [true],
    'inactivo' => [false],
]);

it('expone al usuario que registró el producto', function () {
    $admin = productServiceAdmin();

    $product = Product::factory()->create(['registered_by' => $admin->id]);

    expect($product->registeredBy)->toBeInstanceOf(User::class)
        ->and($product->registeredBy->id)->toBe($admin->id);
});

it('impide en base dos productos con el mismo nombre', function () {
    Product::factory()->create(['name' => 'BROCOLI']);

    expect(fn () => Product::factory()->create(['name' => 'BROCOLI']))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Product::normalizeName()
|--------------------------------------------------------------------------
*/

it('normaliza el nombre recortando, colapsando espacios y pasando a mayúsculas', function (string $entrada, string $esperado) {
    expect(Product::normalizeName($entrada))->toBe($esperado);
})->with([
    'minúsculas' => ['brocoli', 'BROCOLI'],
    'espacios en los extremos' => ['  brocoli  ', 'BROCOLI'],
    'espacios internos repetidos' => ['  mini   zanahoria  ', 'MINI ZANAHORIA'],
    'tabulaciones y saltos de línea' => ["ejote\t\nfrances", 'EJOTE FRANCES'],
    'ya normalizado' => ['MINI ZANAHORIA', 'MINI ZANAHORIA'],
    'cadena vacía' => ['', ''],
    'solo espacios' => ['   ', ''],
    'acentos' => ['brócoli', 'BRÓCOLI'],
]);

/*
|--------------------------------------------------------------------------
| getProducts()
|--------------------------------------------------------------------------
*/

it('devuelve una colección con todos los productos sin limit', function () {
    Product::factory()->count(12)->create();

    $products = productService()->getProducts([]);

    expect($products)->toBeInstanceOf(Collection::class)
        ->and($products)->toHaveCount(12);
});

it('devuelve una colección de productos cuando limit no es numérico', function () {
    Product::factory()->count(12)->create();

    $products = productService()->getProducts(['limit' => 'abc']);

    expect($products)->toBeInstanceOf(Collection::class)
        ->and($products)->toHaveCount(12);
});

it('devuelve un paginador de productos cuando limit es numérico', function () {
    Product::factory()->count(12)->create();

    $products = productService()->getProducts(['limit' => '10']);

    expect($products)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($products->perPage())->toBe(10)
        ->and($products->total())->toBe(12)
        ->and($products->lastPage())->toBe(2);
});

it('acota el tamaño de página de productos al rango permitido', function (string $limit, int $expected) {
    Product::factory()->count(2)->create();

    expect(productService()->getProducts(['limit' => $limit])->perPage())->toBe($expected);
})->with([
    'por debajo del mínimo' => ['1', 10],
    'dentro del rango' => ['25', 25],
    'por encima del máximo' => ['500', 100],
]);

it('devuelve activos e inactivos cuando no se filtra por status', function () {
    Product::factory()->count(2)->active()->create();
    Product::factory()->count(3)->inactive()->create();

    expect(productService()->getProducts([]))->toHaveCount(5);
});

it('aplica el filtro de status solo cuando filter_var lo resuelve a booleano', function (?string $status, int $expected) {
    Product::factory()->count(2)->active()->create();
    Product::factory()->count(3)->inactive()->create();

    expect(productService()->getProducts(['status' => $status]))->toHaveCount($expected);
})->with([
    'sin filtro' => [null, 5],
    'true' => ['true', 2],
    'uno' => ['1', 2],
    'false' => ['false', 3],
    'cero' => ['0', 3],
    'palabra suelta' => ['quizas', 5],
    'número fuera de rango' => ['7', 5],
]);

it('busca por nombre con el término normalizado a mayúsculas', function (?string $search, int $expected) {
    Product::factory()->create(['name' => 'BROCOLI']);
    Product::factory()->create(['name' => 'BROCOLI MORADO']);
    Product::factory()->create(['name' => 'ZANAHORIA']);

    expect(productService()->getProducts(['search' => $search]))->toHaveCount($expected);
})->with([
    'sin filtro' => [null, 3],
    'término vacío' => ['', 3],
    'solo espacios' => ['   ', 3],
    'en minúsculas' => ['brocoli', 2],
    'en mayúsculas' => ['BROCOLI', 2],
    'coincidencia interna' => ['ocol', 2],
    'coincidencia única' => ['morado', 1],
    'sin coincidencias' => ['zzz', 0],
]);

it('combina los filtros de status y de búsqueda', function () {
    $buscado = Product::factory()->inactive()->create(['name' => 'BROCOLI MORADO']);

    Product::factory()->active()->create(['name' => 'BROCOLI VERDE']);
    Product::factory()->inactive()->create(['name' => 'ZANAHORIA']);

    expect(productService()->getProducts(['search' => 'brocoli', 'status' => 'false'])->pluck('id')->all())
        ->toBe([$buscado->id]);
});

it('ordena el catálogo por id ascendente', function () {
    $primero = Product::factory()->create(['name' => 'ZANAHORIA']);
    $segundo = Product::factory()->create(['name' => 'APIO']);
    $tercero = Product::factory()->create(['name' => 'BROCOLI']);

    expect(productService()->getProducts([])->pluck('id')->all())
        ->toBe([$primero->id, $segundo->id, $tercero->id]);
});

it('carga la relación del registrador al listar productos', function () {
    Product::factory()->count(3)->create();

    expect(productService()->getProducts([])->first()->relationLoaded('registeredBy'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| getProductById()
|--------------------------------------------------------------------------
*/

it('devuelve el producto que coincide con el id, esté activo o no', function (bool $active) {
    $product = Product::factory()->{$active ? 'active' : 'inactive'}()->create();

    $found = productService()->getProductById($product->id);

    expect($found)->toBeInstanceOf(Product::class)
        ->and($found->id)->toBe($product->id)
        ->and($found->relationLoaded('registeredBy'))->toBeTrue();
})->with([
    'activo' => [true],
    'dado de baja' => [false],
]);

it('lanza NotFoundError al buscar un producto inexistente', function () {
    expect(fn () => productService()->getProductById(99999))
        ->toThrow(NotFoundError::class, 'El producto no existe');
});

/*
|--------------------------------------------------------------------------
| create()
|--------------------------------------------------------------------------
*/

it('persiste el producto nuevo normalizado, activo y a nombre del usuario recibido', function () {
    $admin = productServiceAdmin();

    $product = productService()->create($admin, ['name' => '  brocoli   morado ']);

    expect($product)->toBeInstanceOf(Product::class)
        ->and($product->name)->toBe('BROCOLI MORADO')
        ->and($product->status)->toBeTrue()
        ->and($product->registered_by)->toBe($admin->id)
        ->and($product->relationLoaded('registeredBy'))->toBeTrue();

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'BROCOLI MORADO',
        'status' => true,
        'registered_by' => $admin->id,
    ]);
});

it('fuerza status true aunque el arreglo recibido pida lo contrario', function () {
    $product = productService()->create(productServiceAdmin(), ['name' => 'brocoli', 'status' => false]);

    expect($product->status)->toBeTrue();

    $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => true]);
});

it('ignora un registered_by que venga en el arreglo y usa el usuario recibido', function () {
    $ana = productServiceAdmin();
    $beto = productServiceAdmin();

    $product = productService()->create($ana, ['name' => 'brocoli', 'registered_by' => $beto->id]);

    expect($product->registered_by)->toBe($ana->id);

    $this->assertDatabaseMissing('products', ['registered_by' => $beto->id]);
});

it('lanza BadRequestError al crear un producto con un nombre ya tomado', function (string $enviado) {
    Product::factory()->create(['name' => 'BROCOLI']);

    expect(fn () => productService()->create(productServiceAdmin(), ['name' => $enviado]))
        ->toThrow(BadRequestError::class, 'Ya existe un producto con ese nombre');

    expect(Product::query()->count())->toBe(1);
})->with([
    'idéntico' => ['BROCOLI'],
    'en minúsculas' => ['brocoli'],
    'con espacios de sobra' => ['  brocoli  '],
]);

/*
|--------------------------------------------------------------------------
| update()
|--------------------------------------------------------------------------
*/

it('actualiza el nombre normalizándolo y sin tocar el estado', function () {
    $product = Product::factory()->active()->create(['name' => 'BROCOLI']);

    $updated = productService()->update($product->id, ['name' => '  fresa   silvestre ']);

    expect($updated->name)->toBe('FRESA SILVESTRE')
        ->and($updated->status)->toBeTrue();

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'FRESA SILVESTRE', 'status' => true]);
});

it('actualiza el estado sin tocar el nombre', function () {
    $product = Product::factory()->active()->create(['name' => 'BROCOLI']);

    $updated = productService()->update($product->id, ['status' => false]);

    expect($updated->name)->toBe('BROCOLI')
        ->and($updated->status)->toBeFalse();

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'BROCOLI', 'status' => false]);
});

it('actualiza nombre y estado a la vez', function () {
    $product = Product::factory()->inactive()->create(['name' => 'BROCOLI']);

    $updated = productService()->update($product->id, ['name' => 'fresa', 'status' => true]);

    expect($updated->name)->toBe('FRESA')
        ->and($updated->status)->toBeTrue();
});

it('acepta reenviar el mismo nombre del propio producto', function (string $enviado) {
    $product = Product::factory()->active()->create(['name' => 'BROCOLI']);

    expect(productService()->update($product->id, ['name' => $enviado])->name)->toBe('BROCOLI');
})->with([
    'idéntico' => ['BROCOLI'],
    'en minúsculas' => ['brocoli'],
    'con espacios de sobra' => ['  brocoli  '],
]);

it('no reescribe registered_by al actualizar', function () {
    $ana = productServiceAdmin();

    $product = Product::factory()->create(['registered_by' => $ana->id]);

    expect(productService()->update($product->id, ['name' => 'fresa'])->registered_by)->toBe($ana->id);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'registered_by' => $ana->id]);
});

it('lanza BadRequestError al actualizar con un nombre que ya usa otro producto', function (string $enviado) {
    Product::factory()->create(['name' => 'ZANAHORIA']);
    $product = Product::factory()->create(['name' => 'BROCOLI']);

    expect(fn () => productService()->update($product->id, ['name' => $enviado]))
        ->toThrow(BadRequestError::class, 'Ya existe un producto con ese nombre');

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'BROCOLI']);
})->with([
    'idéntico' => ['ZANAHORIA'],
    'en minúsculas' => ['zanahoria'],
]);

it('no cambia nada cuando el arreglo del update llega vacío', function () {
    $product = Product::factory()->active()->create(['name' => 'BROCOLI']);

    $updated = productService()->update($product->id, []);

    expect($updated->name)->toBe('BROCOLI')
        ->and($updated->status)->toBeTrue();
});

it('lanza NotFoundError al actualizar un producto inexistente', function () {
    expect(fn () => productService()->update(99999, ['name' => 'fresa']))
        ->toThrow(NotFoundError::class, 'El producto no existe');
});

/*
|--------------------------------------------------------------------------
| toggleStatus()
|--------------------------------------------------------------------------
*/

it('invierte el estado del producto', function (bool $inicial) {
    $product = Product::factory()->{$inicial ? 'active' : 'inactive'}()->create();

    expect(productService()->toggleStatus($product->id)->status)->toBe(! $inicial);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => ! $inicial]);
})->with([
    'activo pasa a inactivo' => [true],
    'inactivo pasa a activo' => [false],
]);

it('devuelve el producto a su estado inicial tras dos toggles', function () {
    $product = Product::factory()->active()->create();

    productService()->toggleStatus($product->id);

    expect(productService()->toggleStatus($product->id)->status)->toBeTrue()
        ->and($product->fresh()->status)->toBeTrue();
});

it('no toca el nombre al invertir el estado', function () {
    $product = Product::factory()->active()->create(['name' => 'BROCOLI']);

    expect(productService()->toggleStatus($product->id)->name)->toBe('BROCOLI');
});

it('lanza NotFoundError al invertir el estado de un producto inexistente', function () {
    expect(fn () => productService()->toggleStatus(99999))
        ->toThrow(NotFoundError::class, 'El producto no existe');
});

/*
|--------------------------------------------------------------------------
| destroy()
|--------------------------------------------------------------------------
*/

it('da de baja lógicamente el producto y deja la fila viva', function () {
    $product = Product::factory()->active()->create(['name' => 'BROCOLI']);

    $deleted = productService()->destroy($product->id);

    expect($deleted)->toBeInstanceOf(Product::class)
        ->and($deleted->id)->toBe($product->id)
        ->and($deleted->status)->toBeFalse()
        ->and($deleted->exists)->toBeTrue();

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'BROCOLI', 'status' => false]);
    expect(Product::query()->count())->toBe(1);
});

it('es idempotente al repetir la baja del mismo producto', function () {
    $product = Product::factory()->active()->create();

    productService()->destroy($product->id);

    expect(productService()->destroy($product->id)->status)->toBeFalse()
        ->and(Product::query()->count())->toBe(1);
});

it('no falla al dar de baja un producto que ya estaba inactivo', function () {
    $product = Product::factory()->inactive()->create();

    expect(productService()->destroy($product->id)->status)->toBeFalse();

    $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => false]);
});

it('sigue devolviendo en el listado el producto dado de baja', function () {
    $product = Product::factory()->active()->create();

    productService()->destroy($product->id);

    expect(productService()->getProducts([])->pluck('id')->all())->toBe([$product->id]);
});

it('permite reactivar con toggleStatus un producto dado de baja', function () {
    $product = Product::factory()->active()->create();

    productService()->destroy($product->id);

    expect(productService()->toggleStatus($product->id)->status)->toBeTrue();
});

it('lanza NotFoundError al dar de baja un producto inexistente', function () {
    expect(fn () => productService()->destroy(99999))
        ->toThrow(NotFoundError::class, 'El producto no existe');
});
