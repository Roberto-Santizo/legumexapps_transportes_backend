<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\TripFinishedProduct\TripFinishedProductServiceInterface;
use App\Models\Carrier;
use App\Models\FinishedProduct;
use App\Models\Trip;
use App\Models\TripFinishedProduct;
use App\Models\User;
use App\Services\TripFinishedProduct\TripFinishedProductService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolve the service through the container, which checks the Provider binding too.
 */
function tripFinishedProductService(): TripFinishedProductServiceInterface
{
    return app(TripFinishedProductServiceInterface::class);
}

function tripFinishedProductServiceUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

/**
 * A line of the given trip, with a fresh SKU of its client.
 */
function tripFinishedProductServiceLine(Trip $trip, int $boxes = 100): TripFinishedProduct
{
    return TripFinishedProduct::factory()->create([
        'trip_id' => $trip->id,
        'finished_product_id' => FinishedProduct::factory()->create(['client_id' => $trip->client_id])->id,
        'boxes' => $boxes,
    ]);
}

it('resuelve la implementación registrada en el provider', function () {
    expect(tripFinishedProductService())->toBeInstanceOf(TripFinishedProductService::class);
});

it('declara los cuatro métodos del contrato', function () {
    expect(get_class_methods(TripFinishedProductServiceInterface::class))->toEqualCanonicalizing([
        'getTripFinishedProducts', 'createTripFinishedProduct', 'updateTripFinishedProduct', 'deleteTripFinishedProduct',
    ]);
});

it('la factory crea el viaje y el producto con el mismo cliente', function () {
    $line = TripFinishedProduct::factory()->create();

    expect($line->finishedProduct->client_id)->toBe($line->trip->client_id)
        ->and($line->boxes)->toBeInt();
});

