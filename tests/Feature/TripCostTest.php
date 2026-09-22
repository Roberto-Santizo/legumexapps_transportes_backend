<?php

use App\Enums\FuelType;
use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\CarrierPilot;
use App\Models\FuelPrice;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\TripFuel;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

if (! function_exists('userWithRole')) {
    /**
     * Create a confirmed user with the given role.
     */
    function userWithRole(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }
}

if (! function_exists('asUser')) {
    /**
     * Authenticate the next request as the given user.
     *
     * The JWT singletons survive between calls of the same test, so the guard
     * state is dropped before handing the fresh token over.
     */
    function asUser(User $user): TestCase
    {
        resetAuthState();

        return test()->withToken(JWTAuth::fromUser($user))->withHeader('Accept', 'application/json');
    }
}

/**
 * A trip in the given state, with its assigned pilot and the owner that took it.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{trip: Trip, pilot: User, owner: User}
 */
function tripWithCost(string $state = 'finished', array $attributes = []): array
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
function tripCostOutsider(): User
{
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    return $carrier->owner;
}

/**
 * Seed the trip with the four components of a known cost.
 *
 * 35 gal of diesel at 38.50 = 1347.50, 450.00 in allowances, a 4500.00 salary and a
 * 350.00 insurance over 2.50 hours: 1347.50 + 450.00 + 15.63 + 1.22 = 1814.35.
 */
function seedTripCost(Trip $trip): void
{
    FuelPrice::factory()->create([
        'fuel_type' => FuelType::Diesel,
        'price' => 38.50,
        'created_at' => '2026-01-01 08:00:00',
    ]);

    TripFuel::factory()->create([
        'trip_id' => $trip->id,
        'gallons' => 35,
        'fuel_type' => FuelType::Diesel,
        'loaded_at' => '2026-02-10 09:00:00',
        'confirmed_by' => $trip->pilot_id,
    ]);

    TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 450]);

    CarrierPilot::query()
        ->where('user_id', '=', $trip->pilot_id)
        ->update(['salary' => 4500.00]);

    $trip->vehicle->update(['monthly_insurance_cost' => 350.00]);
}

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

it('rechaza la consulta del costo sin token', function () {
    $this->getJson('/api/trips/1/cost')
        ->assertStatus(401)
        ->assertJsonPath('message', 'El token de sesión no es válido o ha expirado');
});

it('exige token en todos los endpoints de costo', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertStatus(401)
        ->assertJsonPath('message', 'El token de sesión no es válido o ha expirado');
})->with([
    ['GET', '/api/trips/1/cost'],
]);

/*
|--------------------------------------------------------------------------
| Guardas
|--------------------------------------------------------------------------
*/

it('responde 404 cuando el viaje no existe', function () {
    asUser(userWithRole(UserRole::Administrator))
        ->getJson('/api/trips/9999/cost')
        ->assertStatus(404)
        ->assertJsonPath('message', 'El viaje no existe');
});

it('responde 404 cuando el viaje fue borrado, también si es de otra empresa', function () {
    $trip = Trip::factory()->finished()->trashed()->create();

    asUser(tripCostOutsider())
        ->getJson("/api/trips/{$trip->id}/cost")
        ->assertStatus(404)
        ->assertJsonPath('message', 'El viaje no existe');
});

it('responde 403 a cualquier piloto, incluido el asignado al viaje', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripWithCost();

    asUser($pilot)
        ->getJson("/api/trips/{$trip->id}/cost")
        ->assertStatus(403)
        ->assertJsonPath('message', 'No tienes permisos para consultar el costo de un viaje');
});

it('responde 403 a un piloto que no es el del viaje', function () {
    ['trip' => $trip] = tripWithCost();

    asUser(userWithRole(UserRole::Pilot))
        ->getJson("/api/trips/{$trip->id}/cost")
        ->assertStatus(403);
});

it('responde 403 al transportista que no tomó el viaje', function () {
    ['trip' => $trip] = tripWithCost();

    asUser(tripCostOutsider())
        ->getJson("/api/trips/{$trip->id}/cost")
        ->assertStatus(403)
        ->assertJsonPath('message', 'No puedes acceder a un viaje que no pertenece a tu empresa transportista');
});

it('responde 200 al administrador, al manager y al transportista que tomó el viaje', function () {
    ['trip' => $trip, 'owner' => $owner] = tripWithCost();

    foreach ([userWithRole(UserRole::Administrator), userWithRole(UserRole::Manager), $owner] as $user) {
        asUser($user)
            ->getJson("/api/trips/{$trip->id}/cost")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Costo del viaje obtenido correctamente')
            ->assertJsonPath('data.tripId', $trip->id);
    }
});

