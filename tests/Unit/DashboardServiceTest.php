<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Enums\VehicleExpenseCategory;
use App\Enums\VehicleExpenseNature;
use App\Enums\VehicleStatus;
use App\Errors\ForbiddenError;
use App\Interfaces\Dashboard\DashboardServiceInterface;
use App\Models\Carrier;
use App\Models\Client;
use App\Models\Trip;
use App\Models\TripFuel;
use App\Models\TripPosition;
use App\Models\TripTimeout;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Services\Dashboard\DashboardService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolve the service through the container, which checks the Provider binding too.
 */
function dashboardService(): DashboardServiceInterface
{
    return app(DashboardServiceInterface::class);
}

/**
 * An administrator: no scope, so `carrierId` behaves as the voluntary filter it is.
 */
function dashboardAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * The owner of the given company, the user a `carrier` dashboard is scoped through.
 */
function dashboardOwnerOf(Carrier $carrier): User
{
    return User::query()->findOrFail($carrier->user_id);
}

/**
 * A trip taken by the given company: pilot linked to it, vehicle owned by it and its
 * owner as `assigned_by`.
 *
 * @param  array<string, mixed>  $attributes
 */
function dashboardTripOf(Carrier $carrier, array $attributes = []): Trip
{
    $pilot = User::factory()->create(['role' => UserRole::Pilot]);
    $carrier->pilots()->attach($pilot);

    return Trip::factory()->create(array_merge([
        'pilot_id' => $pilot->id,
        'vehicle_id' => $attributes['vehicle_id'] ?? Vehicle::factory()->create(['carrier_id' => $carrier->id])->id,
        'assigned_by' => $carrier->user_id,
    ], $attributes));
}

/**
 * An expense on a vehicle of the given company.
 *
 * @param  array<string, mixed>  $attributes
 */
function dashboardExpenseOf(Carrier $carrier, array $attributes = []): VehicleExpense
{
    return VehicleExpense::factory()->create(array_merge([
        'vehicle_id' => Vehicle::factory()->create(['carrier_id' => $carrier->id])->id,
    ], $attributes));
}

/*
|--------------------------------------------------------------------------
| Binding
|--------------------------------------------------------------------------
*/

it('resuelve el contrato del dominio contra su implementación', function () {
    expect(dashboardService())->toBeInstanceOf(DashboardService::class);
});

/*
|--------------------------------------------------------------------------
| getTripsSummary()
|--------------------------------------------------------------------------
*/

it('devuelve el resumen de viajes con la forma completa sobre una base vacía', function () {
    expect(dashboardService()->getTripsSummary(dashboardAdmin(), []))->toBe([
        'total' => 0,
        'unassigned' => 0,
        'byStatus' => ['pending' => 0, 'inRoute' => 0, 'finished' => 0],
        'byCarrier' => [],
        'byClient' => [],
        'byShippingLine' => [],
        'byLocation' => [],
        'byMonth' => [],
    ]);
});

it('devuelve lo mismo con un carrierId inexistente o no numérico que sin filtro', function () {
    dashboardTripOf(Carrier::factory()->create());
    Trip::factory()->create();

    $unfiltered = dashboardService()->getTripsSummary(dashboardAdmin(), []);

    expect(dashboardService()->getTripsSummary(dashboardAdmin(), ['carrierId' => '999999']))->toBe($unfiltered)
        ->and(dashboardService()->getTripsSummary(dashboardAdmin(), ['carrierId' => 'abc']))->toBe($unfiltered)
        ->and(dashboardService()->getTripsSummary(dashboardAdmin(), ['carrierId' => '-1']))->toBe($unfiltered)
        ->and($unfiltered['total'])->toBe(2);
});

