<?php

use App\Enums\FuelType;
use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\FreightRate\FreightRateServiceInterface;
use App\Interfaces\Zone\ZoneServiceInterface;
use App\Models\FreightRate;
use App\Models\Product;
use App\Models\User;
use App\Models\Zone;
use App\Services\FreightRate\FreightRateService;
use Illuminate\Database\Eloquent\Collection;

function freightRateService(): FreightRateServiceInterface
{
    return new FreightRateService(app(ZoneServiceInterface::class));
}

function freightRateAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * El cuerpo de un alta, con la zona y el producto ya creados.
 *
 * @return array{zoneId: int, productId: int, fuelType: string, fuelMin: float, pricePerPound: float}
 */
function freightRatePayload(Zone $zone, Product $product, array $overrides = []): array
{
    return array_merge([
        'zoneId' => $zone->id,
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
        freightRatePayload(Zone::factory()->active()->create(), Product::factory()->active()->create()),
    );

    expect($rate->price_per_pound)->toBe('0.454120')
        ->and($rate->fuel_min)->toBe('30.00')
        ->and($rate->fuel_type)->toBe(FuelType::Diesel);
});

it('registra al usuario autenticado como responsable del alta', function () {
    $admin = freightRateAdmin();

    $rate = freightRateService()->create(
        $admin,
        freightRatePayload(Zone::factory()->active()->create(), Product::factory()->active()->create()),
    );

    expect($rate->registered_by)->toBe($admin->id);
});

it('rechaza una segunda tarifa con la misma banda del mismo par', function () {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $admin = freightRateAdmin();

    freightRateService()->create($admin, freightRatePayload($zone, $product));

    freightRateService()->create($admin, freightRatePayload($zone, $product, ['pricePerPound' => 0.9]));
})->throws(BadRequestError::class, 'Ya existe una tarifa para esa zona, ese producto y ese combustible desde ese precio');

it('acepta la misma banda para otro combustible, otra zona u otro producto', function (string $key, string $factory) {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $admin = freightRateAdmin();

    freightRateService()->create($admin, freightRatePayload($zone, $product));

    $value = match ($factory) {
        'zone' => Zone::factory()->active()->create()->id,
        'product' => Product::factory()->active()->create()->id,
        default => FuelType::Regular->value,
    };

    $rate = freightRateService()->create($admin, freightRatePayload($zone, $product, [$key => $value]));

    expect($rate->fuel_min)->toBe('30.00');
})->with([
    'otro combustible' => ['fuelType', 'fuel'],
    'otra zona' => ['zoneId', 'zone'],
    'otro producto' => ['productId', 'product'],
]);

it('libera la banda al borrar la tarifa y permite volver a cotizarla', function () {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $admin = freightRateAdmin();

    $first = freightRateService()->create($admin, freightRatePayload($zone, $product));

    freightRateService()->destroy($first->id);

    $second = freightRateService()->create($admin, freightRatePayload($zone, $product));

    expect($second->id)->not->toBe($first->id)
        ->and($second->fuel_min)->toBe('30.00');
});

it('rechaza el alta cuando la zona o el producto están inactivos', function (bool $zoneActive, string $message) {
    $zone = Zone::factory()->state(['status' => $zoneActive])->create();
    $product = Product::factory()->state(['status' => ! $zoneActive])->create();

    freightRateService()->create(freightRateAdmin(), freightRatePayload($zone, $product));
})->with([
    'zona inactiva' => [false, 'La zona seleccionada no está activa'],
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
        ->and($updated->zone_id)->toBe($rate->zone_id)
        ->and($updated->product_id)->toBe($rate->product_id)
        ->and($updated->fuel_type)->toBe($rate->fuel_type);
});

it('rechaza mover la banda a un fuel_min ya ocupado del mismo par y acepta uno libre', function () {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();
    $admin = freightRateAdmin();

    freightRateService()->create($admin, freightRatePayload($zone, $product, ['fuelMin' => 28.00]));
    $rate = freightRateService()->create($admin, freightRatePayload($zone, $product, ['fuelMin' => 35.00]));

    expect(fn () => freightRateService()->update($rate->id, ['fuelMin' => 28.00]))
        ->toThrow(BadRequestError::class)
        ->and(freightRateService()->update($rate->id, ['fuelMin' => 40.00])->fuel_min)->toBe('40.00');
});

it('acepta reenviar su propia banda sin chocar consigo misma', function () {
    $rate = FreightRate::factory()->create(['fuel_min' => 30]);

    expect(freightRateService()->update($rate->id, ['fuelMin' => 30.00])->fuel_min)->toBe('30.00');
});

it('rechaza la edición cuando la zona o el producto se desactivaron después', function () {
    $zone = Zone::factory()->active()->create();
    $rate = FreightRate::factory()->create(['zone_id' => $zone->id]);

    $zone->update(['status' => false]);

    freightRateService()->update($rate->id, ['pricePerPound' => 0.5]);
})->throws(BadRequestError::class, 'La zona seleccionada no está activa');

it('acepta un body vacío como no-op', function () {
    $rate = FreightRate::factory()->create();

    $updated = freightRateService()->update($rate->id, []);

    expect($updated->fuel_min)->toBe($rate->fuel_min)
        ->and($updated->price_per_pound)->toBe($rate->price_per_pound)
        ->and($updated->zone_id)->toBe($rate->zone_id);
});

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

it('borra una tarifa aunque su zona o su producto estén inactivos', function () {
    $zone = Zone::factory()->inactive()->create();
    $rate = FreightRate::factory()->create(['zone_id' => $zone->id]);

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

it('filtra por zona e ignora un zoneId no numérico', function () {
    $zone = Zone::factory()->active()->create();
    FreightRate::factory()->count(2)->create(['zone_id' => $zone->id]);
    FreightRate::factory()->create();

    expect(freightRateService()->getFreightRates(['zoneId' => (string) $zone->id]))->toHaveCount(2)
        ->and(freightRateService()->getFreightRates(['zoneId' => 'norte']))->toHaveCount(3)
        ->and(freightRateService()->getFreightRates(['zoneId' => '9999']))->toHaveCount(0);
});

it('ordena por tipo de combustible y, dentro de cada tipo, por banda ascendente', function () {
    $zone = Zone::factory()->active()->create();
    $product = Product::factory()->active()->create();

    FreightRate::factory()->create(['zone_id' => $zone->id, 'product_id' => $product->id, 'fuel_type' => FuelType::Regular, 'fuel_min' => 20]);
    FreightRate::factory()->create(['zone_id' => $zone->id, 'product_id' => $product->id, 'fuel_type' => FuelType::Diesel, 'fuel_min' => 35]);
    FreightRate::factory()->create(['zone_id' => $zone->id, 'product_id' => $product->id, 'fuel_type' => FuelType::Diesel, 'fuel_min' => 28]);

    $rates = freightRateService()->getFreightRates([]);

    expect($rates->pluck('fuel_min')->all())->toBe(['28.00', '35.00', '20.00']);
});

it('carga zona, producto y responsable con el listado, sin N+1', function () {
    FreightRate::factory()->count(3)->create();

    $rates = freightRateService()->getFreightRates([]);

    expect($rates->every(fn (FreightRate $rate) => $rate->relationLoaded('zone')
        && $rate->relationLoaded('product')
        && $rate->relationLoaded('registeredBy')))->toBeTrue();
});
