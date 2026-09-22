<?php

use App\Enums\FuelType;
use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\TripCost\TripCostServiceInterface;
use App\Models\Carrier;
use App\Models\FuelPrice;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\TripFuel;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripCost\TripCostService;

/**
 * Resolve the service through the container, which checks the Provider binding too.
 */
function tripCostService(): TripCostServiceInterface
{
    return app(TripCostServiceInterface::class);
}

function tripCostUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

/**
 * A trip in the given state, with its assigned pilot and the owner that took it.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{trip: Trip, pilot: User, owner: User}
 */
function tripCostScene(string $state = 'finished', array $attributes = []): array
{
    $trip = Trip::factory()->{$state}()->create($attributes);

    return [
        'trip' => $trip,
        'pilot' => User::findOrFail($trip->pilot_id),
        'owner' => User::findOrFail($trip->assigned_by),
    ];
}

/**
 * The owner of a company that has nothing to do with the trip under test.
 */
function tripCostStranger(): User
{
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    return $carrier->owner;
}

/*
|--------------------------------------------------------------------------
| Binding
|--------------------------------------------------------------------------
*/

it('resuelve el contrato del dominio contra su implementación', function () {
    expect(tripCostService())->toBeInstanceOf(TripCostService::class);
});

it('reparte el mes sobre 720 horas', function () {
    expect(TripCostService::MONTHLY_HOURS)->toBe(720);
});

/*
|--------------------------------------------------------------------------
| Guardas
|--------------------------------------------------------------------------
*/

it('lanza 404 cuando el viaje no existe', function () {
    tripCostService()->getTripCost(tripCostUser(UserRole::Administrator), 9999);
})->throws(NotFoundError::class, 'El viaje no existe');

it('lanza 404 cuando el viaje fue borrado, porque es una ruta de lectura', function () {
    $trip = Trip::factory()->finished()->trashed()->create();

    tripCostService()->getTripCost(tripCostUser(UserRole::Administrator), $trip->id);
})->throws(NotFoundError::class, 'El viaje no existe');

it('da 404 y no 403 cuando el viaje está borrado y además es de otra empresa', function () {
    $trip = Trip::factory()->finished()->trashed()->create();

    expect(fn () => tripCostService()->getTripCost(tripCostStranger(), $trip->id))
        ->toThrow(NotFoundError::class, 'El viaje no existe');
});

it('lanza 403 a cualquier piloto, incluido el asignado al viaje', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripCostScene();

    expect(fn () => tripCostService()->getTripCost($pilot, $trip->id))
        ->toThrow(ForbiddenError::class, 'No tienes permisos para consultar el costo de un viaje');
});

it('lanza 403 al transportista que no tomó el viaje', function () {
    ['trip' => $trip] = tripCostScene();

    expect(fn () => tripCostService()->getTripCost(tripCostStranger(), $trip->id))
        ->toThrow(ForbiddenError::class);
});

it('lanza 400 cuando el viaje todavía no ha terminado', function (string $state) {
    ['trip' => $trip, 'owner' => $owner] = tripCostScene($state);

    expect(fn () => tripCostService()->getTripCost($owner, $trip->id))
        ->toThrow(BadRequestError::class, 'El costo solo está disponible para viajes finalizados');
})->with(['assigned', 'inRoute']);

it('responde 403 y no 400 sobre un viaje en curso de otra empresa: el orden es 404, 403, 400', function () {
    ['trip' => $trip] = tripCostScene('inRoute');

    expect(fn () => tripCostService()->getTripCost(tripCostStranger(), $trip->id))
        ->toThrow(ForbiddenError::class);
});

it('deja pasar al administrador, al manager y al transportista que tomó el viaje', function () {
    ['trip' => $trip, 'owner' => $owner] = tripCostScene();

    foreach ([tripCostUser(UserRole::Administrator), tripCostUser(UserRole::Manager), $owner] as $user) {
        $cost = tripCostService()->getTripCost($user, $trip->id);

        expect($cost['trip']->id)->toBe($trip->id);
    }
});

/*
|--------------------------------------------------------------------------
| Forma de la salida
|--------------------------------------------------------------------------
*/