it('ordena los desgloses por total descendente y desempata por id ascendente', function () {
    $clientA = Client::factory()->create(['name' => 'A']);
    $clientB = Client::factory()->create(['name' => 'B']);
    $clientC = Client::factory()->create(['name' => 'C']);
    Trip::factory()->create(['client_id' => $clientA->id]);
    Trip::factory()->create(['client_id' => $clientB->id]);
    Trip::factory()->count(2)->create(['client_id' => $clientC->id]);

    $carrierSmall = Carrier::factory()->create(['name' => 'CHICA']);
    $carrierBig = Carrier::factory()->create(['name' => 'GRANDE']);
    dashboardTripOf($carrierSmall);
    dashboardTripOf($carrierBig);
    dashboardTripOf($carrierBig);

    $summary = dashboardService()->getTripsSummary(dashboardAdmin(), []);

    expect(array_slice(array_column($summary['byClient'], 'clientId'), 0, 3))->toBe([$clientC->id, $clientA->id, $clientB->id])
        ->and(array_column($summary['byCarrier'], 'carrierId'))->toBe([$carrierBig->id, $carrierSmall->id])
        ->and($summary['total'])->toBe(7)
        ->and($summary['unassigned'])->toBe(4);
});

it('ordena byMonth de forma ascendente y con el formato YYYY-MM', function () {
    Trip::factory()->create(['recolection_date' => '2026-11-03 10:00:00']);
    Trip::factory()->create(['recolection_date' => '2026-02-15 10:00:00']);
    Trip::factory()->create(['recolection_date' => '2026-02-20 10:00:00']);

    expect(dashboardService()->getTripsSummary(dashboardAdmin(), [])['byMonth'])->toBe([
        ['month' => '2026-02', 'total' => 2],
        ['month' => '2026-11', 'total' => 1],
    ]);
});

it('deja unassigned en cero cuando el resumen se acota a una empresa', function () {
    $carrier = Carrier::factory()->create();
    dashboardTripOf($carrier);
    Trip::factory()->count(3)->create();

    $summary = dashboardService()->getTripsSummary(dashboardAdmin(), ['carrierId' => (string) $carrier->id]);

    expect($summary['total'])->toBe(1)
        ->and($summary['unassigned'])->toBe(0);
});

