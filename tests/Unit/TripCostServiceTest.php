<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\TripCost\TripCostServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
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