it('devuelve los cuatro bloques y el total', function () {
    ['trip' => $trip, 'owner' => $owner] = tripCostScene();

    $cost = tripCostService()->getTripCost($owner, $trip->id);

    expect(array_keys($cost))
        ->toBe(['trip', 'traveledHours', 'fuel', 'expenses', 'pilot', 'vehicle', 'totalCost'])
        ->and(array_keys($cost['fuel']))->toBe(['gallons', 'byType', 'subtotal'])
        ->and(array_keys($cost['expenses']))->toBe(['count', 'subtotal'])
        ->and(array_keys($cost['pilot']))->toBe(['monthlySalary', 'subtotal'])
        ->and(array_keys($cost['vehicle']))->toBe(['monthlyInsuranceCost', 'subtotal']);
});

it('lee traveledHours de la columna y lo deja en null en un viaje cerrado antes de SPEC 32', function () {
    ['trip' => $trip, 'owner' => $owner] = tripCostScene('finished', ['traveled_hours' => null]);

    expect(tripCostService()->getTripCost($owner, $trip->id)['traveledHours'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Combustible
|--------------------------------------------------------------------------
*/

it('cotiza una carga confirmada al precio vigente en su loaded_at', function () {
    ['trip' => $trip, 'owner' => $owner] = tripCostScene();

    tripCostPrice(FuelType::Diesel, 38.50, '2026-01-01 08:00:00');
    tripCostLoad($trip, 35, FuelType::Diesel, '2026-02-10 09:00:00');

    $fuel = tripCostService()->getTripCost($owner, $trip->id)['fuel'];

    expect($fuel['gallons'])->toBe(35.0)
        ->and($fuel['byType'])->toHaveCount(1)
        ->and($fuel['byType'][0])->toBe([
            'fuelType' => 'diesel',
            'gallons' => 35.0,
            'pricePerGallon' => 38.50,
            'amount' => 1347.50,
        ])
        ->and($fuel['subtotal'])->toBe(1347.50);
});

it('cotiza dos cargas del mismo tipo a precios distintos y las suma en un solo elemento', function () {
    ['trip' => $trip, 'owner' => $owner] = tripCostScene();

    tripCostPrice(FuelType::Diesel, 30.00, '2026-01-01 08:00:00');
    tripCostPrice(FuelType::Diesel, 40.00, '2026-02-01 08:00:00');

    tripCostLoad($trip, 10, FuelType::Diesel, '2026-01-15 09:00:00');
    tripCostLoad($trip, 10, FuelType::Diesel, '2026-02-15 09:00:00');

    $fuel = tripCostService()->getTripCost($owner, $trip->id)['fuel'];

    /** 10 × 30 + 10 × 40 = 700, un solo bloque de 20 galones. */
    expect($fuel['byType'])->toHaveCount(1)
        ->and($fuel['byType'][0]['gallons'])->toBe(20.0)
        ->and($fuel['byType'][0]['amount'])->toBe(700.0)
        ->and($fuel['subtotal'])->toBe(700.0);
});

it('usa el precio vigente en esa fecha aunque hoy esté inactivo', function () {
    ['trip' => $trip, 'owner' => $owner] = tripCostScene();

    FuelPrice::factory()->inactive()->create([
        'fuel_type' => FuelType::Regular,
        'price' => 25.00,
        'created_at' => '2026-01-01 08:00:00',
    ]);
    tripCostPrice(FuelType::Regular, 99.00, '2026-03-01 08:00:00');

    tripCostLoad($trip, 4, FuelType::Regular, '2026-01-20 09:00:00');

    $fuel = tripCostService()->getTripCost($owner, $trip->id)['fuel'];

    expect($fuel['byType'][0]['pricePerGallon'])->toBe(25.00)
        ->and($fuel['subtotal'])->toBe(100.0);
});

it('ignora las cargas sin confirmar', function () {
    ['trip' => $trip, 'owner' => $owner] = tripCostScene();

    tripCostPrice(FuelType::Diesel, 38.50, '2026-01-01 08:00:00');
    TripFuel::factory()->create([
        'trip_id' => $trip->id,
        'gallons' => 50,
        'fuel_type' => FuelType::Diesel,
    ]);

    $fuel = tripCostService()->getTripCost($owner, $trip->id)['fuel'];

    expect($fuel['gallons'])->toBe(0.0)
        ->and($fuel['byType'])->toBe([])
        ->and($fuel['subtotal'])->toBe(0.0);
});

it('devuelve el bloque en cero cuando el viaje no tiene cargas', function () {
    ['trip' => $trip, 'owner' => $owner] = tripCostScene();

    $fuel = tripCostService()->getTripCost($owner, $trip->id)['fuel'];

    expect($fuel)->toBe(['gallons' => 0.0, 'byType' => [], 'subtotal' => 0.0]);
});

it('deja el precio en null cuando la carga es anterior a cualquier precio capturado de su tipo', function () {
    ['trip' => $trip, 'owner' => $owner] = tripCostScene();

    tripCostPrice(FuelType::Diesel, 38.50, '2026-05-01 08:00:00');
    tripCostLoad($trip, 12, FuelType::Diesel, '2026-01-10 09:00:00');

    $fuel = tripCostService()->getTripCost($owner, $trip->id)['fuel'];

    /** Los galones sí suman: un bloque con galones y sin importe es la señal visible. */
    expect($fuel['byType'][0]['pricePerGallon'])->toBeNull()
        ->and($fuel['byType'][0]['gallons'])->toBe(12.0)
        ->and($fuel['byType'][0]['amount'])->toBe(0.0)
        ->and($fuel['subtotal'])->toBe(0.0);
});

it('no mezcla los precios de dos tipos de combustible distintos', function () {
    ['trip' => $trip, 'owner' => $owner] = tripCostScene();

    tripCostPrice(FuelType::Diesel, 38.50, '2026-01-01 08:00:00');
    tripCostPrice(FuelType::Regular, 30.00, '2026-01-01 08:00:00');

    tripCostLoad($trip, 10, FuelType::Diesel, '2026-02-01 09:00:00');
    tripCostLoad($trip, 10, FuelType::Regular, '2026-02-01 09:00:00');

    $fuel = tripCostService()->getTripCost($owner, $trip->id)['fuel'];

    expect($fuel['byType'])->toHaveCount(2)
        ->and(collect($fuel['byType'])->pluck('amount', 'fuelType')->all())
        ->toBe(['diesel' => 385.0, 'regular' => 300.0])
        ->and($fuel['subtotal'])->toBe(685.0);
});

/**
 * Capture a fuel price for the given type at a known moment.
 */
function tripCostPrice(FuelType $type, float $price, string $capturedAt): FuelPrice
{
    return FuelPrice::factory()->create([
        'fuel_type' => $type,
        'price' => $price,
        'created_at' => $capturedAt,
    ]);
}

/**
 * Register one already confirmed fuel load on the trip, loaded at a known moment.
 */
function tripCostLoad(Trip $trip, float $gallons, FuelType $type, string $loadedAt): TripFuel
{
    return TripFuel::factory()->create([
        'trip_id' => $trip->id,
        'gallons' => $gallons,
        'fuel_type' => $type,
        'loaded_at' => $loadedAt,
        'confirmed_by' => $trip->pilot_id,
    ]);
}

/*
|--------------------------------------------------------------------------
| Viáticos
|--------------------------------------------------------------------------
*/

it('suma y cuenta solo los viáticos confirmados', function () {
    ['trip' => $trip, 'owner' => $owner] = tripCostScene();

    TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 300]);
    TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 150]);
    TripExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 999]);

    expect(tripCostService()->getTripCost($owner, $trip->id)['expenses'])
        ->toBe(['count' => 2, 'subtotal' => 450.0]);
});

it('devuelve el bloque de viáticos en cero cuando no hay ninguno confirmado', function () {
    ['trip' => $trip, 'owner' => $owner] = tripCostScene();

    expect(tripCostService()->getTripCost($owner, $trip->id)['expenses'])
        ->toBe(['count' => 0, 'subtotal' => 0.0]);
});

it('coincide con el totalExpensesAmount que pinta TripResource', function () {
    ['trip' => $trip, 'owner' => $owner] = tripCostScene();

    TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 725.50]);
    TripExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 100]);

    $cost = tripCostService()->getTripCost($owner, $trip->id);

    expect($cost['expenses']['subtotal'])->toBe((float) $cost['trip']->total_expenses_amount);
});
