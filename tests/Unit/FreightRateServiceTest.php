<?php

use App\Enums\FuelPriceStatus;
use App\Enums\FuelType;
use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\FreightRate\FreightRateServiceInterface;
use App\Models\FreightRate;
use App\Models\FuelPrice;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Services\FreightRate\FreightRateService;
use Illuminate\Database\Eloquent\Collection;

function freightRateService(): FreightRateServiceInterface
{
    return app(FreightRateServiceInterface::class);
}

/** Tras el cambio de eje el service no tiene constructor: no colabora ya con ningún otro dominio. */
it('resuelve la implementación de tarifas registrada en el provider', function () {
    expect(freightRateService())->toBeInstanceOf(FreightRateService::class);
});

function freightRateAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * El cuerpo de un alta, con el destino y el producto ya creados.
 *
 * @return array{locationId: int, productId: int, fuelType: string, fuelMin: float, pricePerPound: float}
 */
function freightRatePayload(Location $location, Product $product, array $overrides = []): array
{
    return array_merge([
        'locationId' => $location->id,
        'productId' => $product->id,
        'fuelType' => FuelType::Diesel->value,
        'fuelMin' => 30.00,
        'pricePerPound' => 0.454120,
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('registra una tarifa conservando los seis decimales del precio por libra', function () {
    $rate = freightRateService()->create(
        freightRateAdmin(),
        freightRatePayload(Location::factory()->active()->create(), Product::factory()->active()->create()),
    );

    expect($rate->price_per_pound)->toBe('0.454120')
        ->and($rate->fuel_min)->toBe('30.00')
        ->and($rate->fuel_type)->toBe(FuelType::Diesel);
});

it('registra al usuario autenticado como responsable del alta', function () {
    $admin = freightRateAdmin();

    $rate = freightRateService()->create(
        $admin,
        freightRatePayload(Location::factory()->active()->create(), Product::factory()->active()->create()),
    );

    expect($rate->registered_by)->toBe($admin->id);
});

it('rechaza una segunda tarifa con la misma banda del mismo par', function () {
    $location = Location::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $admin = freightRateAdmin();

    freightRateService()->create($admin, freightRatePayload($location, $product));

    freightRateService()->create($admin, freightRatePayload($location, $product, ['pricePerPound' => 0.9]));
})->throws(BadRequestError::class, 'Ya existe una tarifa para ese destino, ese producto y ese combustible desde ese precio');

it('acepta la misma banda para otro combustible, otro destino u otro producto', function (string $key, string $factory) {
    $location = Location::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $admin = freightRateAdmin();

    freightRateService()->create($admin, freightRatePayload($location, $product));

    $value = match ($factory) {
        'location' => Location::factory()->active()->create()->id,
        'product' => Product::factory()->active()->create()->id,
        default => FuelType::Regular->value,
    };

    $rate = freightRateService()->create($admin, freightRatePayload($location, $product, [$key => $value]));

    expect($rate->fuel_min)->toBe('30.00');
})->with([
    'otro combustible' => ['fuelType', 'fuel'],
    'otro destino' => ['locationId', 'location'],
    'otro producto' => ['productId', 'product'],
]);

it('libera la banda al borrar la tarifa y permite volver a cotizarla', function () {
    $location = Location::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $admin = freightRateAdmin();

    $first = freightRateService()->create($admin, freightRatePayload($location, $product));

    freightRateService()->destroy($first->id);

    $second = freightRateService()->create($admin, freightRatePayload($location, $product));

    expect($second->id)->not->toBe($first->id)
        ->and($second->fuel_min)->toBe('30.00');
});

it('rechaza el alta cuando el destino o el producto están inactivos', function (bool $locationActive, string $message) {
    $location = Location::factory()->state(['status' => $locationActive])->create();
    $product = Product::factory()->state(['status' => ! $locationActive])->create();

    freightRateService()->create(freightRateAdmin(), freightRatePayload($location, $product));
})->with([
    'destino inactivo' => [false, 'El destino seleccionado no está activo'],
    'producto inactivo' => [true, 'El producto seleccionado no está activo'],
])->throws(BadRequestError::class);

/*
|--------------------------------------------------------------------------
| Guarda compartida por id
|--------------------------------------------------------------------------
*/

it('lanza NotFoundError sobre un id que nunca existió', function (string $method) {
    freightRateService()->{$method}(9999);
})->with(['getFreightRateById', 'destroy'])->throws(NotFoundError::class, 'La tarifa no existe');

it('devuelve la fila con su destino, su producto y su responsable ya cargados', function () {
    $rate = FreightRate::factory()->create();

    $found = freightRateService()->getFreightRateById($rate->id);

    expect($found->relationLoaded('location'))->toBeTrue()
        ->and($found->relationLoaded('product'))->toBeTrue()
        ->and($found->relationLoaded('registeredBy'))->toBeTrue();
});

it('lanza BadRequestError sobre una tarifa ya borrada', function () {
    $rate = FreightRate::factory()->create();

    freightRateService()->destroy($rate->id);

    expect(fn () => freightRateService()->getFreightRateById($rate->id))
        ->toThrow(BadRequestError::class, 'La tarifa ya fue eliminada')
        ->and(fn () => freightRateService()->destroy($rate->id))
        ->toThrow(BadRequestError::class, 'La tarifa ya fue eliminada')
        ->and(fn () => freightRateService()->update($rate->id, []))
        ->toThrow(BadRequestError::class, 'La tarifa ya fue eliminada');
});

/*
|--------------------------------------------------------------------------
| Edición
|--------------------------------------------------------------------------
*/

it('cambia solo el precio sin tocar el resto del par', function () {
    $rate = FreightRate::factory()->create(['fuel_min' => 30, 'price_per_pound' => 0.4]);

    $updated = freightRateService()->update($rate->id, ['pricePerPound' => 0.6]);

    expect($updated->price_per_pound)->toBe('0.600000')
        ->and($updated->fuel_min)->toBe('30.00')
        ->and($updated->location_id)->toBe($rate->location_id)
        ->and($updated->product_id)->toBe($rate->product_id)
        ->and($updated->fuel_type)->toBe($rate->fuel_type);
});

it('rechaza mover la banda a un fuel_min ya ocupado del mismo par y acepta uno libre', function () {
    $location = Location::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $admin = freightRateAdmin();

    freightRateService()->create($admin, freightRatePayload($location, $product, ['fuelMin' => 28.00]));
    $rate = freightRateService()->create($admin, freightRatePayload($location, $product, ['fuelMin' => 35.00]));

    expect(fn () => freightRateService()->update($rate->id, ['fuelMin' => 28.00]))
        ->toThrow(BadRequestError::class)
        ->and(freightRateService()->update($rate->id, ['fuelMin' => 40.00])->fuel_min)->toBe('40.00');
});

it('acepta reenviar su propia banda sin chocar consigo misma', function () {
    $rate = FreightRate::factory()->create(['fuel_min' => 30]);

    expect(freightRateService()->update($rate->id, ['fuelMin' => 30.00])->fuel_min)->toBe('30.00');
});

it('rechaza la edición cuando el destino o el producto se desactivaron después', function () {
    $location = Location::factory()->active()->create();
    $rate = FreightRate::factory()->create(['location_id' => $location->id]);

    $location->update(['status' => false]);

    freightRateService()->update($rate->id, ['pricePerPound' => 0.5]);
})->throws(BadRequestError::class, 'El destino seleccionado no está activo');

it('acepta un body vacío como no-op', function () {
    $rate = FreightRate::factory()->create();

    $updated = freightRateService()->update($rate->id, []);

    expect($updated->fuel_min)->toBe($rate->fuel_min)
        ->and($updated->price_per_pound)->toBe($rate->price_per_pound)
        ->and($updated->location_id)->toBe($rate->location_id);
});

it('mueve la tarifa a otro destino y otro producto activos', function () {
    $rate = FreightRate::factory()->create();
    $location = Location::factory()->active()->create();
    $product = Product::factory()->active()->create();

    $updated = freightRateService()->update($rate->id, ['locationId' => $location->id, 'productId' => $product->id]);

    expect($updated->location_id)->toBe($location->id)
        ->and($updated->product_id)->toBe($product->id)
        ->and($updated->location->name)->toBe($location->name)
        ->and($updated->product->name)->toBe($product->name);
});

it('rechaza mover la tarifa a un destino o un producto inactivos', function (string $key, string $message) {
    $rate = FreightRate::factory()->create();

    $target = $key === 'locationId'
        ? Location::factory()->inactive()->create()->id
        : Product::factory()->inactive()->create()->id;

    freightRateService()->update($rate->id, [$key => $target]);
})->with([
    'destino inactivo' => ['locationId', 'El destino seleccionado no está activo'],
    'producto inactivo' => ['productId', 'El producto seleccionado no está activo'],
])->throws(BadRequestError::class);

it('no reescribe al responsable del alta al editar', function () {
    $rate = FreightRate::factory()->create();

    expect(freightRateService()->update($rate->id, ['pricePerPound' => 0.5])->registered_by)
        ->toBe($rate->registered_by);
});

/*
|--------------------------------------------------------------------------
| Baja
|--------------------------------------------------------------------------
*/

it('borra en lógico dejando la fila en base y fuera del listado', function () {
    $rate = FreightRate::factory()->create();

    freightRateService()->destroy($rate->id);

    expect(FreightRate::withTrashed()->whereKey($rate->id)->first()->deleted_at)->not->toBeNull()
        ->and(freightRateService()->getFreightRates([]))->toHaveCount(0);
});

it('borra una tarifa aunque su destino o su producto estén inactivos', function () {
    $location = Location::factory()->inactive()->create();
    $rate = FreightRate::factory()->create(['location_id' => $location->id]);

    expect(freightRateService()->destroy($rate->id)->trashed())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Listado
|--------------------------------------------------------------------------
*/

it('devuelve la colección completa sin paginar y sin atender a limit', function () {
    FreightRate::factory()->count(3)->create();

    expect(freightRateService()->getFreightRates([]))->toBeInstanceOf(Collection::class)->toHaveCount(3)
        ->and(freightRateService()->getFreightRates(['limit' => '10']))->toHaveCount(3);
});

it('filtra por destino e ignora un locationId no numérico', function () {
    $location = Location::factory()->active()->create();
    FreightRate::factory()->count(2)->create(['location_id' => $location->id]);
    FreightRate::factory()->create();

    expect(freightRateService()->getFreightRates(['locationId' => (string) $location->id]))->toHaveCount(2)
        ->and(freightRateService()->getFreightRates(['locationId' => 'norte']))->toHaveCount(3)
        ->and(freightRateService()->getFreightRates(['locationId' => '9999']))->toHaveCount(0);
});

it('ordena por tipo de combustible y, dentro de cada tipo, por banda ascendente', function () {
    $location = Location::factory()->active()->create();
    $product = Product::factory()->active()->create();

    FreightRate::factory()->create(['location_id' => $location->id, 'product_id' => $product->id, 'fuel_type' => FuelType::Regular, 'fuel_min' => 20]);
    FreightRate::factory()->create(['location_id' => $location->id, 'product_id' => $product->id, 'fuel_type' => FuelType::Diesel, 'fuel_min' => 35]);
    FreightRate::factory()->create(['location_id' => $location->id, 'product_id' => $product->id, 'fuel_type' => FuelType::Diesel, 'fuel_min' => 28]);

    $rates = freightRateService()->getFreightRates([]);

    expect($rates->pluck('fuel_min')->all())->toBe(['28.00', '35.00', '20.00']);
});

it('carga destino, producto y responsable con el listado, sin N+1', function () {
    FreightRate::factory()->count(3)->create();

    $rates = freightRateService()->getFreightRates([]);

    expect($rates->every(fn (FreightRate $rate) => $rate->relationLoaded('location')
        && $rate->relationLoaded('product')
        && $rate->relationLoaded('registeredBy')))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Cotización — selección de banda
|--------------------------------------------------------------------------
*/

/**
 * El escenario completo de una cotización: destino, producto y diésel vigente.
 *
 * @param  array<int, array{0: float, 1: float}>  $bands  pares [fuelMin, pricePerPound].
 * @return array{location: Location, product: Product}
 */
function freightRateScenario(float $currentFuelPrice, array $bands): array
{
    $location = Location::factory()->active()->create();
    $product = Product::factory()->active()->create();

    FuelPrice::factory()->active()->create([
        'fuel_type' => FuelType::Diesel,
        'price' => $currentFuelPrice,
    ]);

    foreach ($bands as [$fuelMin, $pricePerPound]) {
        FreightRate::factory()->create([
            'location_id' => $location->id,
            'product_id' => $product->id,
            'fuel_type' => FuelType::Diesel,
            'fuel_min' => $fuelMin,
            'price_per_pound' => $pricePerPound,
        ]);
    }

    return ['location' => $location, 'product' => $product];
}

/**
 * La cotización del escenario, con el destino mandado por id.
 *
 * @return array{rate: FreightRate, currentFuelPrice: string, pounds: float|null, total: float|null}
 */
function freightRateQuote(Location $location, Product $product, ?float $pounds = null): array
{
    return freightRateService()->quote([
        'locationId' => $location->id,
        'productId' => $product->id,
        'fuelType' => FuelType::Diesel->value,
        'pounds' => $pounds,
    ]);
}

it('elige la banda que rige con el combustible vigente', function (float $currentFuelPrice, string $expected) {
    ['location' => $location, 'product' => $product] = freightRateScenario($currentFuelPrice, [
        [28.00, 0.400000],
        [35.00, 0.454120],
    ]);

    $quote = freightRateQuote($location, $product);

    expect($quote['rate']->price_per_pound)->toBe($expected);
})->with([
    'por encima de todas' => [40.00, '0.454120'],
    'exactamente en el límite' => [35.00, '0.454120'],
    'entre las dos bandas' => [30.00, '0.400000'],
    'por debajo de todas' => [25.00, '0.400000'],
]);

it('aplica la única banda cotizada sea cual sea el precio del combustible', function (float $currentFuelPrice) {
    ['location' => $location, 'product' => $product] = freightRateScenario($currentFuelPrice, [[30.00, 0.454120]]);

    $quote = freightRateQuote($location, $product);

    expect($quote['rate']->fuel_min)->toBe('30.00')
        ->and($quote['currentFuelPrice'])->toBe(number_format($currentFuelPrice, 2, '.', ''));
})->with(['muy por debajo' => [10.00], 'justo debajo' => [29.99], 'muy por encima' => [90.00]]);

it('devuelve la banda aplicada con su destino y su producto resueltos', function () {
    ['location' => $location, 'product' => $product] = freightRateScenario(40.00, [[35.00, 0.454120]]);

    $quote = freightRateQuote($location, $product);

    expect($quote['rate']->location->name)->toBe($location->name)
        ->and($quote['rate']->product->name)->toBe($product->name)
        ->and($quote['currentFuelPrice'])->toBe('40.00');
});

/*
|--------------------------------------------------------------------------
| Cotización — total y fallos
|--------------------------------------------------------------------------
*/

it('multiplica sobre la tarifa completa y redondea solo el total', function () {
    ['location' => $location, 'product' => $product] = freightRateScenario(40.00, [[35.00, 0.454120]]);

    $quote = freightRateQuote($location, $product, 45000.0);

    expect($quote['total'])->toBe(20435.40)
        ->and($quote['pounds'])->toBe(45000.0);
});

it('deja libras y total en null cuando no llegan libras', function () {
    ['location' => $location, 'product' => $product] = freightRateScenario(40.00, [[35.00, 0.454120]]);

    $quote = freightRateQuote($location, $product);

    expect($quote['pounds'])->toBeNull()
        ->and($quote['total'])->toBeNull();
});

it('cotiza con el precio vigente y no con el histórico desplazado del mismo combustible', function () {
    ['location' => $location, 'product' => $product] = freightRateScenario(40.00, [
        [28.00, 0.400000],
        [35.00, 0.454120],
    ]);

    FuelPrice::query()->delete();

    /**
     * El histórico se planta antes que el vigente y por debajo de la banda alta: si se colara
     * —por llegar primero en la consulta—, la cotización bajaría de tarifa sin decirlo.
     */
    FuelPrice::factory()->inactive()->create(['fuel_type' => FuelType::Diesel, 'price' => 30.00]);
    FuelPrice::factory()->active()->create(['fuel_type' => FuelType::Diesel, 'price' => 40.00]);

    $quote = freightRateQuote($location, $product);

    expect($quote['currentFuelPrice'])->toBe('40.00')
        ->and($quote['rate']->fuel_min)->toBe('35.00');
});

it('no persiste nada al cotizar', function () {
    ['location' => $location, 'product' => $product] = freightRateScenario(40.00, [[35.00, 0.454120]]);

    $before = FreightRate::withTrashed()->count();

    freightRateQuote($location, $product, 45000.0);

    expect(FreightRate::withTrashed()->count())->toBe($before);
});

it('lanza BadRequestError cuando el destino está inactivo', function () {
    ['location' => $location, 'product' => $product] = freightRateScenario(40.00, [[35.00, 0.454120]]);

    $location->update(['status' => false]);

    freightRateQuote($location, $product);
})->throws(BadRequestError::class, 'El destino seleccionado no está activo');

it('lanza BadRequestError cuando el producto está inactivo', function () {
    ['location' => $location, 'product' => $product] = freightRateScenario(40.00, [[35.00, 0.454120]]);

    $product->update(['status' => false]);

    freightRateQuote($location, $product);
})->throws(BadRequestError::class, 'El producto seleccionado no está activo');

it('lanza BadRequestError cuando el combustible no tiene precio vigente', function () {
    ['location' => $location, 'product' => $product] = freightRateScenario(40.00, [[35.00, 0.454120]]);

    FuelPrice::query()->update(['status' => FuelPriceStatus::Inactive]);

    freightRateQuote($location, $product);
})->throws(BadRequestError::class, 'No existe un precio vigente para el combustible indicado');

it('lanza BadRequestError cuando el par no tiene ninguna tarifa cotizada', function () {
    ['location' => $location, 'product' => $product] = freightRateScenario(40.00, []);

    freightRateQuote($location, $product);
})->throws(BadRequestError::class, 'No existe tarifa cotizada para ese producto en ese destino');

it('nunca aplica una tarifa borrada', function () {
    ['location' => $location, 'product' => $product] = freightRateScenario(40.00, [[35.00, 0.454120]]);

    freightRateService()->destroy(FreightRate::query()->firstOrFail()->id);

    freightRateQuote($location, $product);
})->throws(BadRequestError::class, 'No existe tarifa cotizada para ese producto en ese destino');

it('ignora la banda borrada y aplica la siguiente que rija', function () {
    ['location' => $location, 'product' => $product] = freightRateScenario(40.00, [
        [28.00, 0.400000],
        [35.00, 0.454120],
    ]);

    freightRateService()->destroy(FreightRate::query()->where('fuel_min', '=', '35.00')->firstOrFail()->id);

    expect(freightRateQuote($location, $product)['rate']->fuel_min)->toBe('28.00');
});

/** La cotización dejó de tener cualquier fallo 404: el id inexistente lo atrapa el 422 del FormRequest. */
it('nunca falla con NotFoundError al cotizar', function () {
    ['location' => $location, 'product' => $product] = freightRateScenario(40.00, []);

    expect(fn () => freightRateQuote($location, $product))->toThrow(BadRequestError::class);
});