it('ignora una fecha que no sea exactamente Y-m-d', function () {
    Trip::factory()->create(['recolection_date' => '2026-09-05 10:00:00']);
    Trip::factory()->create(['recolection_date' => '2025-09-05 10:00:00']);

    expect(dashboardService()->getTripsSummary(dashboardAdmin(), ['dateFrom' => '2026-9-1'])['total'])->toBe(2)
        ->and(dashboardService()->getTripsSummary(dashboardAdmin(), ['dateFrom' => '2026-09-01'])['total'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| getVehicleExpensesSummary()
|--------------------------------------------------------------------------
*/

it('devuelve el resumen de gastos con la forma completa sobre una base vacía', function () {
    expect(dashboardService()->getVehicleExpensesSummary(dashboardAdmin(), []))->toBe([
        'totalAmount' => '0.00',
        'count' => 0,
        'byCategory' => [],
        'byNature' => [
            'preventive' => ['count' => 0, 'totalAmount' => '0.00'],
            'corrective' => ['count' => 0, 'totalAmount' => '0.00'],
        ],
        'invoiced' => ['count' => 0, 'totalAmount' => '0.00'],
        'notInvoiced' => ['count' => 0, 'totalAmount' => '0.00'],
        'byCarrier' => [],
        'byMonth' => [],
    ]);
});

it('ordena byCategory y byCarrier de gastos por monto descendente', function () {
    $small = Carrier::factory()->create();
    $big = Carrier::factory()->create();
    dashboardExpenseOf($small, ['amount' => 100, 'category' => VehicleExpenseCategory::Tires, 'nature' => VehicleExpenseNature::Preventive, 'is_invoiced' => true]);
    dashboardExpenseOf($big, ['amount' => 500, 'category' => VehicleExpenseCategory::Other, 'nature' => VehicleExpenseNature::Corrective]);
    dashboardExpenseOf($big, ['amount' => 50, 'category' => VehicleExpenseCategory::Tires, 'nature' => VehicleExpenseNature::Corrective]);

    $summary = dashboardService()->getVehicleExpensesSummary(dashboardAdmin(), []);

    expect($summary['totalAmount'])->toBe('650.00')
        ->and($summary['count'])->toBe(3)
        ->and(array_column($summary['byCategory'], 'category'))->toBe(['other', 'tires'])
        ->and(array_column($summary['byCarrier'], 'carrierId'))->toBe([$big->id, $small->id])
        ->and($summary['byNature']['preventive'])->toBe(['count' => 1, 'totalAmount' => '100.00'])
        ->and($summary['byNature']['corrective'])->toBe(['count' => 2, 'totalAmount' => '550.00'])
        ->and($summary['invoiced'])->toBe(['count' => 1, 'totalAmount' => '100.00'])
        ->and($summary['notInvoiced'])->toBe(['count' => 2, 'totalAmount' => '550.00']);
});

it('cuenta los gastos de un vehículo inactivo y acota por la empresa del vehículo', function () {
    $mine = Carrier::factory()->create();
    $other = Carrier::factory()->create();
    VehicleExpense::factory()->create([
        'vehicle_id' => Vehicle::factory()->create(['carrier_id' => $mine->id, 'status' => VehicleStatus::Inactive])->id,
        'amount' => 75,
    ]);
    dashboardExpenseOf($other, ['amount' => 25]);

    $summary = dashboardService()->getVehicleExpensesSummary(dashboardAdmin(), ['carrierId' => (string) $mine->id]);

    expect($summary['count'])->toBe(1)
        ->and($summary['totalAmount'])->toBe('75.00')
        ->and($summary['byCarrier'])->toBe([
            ['carrierId' => $mine->id, 'carrierName' => $mine->name, 'count' => 1, 'totalAmount' => '75.00'],
        ]);
});

it('corta los gastos por expense_date de forma inclusiva en ambos extremos', function () {
    $carrier = Carrier::factory()->create();
    dashboardExpenseOf($carrier, ['amount' => 1, 'expense_date' => '2026-09-01']);
    dashboardExpenseOf($carrier, ['amount' => 2, 'expense_date' => '2026-09-30']);
    dashboardExpenseOf($carrier, ['amount' => 4, 'expense_date' => '2026-10-01']);

    $summary = dashboardService()->getVehicleExpensesSummary(dashboardAdmin(), ['dateFrom' => '2026-09-01', 'dateTo' => '2026-09-30']);

    expect($summary['count'])->toBe(2)
        ->and($summary['totalAmount'])->toBe('3.00')
        ->and($summary['byMonth'])->toBe([['month' => '2026-09', 'count' => 2, 'totalAmount' => '3.00']]);
});

/*
|--------------------------------------------------------------------------
| getVehicles()
|--------------------------------------------------------------------------
*/

it('devuelve toda la flota como Collection en orden id ascendente y pagina solo con limit', function () {
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->count(3)->create(['carrier_id' => $carrier->id]);
    Vehicle::factory()->create(['carrier_id' => $carrier->id, 'status' => VehicleStatus::Inactive]);

    $all = dashboardService()->getVehicles(dashboardAdmin(), []);
    $page = dashboardService()->getVehicles(dashboardAdmin(), ['limit' => '10']);

    expect($all)->toBeInstanceOf(Collection::class)
        ->and($all)->toHaveCount(4)
        ->and($all->modelKeys())->toBe($all->sortBy('id')->modelKeys())
        ->and($page)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($page->total())->toBe(4)
        ->and($page->perPage())->toBe(10)
        ->and(dashboardService()->getVehicles(dashboardAdmin(), ['limit' => '500']))->toBeInstanceOf(LengthAwarePaginator::class)
        ->and(dashboardService()->getVehicles(dashboardAdmin(), ['limit' => '500'])->perPage())->toBe(100)
        ->and(dashboardService()->getVehicles(dashboardAdmin(), ['limit' => 'abc']))->toBeInstanceOf(Collection::class);
});

it('cuelga de cada vehículo su viaje en curso más reciente como atributo transitorio', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);
    $idle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);
    dashboardTripOf($carrier, ['vehicle_id' => $vehicle->id, 'status' => TripStatus::InRoute, 'start_date' => '2026-09-14 06:00:00']);
    $latest = dashboardTripOf($carrier, ['vehicle_id' => $vehicle->id, 'status' => TripStatus::InRoute, 'start_date' => '2026-09-14 08:15:00']);
    dashboardTripOf($carrier, ['vehicle_id' => $idle->id, 'status' => TripStatus::Finished, 'start_date' => now(), 'end_date' => now()]);

    $vehicles = dashboardService()->getVehicles(dashboardAdmin(), [])->keyBy('id');

    expect($vehicles->get($vehicle->id)->getAttribute('currentTrip'))->toBeInstanceOf(Trip::class)
        ->and($vehicles->get($vehicle->id)->getAttribute('currentTrip')->id)->toBe($latest->id)
        ->and($vehicles->get($vehicle->id)->getAttribute('currentTrip')->relationLoaded('pilot'))->toBeTrue()
        ->and($vehicles->get($idle->id)->getAttribute('currentTrip'))->toBeNull();
});

it('aplica el filtro inRoute antes de paginar e ignora los filtros inválidos', function () {
    $carrier = Carrier::factory()->create();
    $busy = Vehicle::factory()->create(['carrier_id' => $carrier->id]);
    Vehicle::factory()->count(2)->create(['carrier_id' => $carrier->id]);
    dashboardTripOf($carrier, ['vehicle_id' => $busy->id, 'status' => TripStatus::InRoute, 'start_date' => now()]);

    expect(dashboardService()->getVehicles(dashboardAdmin(), ['inRoute' => 'true', 'limit' => '10'])->total())->toBe(1)
        ->and(dashboardService()->getVehicles(dashboardAdmin(), ['inRoute' => 'false']))->toHaveCount(2)
        ->and(dashboardService()->getVehicles(dashboardAdmin(), ['inRoute' => 'basura', 'status' => 'basura', 'condition' => 'basura']))->toHaveCount(3);
});

/*
|--------------------------------------------------------------------------
| getTripsInRoute()
|--------------------------------------------------------------------------
*/

it('devuelve los viajes en curso con sus atributos transitorios y sumas de combustible', function () {
    $this->travelTo('2026-09-14 09:00:15');
    $carrier = Carrier::factory()->create();
    $trip = dashboardTripOf($carrier, ['status' => TripStatus::InRoute, 'start_date' => '2026-09-14 08:00:00']);
    dashboardTripOf($carrier, ['status' => TripStatus::Pending, 'start_date' => now()]);

    TripPosition::factory()->create(['trip_id' => $trip->id, 'pilot_id' => $trip->pilot_id, 'recorded_at' => '2026-09-14 08:30:00']);
    $last = TripPosition::factory()->create(['trip_id' => $trip->id, 'pilot_id' => $trip->pilot_id, 'recorded_at' => '2026-09-14 08:50:00']);
    TripTimeout::factory()->open()->create([
        'trip_id' => $trip->id,
        'pilot_id' => $trip->pilot_id,
        'start_position_id' => $last->id,
        'started_at' => '2026-09-14 08:50:00',
    ]);
    TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id, 'gallons' => 45]);
    TripFuel::factory()->create(['trip_id' => $trip->id, 'gallons' => 10]);

    $trips = dashboardService()->getTripsInRoute(dashboardAdmin(), []);

    expect($trips)->toHaveCount(1)
        ->and($trips->first()->getAttribute('lastPosition')->id)->toBe($last->id)
        ->and($trips->first()->getAttribute('openTimeout'))->toBeInstanceOf(TripTimeout::class)
        ->and((float) $trips->first()->total_fuel_gallons)->toBe(45.0)
        ->and((float) $trips->first()->unconfirmed_fuel_gallons)->toBe(10.0)
        ->and($trips->first()->relationLoaded('assignedBy'))->toBeTrue()
        ->and($trips->first()->assignedBy->relationLoaded('carrier'))->toBeTrue();
});