it('lista las líneas de un viaje como Collection en orden id ASC', function () {
    $trip = Trip::factory()->create();
    $first = tripFinishedProductServiceLine($trip);
    $second = tripFinishedProductServiceLine($trip);
    tripFinishedProductServiceLine(Trip::factory()->create());

    $lines = tripFinishedProductService()->getTripFinishedProducts(tripFinishedProductServiceUser(UserRole::Manager), $trip->id);

    expect($lines)->toBeInstanceOf(Collection::class)
        ->and($lines->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($lines->first()->relationLoaded('finishedProduct'))->toBeTrue()
        ->and($lines->first()->relationLoaded('registeredBy'))->toBeTrue();
});

it('hereda el ámbito del viaje al listar', function () {
    $trip = Trip::factory()->assigned()->create();

    expect(fn () => tripFinishedProductService()->getTripFinishedProducts(Carrier::factory()->create()->owner, $trip->id))
        ->toThrow(ForbiddenError::class);

    expect(fn () => tripFinishedProductService()->getTripFinishedProducts(tripFinishedProductServiceUser(UserRole::Manager), 999999))
        ->toThrow(NotFoundError::class, 'El viaje no existe');

    expect(tripFinishedProductService()->getTripFinishedProducts(User::findOrFail($trip->pilot_id), $trip->id))->toHaveCount(0);
});

it('crea la línea con el autor recibido', function () {
    $trip = Trip::factory()->create();
    $sku = FinishedProduct::factory()->create(['client_id' => $trip->client_id]);
    $export = tripFinishedProductServiceUser(UserRole::Export);

    $line = tripFinishedProductService()->createTripFinishedProduct([
        'tripId' => $trip->id,
        'finishedProductId' => $sku->id,
        'boxes' => '42',
    ], $export);

    expect($line->boxes)->toBe(42)
        ->and($line->registered_by)->toBe($export->id)
        ->and($line->relationLoaded('finishedProduct'))->toBeTrue();
});

it('lanza NotFoundError al crear sobre un viaje o un producto inexistente', function () {
    $trip = Trip::factory()->create();
    $admin = tripFinishedProductServiceUser(UserRole::Administrator);

    expect(fn () => tripFinishedProductService()->createTripFinishedProduct(['tripId' => 999999, 'finishedProductId' => 1, 'boxes' => 1], $admin))
        ->toThrow(NotFoundError::class, 'El viaje no existe');

    expect(fn () => tripFinishedProductService()->createTripFinishedProduct(['tripId' => $trip->id, 'finishedProductId' => 999999, 'boxes' => 1], $admin))
        ->toThrow(NotFoundError::class, 'El producto terminado no existe');
});

it('aplica las guardas del alta en orden', function (Closure $scene, string $message) {
    [$tripId, $skuId] = $scene();

    expect(fn () => tripFinishedProductService()->createTripFinishedProduct([
        'tripId' => $tripId,
        'finishedProductId' => $skuId,
        'boxes' => 1,
    ], tripFinishedProductServiceUser(UserRole::Administrator)))->toThrow(BadRequestError::class, $message);
})->with([
    'viaje borrado' => [fn () => [Trip::factory()->finished()->trashed()->create()->id, FinishedProduct::factory()->trashed()->create()->id], 'El viaje ya fue eliminado'],
    'viaje en ruta' => [fn () => [Trip::factory()->inRoute()->create()->id, FinishedProduct::factory()->trashed()->create()->id], 'Solo se pueden modificar los productos de un viaje pendiente'],
    'producto borrado' => [fn () => [Trip::factory()->create()->id, FinishedProduct::factory()->trashed()->create()->id], 'El producto terminado seleccionado ya fue eliminado'],
    'otro cliente' => [fn () => [Trip::factory()->create()->id, FinishedProduct::factory()->create()->id], 'El producto terminado no pertenece al cliente del viaje'],
    'repetido' => [function () {
        $line = TripFinishedProduct::factory()->create();

        return [$line->trip_id, $line->finished_product_id];
    }, 'El producto terminado ya está en el viaje'],
]);

it('actualiza solo las cajas', function () {
    $trip = Trip::factory()->create();
    $line = tripFinishedProductServiceLine($trip, 10);

    $updated = tripFinishedProductService()->updateTripFinishedProduct($line->id, [
        'boxes' => 77,
        'tripId' => Trip::factory()->create()->id,
        'finishedProductId' => FinishedProduct::factory()->create()->id,
    ]);

    expect($updated->boxes)->toBe(77)
        ->and($updated->trip_id)->toBe($trip->id)
        ->and($updated->finished_product_id)->toBe($line->finished_product_id)
        ->and($updated->registered_by)->toBe($line->registered_by);
});

it('lanza NotFoundError al editar o borrar una línea inexistente', function (string $method) {
    $args = $method === 'updateTripFinishedProduct' ? [999999, ['boxes' => 1]] : [999999];

    expect(fn () => tripFinishedProductService()->{$method}(...$args))
        ->toThrow(NotFoundError::class, 'La línea de producto no existe');
})->with(['updateTripFinishedProduct', 'deleteTripFinishedProduct']);

it('rechaza editar y borrar líneas de un viaje que no está pendiente', function () {
    $trip = Trip::factory()->finished()->create();
    $line = tripFinishedProductServiceLine($trip);
    tripFinishedProductServiceLine($trip);

    expect(fn () => tripFinishedProductService()->updateTripFinishedProduct($line->id, ['boxes' => 1]))
        ->toThrow(BadRequestError::class, 'Solo se pueden modificar los productos de un viaje pendiente');

    expect(fn () => tripFinishedProductService()->deleteTripFinishedProduct($line->id))
        ->toThrow(BadRequestError::class, 'Solo se pueden modificar los productos de un viaje pendiente');
});

it('no deja borrar la última línea y borra físicamente cuando quedan más', function () {
    $trip = Trip::factory()->create();
    $line = tripFinishedProductServiceLine($trip);

    expect(fn () => tripFinishedProductService()->deleteTripFinishedProduct($line->id))
        ->toThrow(BadRequestError::class, 'El viaje debe tener al menos un producto terminado');

    $other = tripFinishedProductServiceLine($trip);

    $deleted = tripFinishedProductService()->deleteTripFinishedProduct($other->id);

    expect($deleted->id)->toBe($other->id)
        ->and(TripFinishedProduct::find($other->id))->toBeNull()
        ->and(TripFinishedProduct::find($line->id))->not->toBeNull();
});