it('responde 400 cuando el viaje todavía no ha terminado', function (string $state) {
    ['trip' => $trip, 'owner' => $owner] = tripWithCost($state);

    asUser($owner)
        ->getJson("/api/trips/{$trip->id}/cost")
        ->assertStatus(400)
        ->assertJsonPath('message', 'El costo solo está disponible para viajes finalizados');
})->with(['assigned', 'inRoute']);

it('responde 400 aunque el viaje en curso ya tenga cargas y viáticos confirmados', function () {
    ['trip' => $trip, 'owner' => $owner] = tripWithCost('inRoute');

    seedTripCost($trip);

    asUser($owner)
        ->getJson("/api/trips/{$trip->id}/cost")
        ->assertStatus(400)
        ->assertJsonPath('message', 'El costo solo está disponible para viajes finalizados');
});

it('responde 403 y no 400 sobre un viaje en curso de otra empresa', function () {
    ['trip' => $trip] = tripWithCost('inRoute');

    asUser(tripCostOutsider())
        ->getJson("/api/trips/{$trip->id}/cost")
        ->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| Forma de la respuesta
|--------------------------------------------------------------------------
*/

it('devuelve las siete claves del desglose y la forma de los cuatro bloques', function () {
    ['trip' => $trip, 'owner' => $owner] = tripWithCost('finished', ['traveled_hours' => 2.50]);

    seedTripCost($trip);

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/cost")->assertStatus(200);

    expect(array_keys($response->json('data')))->toBe([
        'tripId', 'order', 'traveledHours', 'fuel', 'expenses', 'pilot', 'vehicle', 'totalCost',
    ]);

    $response
        ->assertJsonStructure([
            'data' => [
                'fuel' => ['gallons', 'byType' => [['fuelType', 'gallons', 'pricePerGallon', 'amount']], 'subtotal'],
                'expenses' => ['count', 'subtotal'],
                'pilot' => ['pilotId', 'pilotName', 'monthlySalary', 'subtotal'],
                'vehicle' => ['vehicleId', 'plate', 'monthlyInsuranceCost', 'subtotal'],
            ],
        ]);
});

it('pinta el desglose completo con los cuatro componentes', function () {
    ['trip' => $trip, 'owner' => $owner] = tripWithCost('finished', ['traveled_hours' => 2.50]);

    seedTripCost($trip);

    asUser($owner)
        ->getJson("/api/trips/{$trip->id}/cost")
        ->assertStatus(200)
        ->assertJsonPath('data.order', $trip->order)
        ->assertJsonPath('data.traveledHours', '2.50')
        ->assertJsonPath('data.fuel.gallons', '35.00')
        ->assertJsonPath('data.fuel.byType.0.fuelType', 'diesel')
        ->assertJsonPath('data.fuel.byType.0.pricePerGallon', '38.50')
        ->assertJsonPath('data.fuel.byType.0.amount', '1347.50')
        ->assertJsonPath('data.fuel.subtotal', '1347.50')
        ->assertJsonPath('data.expenses.count', 1)
        ->assertJsonPath('data.expenses.subtotal', '450.00')
        ->assertJsonPath('data.pilot.pilotId', $trip->pilot_id)
        ->assertJsonPath('data.pilot.pilotName', $trip->pilot->name)
        ->assertJsonPath('data.pilot.monthlySalary', '4500.00')
        ->assertJsonPath('data.pilot.subtotal', '15.63')
        ->assertJsonPath('data.vehicle.vehicleId', $trip->vehicle_id)
        ->assertJsonPath('data.vehicle.plate', $trip->vehicle->plate)
        ->assertJsonPath('data.vehicle.monthlyInsuranceCost', '350.00')
        ->assertJsonPath('data.vehicle.subtotal', '1.22')
        ->assertJsonPath('data.totalCost', '1814.35');
});

it('devuelve el total como suma exacta de los cuatro subtotales tal como salen', function () {
    ['trip' => $trip, 'owner' => $owner] = tripWithCost('finished', ['traveled_hours' => 2.50]);

    seedTripCost($trip);

    $data = asUser($owner)->getJson("/api/trips/{$trip->id}/cost")->json('data');

    $sum = (float) $data['fuel']['subtotal'] + (float) $data['expenses']['subtotal']
        + (float) $data['pilot']['subtotal'] + (float) $data['vehicle']['subtotal'];

    expect($data['totalCost'])->toBe(number_format($sum, 2, '.', ''));
});

it('devuelve un viaje sin cargas ni viáticos con los bloques en cero', function () {
    ['trip' => $trip, 'owner' => $owner] = tripWithCost('finished', ['traveled_hours' => null]);

    asUser($owner)
        ->getJson("/api/trips/{$trip->id}/cost")
        ->assertStatus(200)
        ->assertJsonPath('data.traveledHours', null)
        ->assertJsonPath('data.fuel.gallons', '0.00')
        ->assertJsonPath('data.fuel.byType', [])
        ->assertJsonPath('data.fuel.subtotal', '0.00')
        ->assertJsonPath('data.expenses.count', 0)
        ->assertJsonPath('data.expenses.subtotal', '0.00')
        ->assertJsonPath('data.pilot.subtotal', '0.00')
        ->assertJsonPath('data.vehicle.subtotal', '0.00')
        ->assertJsonPath('data.totalCost', '0.00');
});

it('deja en null los insumos del viaje finalizado sin tripulación', function () {
    ['trip' => $trip] = tripWithCost('finished', ['traveled_hours' => 2.50]);

    /** Solo alcanzable por el PATCH del administrador, el hueco declarado de SPEC 24. */
    $trip->update(['pilot_id' => null, 'vehicle_id' => null]);

    asUser(userWithRole(UserRole::Administrator))
        ->getJson("/api/trips/{$trip->id}/cost")
        ->assertStatus(200)
        ->assertJsonPath('data.pilot.pilotId', null)
        ->assertJsonPath('data.pilot.pilotName', null)
        ->assertJsonPath('data.pilot.monthlySalary', null)
        ->assertJsonPath('data.pilot.subtotal', '0.00')
        ->assertJsonPath('data.vehicle.vehicleId', null)
        ->assertJsonPath('data.vehicle.plate', null)
        ->assertJsonPath('data.vehicle.monthlyInsuranceCost', null)
        ->assertJsonPath('data.vehicle.subtotal', '0.00');
});

it('deja los dos prorrateos en cero con los insumos a la vista en un viaje anterior a SPEC 32', function () {
    ['trip' => $trip, 'owner' => $owner] = tripWithCost('finished', ['traveled_hours' => null]);

    seedTripCost($trip);

    asUser($owner)
        ->getJson("/api/trips/{$trip->id}/cost")
        ->assertStatus(200)
        ->assertJsonPath('data.traveledHours', null)
        ->assertJsonPath('data.pilot.monthlySalary', '4500.00')
        ->assertJsonPath('data.pilot.subtotal', '0.00')
        ->assertJsonPath('data.vehicle.monthlyInsuranceCost', '350.00')
        ->assertJsonPath('data.vehicle.subtotal', '0.00');
});

it('deja el precio en null cuando la carga es anterior a cualquier precio de su tipo', function () {
    ['trip' => $trip, 'owner' => $owner] = tripWithCost('finished', ['traveled_hours' => 2.50]);

    FuelPrice::factory()->create([
        'fuel_type' => FuelType::Premium,
        'price' => 40.00,
        'created_at' => '2026-05-01 08:00:00',
    ]);

    TripFuel::factory()->create([
        'trip_id' => $trip->id,
        'gallons' => 12,
        'fuel_type' => FuelType::Premium,
        'loaded_at' => '2026-01-10 09:00:00',
        'confirmed_by' => $trip->pilot_id,
    ]);

    asUser($owner)
        ->getJson("/api/trips/{$trip->id}/cost")
        ->assertStatus(200)
        ->assertJsonPath('data.fuel.gallons', '12.00')
        ->assertJsonPath('data.fuel.byType.0.pricePerGallon', null)
        ->assertJsonPath('data.fuel.byType.0.amount', '0.00')
        ->assertJsonPath('data.fuel.subtotal', '0.00');
});

it('ignora cualquier query param sin devolver 422', function () {
    ['trip' => $trip, 'owner' => $owner] = tripWithCost();

    asUser($owner)
        ->getJson("/api/trips/{$trip->id}/cost?limit=5&currency=USD&includeDepreciation=true")
        ->assertStatus(200);
});

/*
|--------------------------------------------------------------------------
| Consultas
|--------------------------------------------------------------------------
*/

it('no crece en consultas con el número de cargas, viáticos ni posiciones', function () {
    ['trip' => $small, 'owner' => $owner] = tripWithCost('finished', ['traveled_hours' => 2.50]);
    seedTripCost($small);

    $big = Trip::factory()->finished()->create([
        'traveled_hours' => 2.50,
        'assigned_by' => $small->assigned_by,
        'pilot_id' => $small->pilot_id,
        'vehicle_id' => $small->vehicle_id,
    ]);

    TripFuel::factory()->count(12)->create([
        'trip_id' => $big->id,
        'fuel_type' => FuelType::Diesel,
        'loaded_at' => '2026-02-10 09:00:00',
        'confirmed_by' => $big->pilot_id,
    ]);
    TripExpense::factory()->confirmed()->count(12)->create(['trip_id' => $big->id]);

    $count = function (int $tripId) use ($owner): int {
        resetAuthState();

        /** El token se emite fuera de la medición: sus claims consultan la empresa del usuario. */
        $token = JWTAuth::fromUser($owner);

        DB::flushQueryLog();
        DB::enableQueryLog();

        test()->withToken($token)->withHeader('Accept', 'application/json')
            ->getJson("/api/trips/{$tripId}/cost")->assertStatus(200);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count($big->id))->toBe($count($small->id));
});