it('ordena los viajes en curso por start_date descendente y acota por carrierId', function () {
    $mine = Carrier::factory()->create();
    $other = Carrier::factory()->create();
    $older = dashboardTripOf($mine, ['status' => TripStatus::InRoute, 'start_date' => '2026-09-14 06:00:00']);
    $newer = dashboardTripOf($mine, ['status' => TripStatus::InRoute, 'start_date' => '2026-09-14 08:00:00']);
    dashboardTripOf($other, ['status' => TripStatus::InRoute, 'start_date' => '2026-09-14 09:00:00']);

    expect(dashboardService()->getTripsInRoute(dashboardAdmin(), [])->modelKeys())->toHaveCount(3)
        ->and(dashboardService()->getTripsInRoute(dashboardAdmin(), ['carrierId' => (string) $mine->id])->modelKeys())->toBe([$newer->id, $older->id])
        ->and(dashboardService()->getTripsInRoute(dashboardAdmin(), ['carrierId' => '999999']))->toHaveCount(3);
});

/*
|--------------------------------------------------------------------------
| Ámbito por rol
|--------------------------------------------------------------------------
*/

it('acota los cuatro métodos a la empresa del transportista e ignora su carrierId', function () {
    $mine = Carrier::factory()->create();
    $other = Carrier::factory()->create();
    $mineTrip = dashboardTripOf($mine, ['status' => TripStatus::InRoute, 'start_date' => now()->subHour()]);
    dashboardTripOf($other, ['status' => TripStatus::InRoute, 'start_date' => now()->subHour()]);
    dashboardExpenseOf($mine, ['amount' => 100]);
    dashboardExpenseOf($other, ['amount' => 900]);

    $owner = dashboardOwnerOf($mine);
    $filters = ['carrierId' => (string) $other->id];

    expect(dashboardService()->getTripsSummary($owner, $filters)['byCarrier'])->toBe([
        ['carrierId' => $mine->id, 'carrierName' => $mine->name, 'total' => 1],
    ])
        ->and(dashboardService()->getTripsInRoute($owner, $filters)->modelKeys())->toBe([$mineTrip->id])
        ->and(dashboardService()->getVehicleExpensesSummary($owner, $filters)['totalAmount'])->toBe('100.00')
        ->and(dashboardService()->getVehicles($owner, $filters)->pluck('carrier_id')->unique()->all())->toBe([$mine->id]);
});

it('acota al piloto vinculado a su empresa y no a la empresa del filtro', function () {
    $mine = Carrier::factory()->create();
    $other = Carrier::factory()->create();
    dashboardTripOf($mine);
    dashboardTripOf($other);

    $pilot = User::factory()->create(['role' => UserRole::Pilot]);
    $mine->pilots()->attach($pilot);

    expect(dashboardService()->getTripsSummary($pilot, ['carrierId' => (string) $other->id])['byCarrier'])->toBe([
        ['carrierId' => $mine->id, 'carrierName' => $mine->name, 'total' => 1],
    ]);
});

it('deja al manager sin ámbito, con carrierId como filtro voluntario', function () {
    $mine = Carrier::factory()->create();
    $other = Carrier::factory()->create();
    dashboardTripOf($mine);
    dashboardTripOf($other);

    $manager = User::factory()->create(['role' => UserRole::Manager]);

    expect(dashboardService()->getTripsSummary($manager, [])['total'])->toBe(2)
        ->and(dashboardService()->getTripsSummary($manager, ['carrierId' => (string) $other->id])['byCarrier'])->toBe([
            ['carrierId' => $other->id, 'carrierName' => $other->name, 'total' => 1],
        ]);
});

it('rechaza con ForbiddenError a un transportista sin empresa en los cuatro métodos', function () {
    $carrier = User::factory()->create(['role' => UserRole::Carrier]);

    expect(fn () => dashboardService()->getTripsSummary($carrier, []))->toThrow(ForbiddenError::class, 'No perteneces a ninguna empresa transportista')
        ->and(fn () => dashboardService()->getTripsInRoute($carrier, []))->toThrow(ForbiddenError::class)
        ->and(fn () => dashboardService()->getVehicleExpensesSummary($carrier, []))->toThrow(ForbiddenError::class)
        ->and(fn () => dashboardService()->getVehicles($carrier, []))->toThrow(ForbiddenError::class);
});
