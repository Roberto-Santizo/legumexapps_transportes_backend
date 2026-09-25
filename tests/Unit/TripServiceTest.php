<?php

use App\Enums\FuelType;
use App\Enums\LocationType;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Enums\VehicleStatus;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Trip\TripServiceInterface;
use App\Models\Carrier;
use App\Models\Client;
use App\Models\DeparturePoint;
use App\Models\FinishedProduct;
use App\Models\Location;
use App\Models\ShippingLine;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\TripFuel;
use App\Models\TripPosition;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Place\PolylineDecoder;
use App\Services\Place\PolylineEncoder;
use App\Services\Trip\TripService;
use App\Services\TripTimeout\DistanceCalculator;
use Database\Factories\TripFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function tripService(): TripServiceInterface
{
    return app(TripServiceInterface::class);
}

function tripServiceUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

/**
 * Build a whole carrier company: owner, linked pilot and an active vehicle of its own.
 *
 * @return array{carrier: Carrier, owner: User, pilot: User, vehicle: Vehicle}
 */
function tripServiceTeam(): array
{
    $carrier = Carrier::factory()->create();
    $pilot = User::factory()->create(['role' => UserRole::Pilot]);
    $carrier->pilots()->attach($pilot);

    return [
        'carrier' => $carrier,
        'owner' => $carrier->owner,
        'pilot' => $pilot,
        'vehicle' => Vehicle::factory()->create(['carrier_id' => $carrier->id]),
    ];
}

/**
 * The payload the service expects, straight from the validated request: camelCase keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tripServiceData(array $overrides = []): array
{
    $payload = array_merge([
        'order' => '  ord-2026   0001 ',
        'clientId' => Client::factory()->create()->id,
        'shippingLineId' => ShippingLine::factory()->create()->id,
        'departurePointId' => DeparturePoint::factory()->create()->id,
        'locationId' => Location::factory()->port()->active()->create()->id,
        'destination' => 'Rotterdam, Países Bajos',
        'container' => ' msku  123456 7 ',
        'transport' => 'Rastra 40 pies',
        'recolectionDate' => now()->addDays(3)->startOfSecond()->format('Y-m-d H:i:s'),
        'shipDate' => now()->addDays(5)->startOfSecond()->format('Y-m-d H:i:s'),
        'polyline' => TripFactory::POLYLINE,
        'estimatedKilometers' => 104.32,
        'estimatedHours' => 1.75,
        'observations' => 'Cargar a primera hora',
    ], $overrides);

    /**
     * SPEC 37: una línea de producto terminado del mismo cliente que el payload, salvo
     * que el test mande sus propios products. Con un clientId que no es un cliente
     * existente el producto lleva su propio cliente: ese alta falla antes igual.
     */
    if (! array_key_exists('products', $overrides)) {
        $payload['products'] = [tripServiceDataProductLine($payload['clientId'] ?? null)];
    }

    return $payload;
}

/**
 * One valid `products` line for a trip payload: a fresh finished product of the given
 * client, or of a client of its own when the given id is not an existing client.
 *
 * @return array{finishedProductId: int, boxes: int}
 */
function tripServiceDataProductLine(mixed $clientId): array
{
    $exists = is_numeric($clientId) && Client::withTrashed()->whereKey((int) $clientId)->exists();

    return [
        'finishedProductId' => FinishedProduct::factory()->create($exists ? ['client_id' => (int) $clientId] : [])->id,
        'boxes' => 960,
    ];
}

it('resuelve la implementación de viajes registrada en el provider', function () {
    expect(tripService())->toBeInstanceOf(TripService::class);
});

it('declara los nueve métodos del contrato', function () {
    expect(get_class_methods(TripServiceInterface::class))->toEqualCanonicalizing([
        'getTrips', 'getTripById', 'create', 'update', 'destroy', 'assign', 'start', 'finish', 'getCurrentTrip',
    ]);
});

/*
|--------------------------------------------------------------------------
| Base de datos, modelo y enum
|--------------------------------------------------------------------------
*/

it('crea la tabla trips con sus columnas, incluida deleted_at, la traveled_polyline de SPEC 28, las estimaciones de SPEC 30 y las métricas reales de SPEC 32', function () {
    expect(Schema::hasTable('trips'))->toBeTrue()
        ->and(Schema::getColumnListing('trips'))->toEqualCanonicalizing([
            'id', 'order', 'client_id', 'shipping_line_id', 'departure_point_id', 'location_id',
            'destination', 'container', 'transport',
            'recolection_date', 'ship_date', 'start_date', 'end_date',
            'polyline', 'estimated_kilometers', 'estimated_hours',
            'traveled_polyline', 'traveled_kilometers', 'traveled_hours', 'observations', 'status',
            'pilot_id', 'vehicle_id', 'assigned_by', 'registered_by',
            'created_at', 'updated_at', 'deleted_at',
        ]);
});

it('deja las métricas reales en null por defecto y solo el estado finished de la factory las rellena', function () {
    $pendiente = Trip::factory()->create();
    $finalizado = Trip::factory()->finished()->create();

    expect($pendiente->traveled_kilometers)->toBeNull()
        ->and($pendiente->traveled_hours)->toBeNull()
        ->and((float) $finalizado->fresh()->traveled_kilometers)->toBeGreaterThan(0)
        ->and((float) $finalizado->fresh()->traveled_hours)->toBeGreaterThan(0)
        ->and($finalizado->fresh()->traveled_kilometers)->toMatch('/^\d+\.\d{2}$/')
        ->and($finalizado->fresh()->traveled_hours)->toMatch('/^\d+\.\d{2}$/');
});

it('declara exactamente tres estados y ningún label', function () {
    expect(TripStatus::cases())->toHaveCount(3)
        ->and(array_map(fn (TripStatus $case) => $case->value, TripStatus::cases()))->toBe(['pending', 'in_route', 'finished'])
        ->and(method_exists(TripStatus::class, 'label'))->toBeFalse();
});

it('normaliza una referencia trimando, colapsando espacios y pasando a mayúsculas', function (string $entrada, string $esperado) {
    expect(Trip::normalizeReference($entrada))->toBe($esperado);
})->with([
    'con espacios de sobra' => ['  ord-2026   0001 ', 'ORD-2026 0001'],
    'ya normalizada' => ['ORD-2026 0001', 'ORD-2026 0001'],
    'con tabulador y salto de línea' => ["msku\n\t123456", 'MSKU 123456'],
    'vacía' => ['   ', ''],
]);

/*
|--------------------------------------------------------------------------
| create()
|--------------------------------------------------------------------------
*/

it('persiste el viaje normalizando la orden y el contenedor', function () {
    $trip = tripService()->create(tripServiceUser(UserRole::Administrator), tripServiceData());

    expect($trip->order)->toBe('ORD-2026 0001')
        ->and($trip->container)->toBe('MSKU 123456 7')
        /** Los tres de texto libre entran tal cual: el trim es del FormRequest, no del service. */
        ->and($trip->destination)->toBe('Rotterdam, Países Bajos')
        ->and($trip->transport)->toBe('Rastra 40 pies')
        ->and($trip->observations)->toBe('Cargar a primera hora')
        ->and($trip->polyline)->toBe(TripFactory::POLYLINE)
        ->and(Trip::query()->whereKey($trip->id)->exists())->toBeTrue();
});

it('persiste la distancia y la duración estimadas tal cual llegan, sin cast', function () {
    $trip = tripService()->create(tripServiceUser(UserRole::Administrator), tripServiceData([
        'estimatedKilometers' => '104.32',
        'estimatedHours' => '1.75',
    ]));

    /** Postgres devuelve el decimal como string: el formato de salida es del Resource. */
    expect($trip->fresh()->estimated_kilometers)->toBe('104.32')
        ->and($trip->fresh()->estimated_hours)->toBe('1.75');
});

it('admite cero en las dos estimaciones: una ruta muy corta redondeada es legítima', function () {
    $trip = tripService()->create(tripServiceUser(UserRole::Administrator), tripServiceData([
        'estimatedKilometers' => 0,
        'estimatedHours' => 0,
    ]));

    expect($trip->fresh()->estimated_kilometers)->toBe('0.00')
        ->and($trip->fresh()->estimated_hours)->toBe('0.00');
});

it('fuerza el estado pendiente y descarta la tripulación que llegue en los datos', function () {
    $admin = tripServiceUser(UserRole::Administrator);
    $team = tripServiceTeam();

    $trip = tripService()->create($admin, tripServiceData([
        'status' => TripStatus::Finished->value,
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
        'assignedBy' => $team['owner']->id,
        'registeredBy' => $team['owner']->id,
    ]));

    expect($trip->status)->toBe(TripStatus::Pending)
        ->and($trip->pilot_id)->toBeNull()
        ->and($trip->vehicle_id)->toBeNull()
        ->and($trip->assigned_by)->toBeNull()
        /** El autor sale del usuario recibido, nunca de los datos. */
        ->and($trip->registered_by)->toBe($admin->id);
});

it('carga las ocho relaciones en el alta', function () {
    $trip = tripService()->create(tripServiceUser(UserRole::Administrator), tripServiceData());

    foreach (['client', 'shippingLine', 'departurePoint', 'location', 'pilot', 'vehicle', 'assignedBy', 'registeredBy'] as $relacion) {
        expect($trip->relationLoaded($relacion))->toBeTrue();
    }
});

it('rechaza el alta con un cliente borrado', function () {
    tripService()->create(tripServiceUser(UserRole::Administrator), tripServiceData([
        'clientId' => Client::factory()->trashed()->create()->id,
    ]));
})->throws(BadRequestError::class, 'El cliente seleccionado fue eliminado');

it('rechaza el alta con una naviera borrada', function () {
    tripService()->create(tripServiceUser(UserRole::Administrator), tripServiceData([
        'shippingLineId' => ShippingLine::factory()->trashed()->create()->id,
    ]));
})->throws(BadRequestError::class, 'La naviera seleccionada fue eliminada');

it('rechaza el alta con un destino que no es puerto', function () {
    tripService()->create(tripServiceUser(UserRole::Administrator), tripServiceData([
        'locationId' => Location::factory()->active()->create(['type' => LocationType::Destination])->id,
    ]));
})->throws(BadRequestError::class, 'El destino seleccionado no es un puerto');

it('rechaza el alta con un puerto inactivo', function () {
    tripService()->create(tripServiceUser(UserRole::Administrator), tripServiceData([
        'locationId' => Location::factory()->port()->inactive()->create()->id,
    ]));
})->throws(BadRequestError::class, 'El puerto de destino está inactivo');

it('rechaza el alta con un punto de partida inactivo', function () {
    tripService()->create(tripServiceUser(UserRole::Administrator), tripServiceData([
        'departurePointId' => DeparturePoint::factory()->inactive()->create()->id,
    ]));
})->throws(BadRequestError::class, 'El punto de partida está inactivo');

/*
|--------------------------------------------------------------------------
| getTrips(): ámbito
|--------------------------------------------------------------------------
*/

it('devuelve todos los viajes al administrador y al gerente', function (UserRole $role) {
    Trip::factory()->create();
    Trip::factory()->assigned()->create();

    expect(tripService()->getTrips(tripServiceUser($role), []))->toHaveCount(2);
})->with([
    'administrador' => UserRole::Administrator,
    'gerente' => UserRole::Manager,
]);

it('devuelve al transportista la bolsa más lo que asignó su propia empresa', function () {
    $empresaA = tripServiceTeam();
    $enLaBolsa = Trip::factory()->create();
    $suyo = Trip::factory()->create([
        'pilot_id' => $empresaA['pilot']->id,
        'vehicle_id' => $empresaA['vehicle']->id,
        'assigned_by' => $empresaA['owner']->id,
    ]);
    $ajeno = Trip::factory()->assigned()->create();

    $ids = tripService()->getTrips($empresaA['owner'], [])->pluck('id')->all();

    expect($ids)->toContain($enLaBolsa->id)
        ->and($ids)->toContain($suyo->id)
        ->and($ids)->not->toContain($ajeno->id);
});

it('compara la empresa y no la persona: el piloto de la empresa ve lo que asignó su dueño', function () {
    $empresaA = tripServiceTeam();
    $suyo = Trip::factory()->create([
        'pilot_id' => $empresaA['pilot']->id,
        'vehicle_id' => $empresaA['vehicle']->id,
        'assigned_by' => $empresaA['owner']->id,
    ]);

    /** Un segundo usuario de la empresa, que no fue quien asignó. */
    $companero = tripServiceUser(UserRole::Carrier);
    $empresaA['carrier']->pilots()->attach($companero);

    expect(tripService()->getTrips($companero, [])->pluck('id')->all())->toBe([$suyo->id]);
});

it('devuelve al piloto solo sus viajes, sin la bolsa', function () {
    $team = tripServiceTeam();
    $suyo = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
    ]);
    Trip::factory()->create();
    Trip::factory()->assigned()->create();

    expect(tripService()->getTrips($team['pilot'], [])->pluck('id')->all())->toBe([$suyo->id]);
});

it('devuelve solo la bolsa a un transportista sin empresa', function () {
    $suelto = tripServiceUser(UserRole::Carrier);
    $enLaBolsa = Trip::factory()->create();
    Trip::factory()->assigned()->create();

    expect(tripService()->getTrips($suelto, [])->pluck('id')->all())->toBe([$enLaBolsa->id]);
});

it('aplica el ámbito antes que los filtros', function () {
    $empresaB = tripServiceTeam();
    $enLaBolsa = Trip::factory()->create();
    $deLaA = Trip::factory()->assigned()->create();

    $ids = tripService()->getTrips($empresaB['owner'], ['status' => 'pending'])->pluck('id')->all();

    expect($deLaA->status)->toBe(TripStatus::Pending)
        ->and($ids)->toBe([$enLaBolsa->id]);
});

/*
|--------------------------------------------------------------------------
| getTrips(): filtros, orden y paginación
|--------------------------------------------------------------------------
*/

it('devuelve una Collection sin limit y un LengthAwarePaginator con él', function () {
    $admin = tripServiceUser(UserRole::Administrator);
    Trip::factory()->count(3)->create();

    expect(tripService()->getTrips($admin, []))->toBeInstanceOf(Collection::class)
        ->and(tripService()->getTrips($admin, ['limit' => null]))->toBeInstanceOf(Collection::class)
        ->and(tripService()->getTrips($admin, ['limit' => 'abc']))->toBeInstanceOf(Collection::class)
        ->and(tripService()->getTrips($admin, ['limit' => '10']))->toBeInstanceOf(LengthAwarePaginator::class);
});

it('acota el tamaño de página a [1, 100], sin el piso de 10 del resto del proyecto', function (string $limit, int $esperado) {
    $paginator = tripService()->getTrips(tripServiceUser(UserRole::Administrator), ['limit' => $limit]);

    expect($paginator->perPage())->toBe($esperado);
})->with([
    'un tamaño pequeño se respeta tal cual' => ['5', 5],
    'el mínimo' => ['1', 1],
    'cero sube al mínimo' => ['0', 1],
    'negativo sube al mínimo' => ['-20', 1],
    'dentro del rango' => ['25', 25],
    'por encima del máximo' => ['500', 100],
]);

it('ordena por fecha de recolección descendente y por id descendente', function () {
    $viejo = Trip::factory()->create(['recolection_date' => now()->addDays(2)]);
    $empateA = Trip::factory()->create(['recolection_date' => now()->addDays(9)]);
    $empateB = Trip::factory()->create(['recolection_date' => now()->addDays(9)]);

    expect(tripService()->getTrips(tripServiceUser(UserRole::Administrator), [])->pluck('id')->all())
        ->toBe([$empateB->id, $empateA->id, $viejo->id]);
});

it('no devuelve nunca los viajes borrados', function () {
    $vivo = Trip::factory()->create();
    Trip::factory()->trashed()->create();

    expect(tripService()->getTrips(tripServiceUser(UserRole::Administrator), [])->pluck('id')->all())->toBe([$vivo->id]);
});

it('filtra por estado, por identificadores y por rango de fechas', function () {
    $admin = tripServiceUser(UserRole::Administrator);
    $enRuta = Trip::factory()->inRoute()->create(['recolection_date' => now()->addDays(5)]);
    Trip::factory()->create(['recolection_date' => now()->addDays(15)]);

    $service = tripService();

    expect($service->getTrips($admin, ['status' => 'in_route'])->pluck('id')->all())->toBe([$enRuta->id])
        ->and($service->getTrips($admin, ['clientId' => (string) $enRuta->client_id])->pluck('id')->all())->toBe([$enRuta->id])
        ->and($service->getTrips($admin, ['shippingLineId' => (string) $enRuta->shipping_line_id])->pluck('id')->all())->toBe([$enRuta->id])
        ->and($service->getTrips($admin, ['locationId' => (string) $enRuta->location_id])->pluck('id')->all())->toBe([$enRuta->id])
        ->and($service->getTrips($admin, ['pilotId' => (string) $enRuta->pilot_id])->pluck('id')->all())->toBe([$enRuta->id])
        ->and($service->getTrips($admin, ['vehicleId' => (string) $enRuta->vehicle_id])->pluck('id')->all())->toBe([$enRuta->id])
        ->and($service->getTrips($admin, ['dateTo' => now()->addDays(6)->format('Y-m-d')])->pluck('id')->all())->toBe([$enRuta->id])
        ->and($service->getTrips($admin, ['dateFrom' => now()->addDays(10)->format('Y-m-d')])->pluck('id')->all())->not->toContain($enRuta->id);
});

it('busca por la orden y por el contenedor normalizando el término', function (string $search) {
    $admin = tripServiceUser(UserRole::Administrator);
    $buscado = Trip::factory()->create(['order' => 'ORD-2026-0001', 'container' => 'MSKU 123456 7']);
    Trip::factory()->create(['order' => 'ORD-2026-9999', 'container' => 'TCLU 777777 3']);

    expect(tripService()->getTrips($admin, ['search' => $search])->pluck('id')->all())->toBe([$buscado->id]);
})->with([
    'orden en minúsculas' => 'ord-2026-0001',
    'trozo de la orden' => '2026-00',
    'contenedor en minúsculas' => 'msku',
    'contenedor con espacios de sobra' => '  msku   123456 ',
]);

it('ignora un valor inválido en cualquiera de los filtros', function (array $filtros) {
    $admin = tripServiceUser(UserRole::Administrator);
    Trip::factory()->count(3)->create();

    expect(tripService()->getTrips($admin, $filtros))->toHaveCount(3);
})->with([
    'status inventado' => [['status' => 'cancelled']],
    'status en mayúsculas' => [['status' => 'PENDING']],
    'clientId no numérico' => [['clientId' => 'abc']],
    'pilotId vacío' => [['pilotId' => '']],
    'dateFrom malformada' => [['dateFrom' => 'ayer']],
    'dateTo en otro formato' => [['dateTo' => '31-12-2026']],
    'search en blanco' => [['search' => '   ']],
]);

/*
|--------------------------------------------------------------------------
| getTripById()
|--------------------------------------------------------------------------
*/

it('devuelve el viaje por id con sus relaciones cargadas', function () {
    $trip = Trip::factory()->create();

    $encontrado = tripService()->getTripById(tripServiceUser(UserRole::Administrator), $trip->id);

    expect($encontrado->id)->toBe($trip->id)
        ->and($encontrado->relationLoaded('client'))->toBeTrue()
        ->and($encontrado->relationLoaded('registeredBy'))->toBeTrue();
});

it('lanza NotFoundError con un id inexistente', function () {
    tripService()->getTripById(tripServiceUser(UserRole::Administrator), 999999);
})->throws(NotFoundError::class, 'El viaje no existe');

it('lanza NotFoundError, y no BadRequestError, con un viaje borrado', function () {
    $trip = Trip::factory()->trashed()->create();

    tripService()->getTripById(tripServiceUser(UserRole::Administrator), $trip->id);
})->throws(NotFoundError::class, 'El viaje no existe');

it('lanza ForbiddenError cuando el viaje es de otra empresa', function () {
    $empresaB = tripServiceTeam();
    $trip = Trip::factory()->assigned()->create();

    tripService()->getTripById($empresaB['owner'], $trip->id);
})->throws(ForbiddenError::class, 'No puedes acceder a un viaje que no pertenece a tu empresa transportista');

it('lanza ForbiddenError cuando el viaje no es del piloto', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create();

    tripService()->getTripById($team['pilot'], $trip->id);
})->throws(ForbiddenError::class, 'No puedes acceder a un viaje que no tienes asignado');

/*
|--------------------------------------------------------------------------
| update()
|--------------------------------------------------------------------------
*/

it('actualiza solo las claves recibidas normalizando las referencias', function () {
    $trip = Trip::factory()->create(['order' => 'ORD-2026-0001', 'destination' => 'Miami, Estados Unidos']);

    $actualizado = tripService()->update($trip->id, ['order' => '  ord-2026   9999 ']);

    expect($actualizado->order)->toBe('ORD-2026 9999')
        ->and($actualizado->destination)->toBe('Miami, Estados Unidos');
});

it('trata un payload vacío como un no-op que devuelve el viaje intacto', function () {
    $trip = Trip::factory()->create(['order' => 'ORD-2026-0001']);
    $updatedAt = $trip->updated_at;

    $actualizado = tripService()->update($trip->id, []);

    expect($actualizado->order)->toBe('ORD-2026-0001')
        ->and($actualizado->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('nunca escribe la tripulación ni las dos autorías desde el update', function () {
    $admin = tripServiceUser(UserRole::Administrator);
    $empresaA = tripServiceTeam();
    $empresaB = tripServiceTeam();

    $trip = Trip::factory()->create([
        'pilot_id' => $empresaA['pilot']->id,
        'vehicle_id' => $empresaA['vehicle']->id,
        'assigned_by' => $empresaA['owner']->id,
        'registered_by' => $admin->id,
    ]);

    $actualizado = tripService()->update($trip->id, [
        'pilotId' => $empresaB['pilot']->id,
        'vehicleId' => $empresaB['vehicle']->id,
        'assignedBy' => $empresaB['owner']->id,
        'registeredBy' => $empresaB['owner']->id,
    ]);

    expect($actualizado->pilot_id)->toBe($empresaA['pilot']->id)
        ->and($actualizado->vehicle_id)->toBe($empresaA['vehicle']->id)
        ->and($actualizado->assigned_by)->toBe($empresaA['owner']->id)
        ->and($actualizado->registered_by)->toBe($admin->id);
});

it('reescribe los tres campos de la ruta cuando llegan juntos', function () {
    $trip = Trip::factory()->create([
        'polyline' => TripFactory::POLYLINE,
        'estimated_kilometers' => 10.00,
        'estimated_hours' => 0.50,
    ]);

    $actualizado = tripService()->update($trip->id, [
        'polyline' => '_p~iF~ps|U',
        'estimatedKilometers' => 250.5,
        'estimatedHours' => 4,
    ]);

    expect($actualizado->polyline)->toBe('_p~iF~ps|U')
        ->and($actualizado->fresh()->estimated_kilometers)->toBe('250.50')
        ->and($actualizado->fresh()->estimated_hours)->toBe('4.00');
});

it('no toca las estimaciones cuando el payload no trae la ruta', function () {
    $trip = Trip::factory()->create([
        'estimated_kilometers' => 104.32,
        'estimated_hours' => 1.75,
    ]);

    $actualizado = tripService()->update($trip->id, ['destination' => 'Algeciras, España']);

    expect($actualizado->destination)->toBe('Algeciras, España')
        ->and($actualizado->fresh()->estimated_kilometers)->toBe('104.32')
        ->and($actualizado->fresh()->estimated_hours)->toBe('1.75');
});

it('mueve el estado hacia atrás sin tocar las fechas de ejecución', function () {
    $trip = Trip::factory()->finished()->create();

    $actualizado = tripService()->update($trip->id, ['status' => TripStatus::Pending->value]);

    expect($actualizado->status)->toBe(TripStatus::Pending)
        ->and($actualizado->start_date)->not->toBeNull()
        ->and($actualizado->end_date)->not->toBeNull();
});

it('revalida los catálogos aunque el payload solo mueva una fecha', function () {
    $puerto = Location::factory()->port()->active()->create();
    $trip = Trip::factory()->create(['location_id' => $puerto->id]);

    $puerto->update(['status' => false]);

    tripService()->update($trip->id, ['recolectionDate' => now()->addDays(9)->format('Y-m-d H:i:s')]);
})->throws(BadRequestError::class, 'El puerto de destino está inactivo');

it('valida el catálogo que llega en el payload por encima del almacenado', function () {
    $trip = Trip::factory()->create();

    tripService()->update($trip->id, [
        'clientId' => Client::factory()->trashed()->create()->id,
    ]);
})->throws(BadRequestError::class, 'El cliente seleccionado fue eliminado');

it('lanza NotFoundError al actualizar un id inexistente', function () {
    tripService()->update(999999, ['order' => 'ord-2026-9999']);
})->throws(NotFoundError::class, 'El viaje no existe');

it('lanza BadRequestError al actualizar un viaje borrado', function () {
    tripService()->update(Trip::factory()->trashed()->create()->id, ['order' => 'ord-2026-9999']);
})->throws(BadRequestError::class, 'El viaje ya fue eliminado');

/*
|--------------------------------------------------------------------------
| destroy()
|--------------------------------------------------------------------------
*/

it('borra el viaje de forma lógica dejando la fila en la tabla', function () {
    $trip = Trip::factory()->create();

    $borrado = tripService()->destroy($trip->id);

    expect($borrado->deleted_at)->not->toBeNull()
        ->and(Trip::query()->find($trip->id))->toBeNull()
        ->and(Trip::withTrashed()->find($trip->id))->not->toBeNull();
});

it('lanza NotFoundError al borrar un id inexistente', function () {
    tripService()->destroy(999999);
})->throws(NotFoundError::class, 'El viaje no existe');

it('lanza BadRequestError en el segundo borrado: no es idempotente', function () {
    $trip = Trip::factory()->create();

    tripService()->destroy($trip->id);
    tripService()->destroy($trip->id);
})->throws(BadRequestError::class, 'El viaje ya fue eliminado');

/*
|--------------------------------------------------------------------------
| assign()
|--------------------------------------------------------------------------
*/

it('escribe piloto, vehículo y autor de la asignación juntos', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create();

    $asignado = tripService()->assign($team['owner'], $trip->id, [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
    ]);

    expect($asignado->pilot_id)->toBe($team['pilot']->id)
        ->and($asignado->vehicle_id)->toBe($team['vehicle']->id)
        ->and($asignado->assigned_by)->toBe($team['owner']->id)
        /** Asignar no arranca el viaje. */
        ->and($asignado->status)->toBe(TripStatus::Pending)
        ->and($asignado->start_date)->toBeNull();
});

it('lanza ForbiddenError cuando quien asigna no tiene empresa', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create();

    tripService()->assign(tripServiceUser(UserRole::Carrier), $trip->id, [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
    ]);
})->throws(ForbiddenError::class, 'Necesitas pertenecer a una empresa transportista para asignar un viaje');

it('lanza ForbiddenError cuando el viaje ya lo tomó otra empresa', function () {
    $empresaA = tripServiceTeam();
    $empresaB = tripServiceTeam();

    $trip = Trip::factory()->create([
        'pilot_id' => $empresaA['pilot']->id,
        'vehicle_id' => $empresaA['vehicle']->id,
        'assigned_by' => $empresaA['owner']->id,
    ]);

    tripService()->assign($empresaB['owner'], $trip->id, [
        'pilotId' => $empresaB['pilot']->id,
        'vehicleId' => $empresaB['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
    ]);
})->throws(ForbiddenError::class, 'No puedes asignar un viaje que ya tomó otra empresa transportista');

it('lanza BadRequestError al reasignar un viaje que ya no está pendiente', function (TripStatus $status) {
    $team = tripServiceTeam();

    $trip = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
        'status' => $status,
        'start_date' => now()->subHours(3),
    ]);

    tripService()->assign($team['owner'], $trip->id, [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
    ]);
})->with([
    'en ruta' => TripStatus::InRoute,
    'finalizado' => TripStatus::Finished,
])->throws(BadRequestError::class, 'Solo se puede asignar un viaje pendiente');

it('lanza BadRequestError cuando el usuario elegido no es piloto', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create();

    tripService()->assign($team['owner'], $trip->id, [
        'pilotId' => $team['owner']->id,
        'vehicleId' => $team['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
    ]);
})->throws(BadRequestError::class, 'El usuario seleccionado no es un piloto');

it('lanza BadRequestError cuando el piloto no pertenece a ninguna empresa', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create();

    tripService()->assign($team['owner'], $trip->id, [
        'pilotId' => tripServiceUser(UserRole::Pilot)->id,
        'vehicleId' => $team['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
    ]);
})->throws(BadRequestError::class, 'El piloto seleccionado no pertenece a ninguna empresa transportista');

it('lanza BadRequestError cuando el vehículo no está activo', function (VehicleStatus $status) {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create();

    tripService()->assign($team['owner'], $trip->id, [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => Vehicle::factory()->create(['carrier_id' => $team['carrier']->id, 'status' => $status])->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
    ]);
})->with([
    'inactivo' => VehicleStatus::Inactive,
    'en reparación' => VehicleStatus::UnderRepair,
])->throws(BadRequestError::class, 'El vehículo seleccionado no está activo');

it('lanza BadRequestError cuando el piloto y el vehículo son de empresas distintas', function () {
    $empresaA = tripServiceTeam();
    $empresaB = tripServiceTeam();
    $trip = Trip::factory()->create();

    tripService()->assign($empresaA['owner'], $trip->id, [
        'pilotId' => $empresaA['pilot']->id,
        'vehicleId' => $empresaB['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
    ]);
})->throws(BadRequestError::class, 'El piloto y el vehículo deben pertenecer a la misma empresa transportista');

it('lanza NotFoundError al asignar un id inexistente y BadRequestError sobre uno borrado', function () {
    $team = tripServiceTeam();
    $data = ['pilotId' => $team['pilot']->id, 'vehicleId' => $team['vehicle']->id, 'fuelGallons' => 45.5, 'fuelType' => 'diesel'];
    $borrado = Trip::factory()->trashed()->create();

    expect(fn () => tripService()->assign($team['owner'], 999999, $data))
        ->toThrow(NotFoundError::class, 'El viaje no existe')
        ->and(fn () => tripService()->assign($team['owner'], $borrado->id, $data))
        ->toThrow(BadRequestError::class, 'El viaje ya fue eliminado');
});

/*
|--------------------------------------------------------------------------
| start() y finish()
|--------------------------------------------------------------------------
*/

it('no toca las estimaciones al asignar, arrancar ni cerrar el viaje', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create(['estimated_kilometers' => 104.32, 'estimated_hours' => 1.75]);

    tripService()->assign($team['owner'], $trip->id, [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
    ]);
    TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id]);
    tripService()->start($team['pilot'], $trip->id);
    tripService()->finish($team['pilot'], $trip->id);

    expect($trip->fresh()->estimated_kilometers)->toBe('104.32')
        ->and($trip->fresh()->estimated_hours)->toBe('1.75');
});

it('arranca el viaje con la hora del servidor y lo pone en ruta', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
    ]);

    /** Desde SPEC 27 el arranque exige al menos una carga confirmada. */
    TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id]);

    $antes = now()->subSecond();
    $iniciado = tripService()->start($team['pilot'], $trip->id);

    expect($iniciado->status)->toBe(TripStatus::InRoute)
        ->and($iniciado->start_date)->not->toBeNull()
        ->and($iniciado->start_date->greaterThanOrEqualTo($antes))->toBeTrue()
        ->and($iniciado->end_date)->toBeNull();
});

it('lanza ForbiddenError cuando quien arranca no es el piloto asignado', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
    ]);

    tripService()->start(tripServiceUser(UserRole::Pilot), $trip->id);
})->throws(ForbiddenError::class, 'No puedes iniciar un viaje que no tienes asignado');

it('lanza ForbiddenError al arrancar un viaje sin piloto asignado', function () {
    tripService()->start(tripServiceUser(UserRole::Pilot), Trip::factory()->create()->id);
})->throws(ForbiddenError::class, 'No puedes iniciar un viaje que no tienes asignado');

it('lanza BadRequestError en el segundo arranque', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
        'start_date' => now()->subHours(2),
        'status' => TripStatus::InRoute,
    ]);

    tripService()->start($team['pilot'], $trip->id);
})->throws(BadRequestError::class, 'El viaje ya fue iniciado');

it('cierra el viaje con la hora del servidor y lo deja finalizado', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
        'start_date' => now()->subHours(4),
        'status' => TripStatus::InRoute,
    ]);

    $finalizado = tripService()->finish($team['pilot'], $trip->id);

    expect($finalizado->status)->toBe(TripStatus::Finished)
        ->and($finalizado->end_date)->not->toBeNull()
        ->and($finalizado->end_date->lessThanOrEqualTo(now()->addSecond()))->toBeTrue();
});

it('lanza BadRequestError al cerrar un viaje que nunca arrancó', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
    ]);

    tripService()->finish($team['pilot'], $trip->id);
})->throws(BadRequestError::class, 'El viaje no ha sido iniciado');

it('lanza BadRequestError en el segundo cierre', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
        'start_date' => now()->subHours(4),
        'end_date' => now()->subHour(),
        'status' => TripStatus::Finished,
    ]);

    tripService()->finish($team['pilot'], $trip->id);
})->throws(BadRequestError::class, 'El viaje ya fue finalizado');

it('lanza ForbiddenError cuando quien cierra no es el piloto asignado', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
        'start_date' => now()->subHours(4),
        'status' => TripStatus::InRoute,
    ]);

    tripService()->finish(tripServiceUser(UserRole::Pilot), $trip->id);
})->throws(ForbiddenError::class, 'No puedes finalizar un viaje que no tienes asignado');

it('lanza NotFoundError al arrancar o cerrar un id inexistente', function () {
    $piloto = tripServiceUser(UserRole::Pilot);

    expect(fn () => tripService()->start($piloto, 999999))->toThrow(NotFoundError::class, 'El viaje no existe')
        ->and(fn () => tripService()->finish($piloto, 999999))->toThrow(NotFoundError::class, 'El viaje no existe');
});

it('lanza BadRequestError al arrancar o cerrar un viaje borrado', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->trashed()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
        'start_date' => now()->subHours(4),
    ]);

    expect(fn () => tripService()->start($team['pilot'], $trip->id))->toThrow(BadRequestError::class, 'El viaje ya fue eliminado')
        ->and(fn () => tripService()->finish($team['pilot'], $trip->id))->toThrow(BadRequestError::class, 'El viaje ya fue eliminado');
});

/*
|--------------------------------------------------------------------------
| getCurrentTrip()
|--------------------------------------------------------------------------
*/

/**
 * A trip in the hands of the given team's pilot, in the given status.
 *
 * @param  array{carrier: Carrier, owner: User, pilot: User, vehicle: Vehicle}  $team
 * @param  array<string, mixed>  $attributes
 */
function tripServiceDriving(array $team, array $attributes = []): Trip
{
    return Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
        'status' => TripStatus::InRoute,
        'start_date' => now()->subHours(2),
        ...$attributes,
    ]);
}

it('devuelve el viaje en ruta del piloto', function () {
    $team = tripServiceTeam();
    $trip = tripServiceDriving($team);

    expect(tripService()->getCurrentTrip($team['pilot'])?->id)->toBe($trip->id);
});

it('devuelve null cuando el viaje del piloto no está en ruta', function (TripStatus $status) {
    $team = tripServiceTeam();
    tripServiceDriving($team, ['status' => $status]);

    expect(tripService()->getCurrentTrip($team['pilot']))->toBeNull();
})->with([
    'pending' => TripStatus::Pending,
    'finished' => TripStatus::Finished,
]);

it('devuelve null aunque el viaje conserve su fecha de arranque, porque mira el status', function () {
    $team = tripServiceTeam();

    /** Justo lo que deja el PATCH del administrador: sin máquina de estados, la fecha se queda. */
    tripServiceDriving($team, ['status' => TripStatus::Pending, 'start_date' => now()->subHours(3)]);

    expect(tripService()->getCurrentTrip($team['pilot']))->toBeNull();
});

it('no devuelve el viaje en ruta de otro piloto', function () {
    $team = tripServiceTeam();
    tripServiceDriving($team);

    expect(tripService()->getCurrentTrip(tripServiceUser(UserRole::Pilot)))->toBeNull();
});

it('devuelve null cuando el viaje en ruta del piloto fue borrado', function () {
    $team = tripServiceTeam();
    tripServiceDriving($team)->delete();

    expect(tripService()->getCurrentTrip($team['pilot']))->toBeNull();
});

it('devuelve el viaje en ruta más reciente cuando el piloto tiene dos', function () {
    $team = tripServiceTeam();

    $antiguo = tripServiceDriving($team, ['start_date' => now()->subDay()]);
    $reciente = tripServiceDriving($team, ['start_date' => now()->subMinutes(10)]);

    expect(tripService()->getCurrentTrip($team['pilot'])?->id)->toBe($reciente->id)
        ->and($antiguo->fresh()->status)->toBe(TripStatus::InRoute);
});

it('devuelve null para un usuario que no es piloto de ningún viaje', function () {
    $team = tripServiceTeam();
    tripServiceDriving($team);

    expect(tripService()->getCurrentTrip(tripServiceUser(UserRole::Administrator)))->toBeNull();
});

it('carga las seis relaciones del listado y ninguna más', function () {
    $team = tripServiceTeam();
    tripServiceDriving($team);

    $trip = tripService()->getCurrentTrip($team['pilot']);

    expect(array_keys($trip->getRelations()))->toEqualCanonicalizing([
        'shippingLine', 'departurePoint', 'location', 'pilot', 'vehicle', 'registeredBy',
    ]);
});

/*
|--------------------------------------------------------------------------
| SPEC 27 — La carga que escribe assign() y la guarda de start()
|--------------------------------------------------------------------------
*/

it('inserta la primera carga sin confirmar dentro de la transacción de la asignación', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create();

    $asignado = tripService()->assign($team['owner'], $trip->id, [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
    ]);

    $fuel = TripFuel::query()->where('trip_id', '=', $trip->id)->sole();

    expect(TripFuel::count())->toBe(1)
        ->and($fuel->gallons)->toBe('45.50')
        ->and($fuel->fuel_type)->toBe(FuelType::Diesel)
        ->and($fuel->loaded_at)->toBeNull()
        ->and($fuel->confirmed_by)->toBeNull()
        /** El autor de la primera carga es quien asignó, no el piloto. */
        ->and($fuel->registered_by)->toBe($team['owner']->id)
        /**
         * La suma solo cuenta lo confirmado: sin ninguna carga confirmada el withSum no
         * devuelve cero sino null, y es el Resource el que lo pinta como "0.00".
         */
        ->and($asignado->total_fuel_gallons)->toBeNull();
});

it('añade otra carga al reasignar, sin pisar ni borrar la anterior', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create();

    $otroPiloto = tripServiceUser(UserRole::Pilot);
    $team['carrier']->pilots()->attach($otroPiloto);

    $data = [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
    ];

    tripService()->assign($team['owner'], $trip->id, $data);
    tripService()->assign($team['owner'], $trip->id, [...$data, 'pilotId' => $otroPiloto->id, 'fuelGallons' => 12.25]);

    expect(TripFuel::query()->where('trip_id', '=', $trip->id)->orderBy('id')->pluck('gallons')->all())
        ->toBe(['45.50', '12.25']);
});

it('no deja ninguna carga cuando la asignación se cae por una de sus guardas', function () {
    $empresaA = tripServiceTeam();
    $empresaB = tripServiceTeam();
    $trip = Trip::factory()->create();

    expect(fn () => tripService()->assign($empresaA['owner'], $trip->id, [
        'pilotId' => $empresaA['pilot']->id,
        'vehicleId' => $empresaB['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
    ]))->toThrow(BadRequestError::class);

    expect(TripFuel::count())->toBe(0)
        ->and($trip->fresh()->assigned_by)->toBeNull();
});

it('lanza BadRequestError al arrancar sin ninguna carga confirmada', function (bool $conCargaSinConfirmar) {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
    ]);

    if ($conCargaSinConfirmar) {
        TripFuel::factory()->create(['trip_id' => $trip->id]);
    }

    expect(fn () => tripService()->start($team['pilot'], $trip->id))
        ->toThrow(BadRequestError::class, 'Debes confirmar al menos una carga de combustible antes de iniciar el viaje');

    $fresco = $trip->fresh();

    expect($fresco->status)->toBe(TripStatus::Pending)
        ->and($fresco->start_date)->toBeNull();
})->with([
    'sin ninguna carga' => false,
    'con una carga sin confirmar' => true,
]);

it('devuelve la suma de las cargas confirmadas en el detalle del viaje', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
    ]);

    TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id, 'gallons' => 30.25]);
    TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id, 'gallons' => 12.75]);
    TripFuel::factory()->create(['trip_id' => $trip->id, 'gallons' => 100]);

    $detalle = tripService()->getTripById(tripServiceUser(UserRole::Administrator), $trip->id);

    expect((float) $detalle->total_fuel_gallons)->toBe(43.0);
});

/*
|--------------------------------------------------------------------------
| SPEC 28 — Polilínea del recorrido real
|--------------------------------------------------------------------------
*/

/**
 * Plant the given `[lat, lng]` points as the trip's trail, fifteen seconds apart.
 *
 * @param  list<array{0: float, 1: float}>  $points
 */
function tripServiceSeedTrail(Trip $trip, array $points): void
{
    $from = now()->subMinutes(10);

    foreach ($points as $index => [$latitude, $longitude]) {
        TripPosition::factory()->create([
            'trip_id' => $trip->id,
            'pilot_id' => $trip->pilot_id,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'recorded_at' => $from->copy()->addSeconds(15 * $index),
        ]);
    }
}

it('codifica el rastro completo en traveled_polyline al cerrar el viaje', function () {
    $team = tripServiceTeam();
    $trip = tripServiceDriving($team);
    $points = [[14.6248, -90.5152], [14.6231, -90.5148], [13.9276, -90.7853]];

    tripServiceSeedTrail($trip, $points);

    $finalizado = tripService()->finish($team['pilot'], $trip->id);

    expect($finalizado->traveled_polyline)->toBe(PolylineEncoder::encode($points))
        ->and(PolylineDecoder::decode($finalizado->traveled_polyline))->toBe($points)
        ->and($finalizado->end_date)->not->toBeNull()
        ->and($finalizado->status)->toBe(TripStatus::Finished);
});

it('deja traveled_polyline en null al cerrar un viaje sin puntos', function () {
    $team = tripServiceTeam();
    $trip = tripServiceDriving($team);

    $finalizado = tripService()->finish($team['pilot'], $trip->id);

    expect($finalizado->traveled_polyline)->toBeNull()
        ->and($finalizado->status)->toBe(TripStatus::Finished);
});

it('codifica solo los puntos del viaje que se cierra', function () {
    $team = tripServiceTeam();
    $trip = tripServiceDriving($team);
    $otro = tripServiceDriving($team);

    tripServiceSeedTrail($trip, [[14.6248, -90.5152]]);
    tripServiceSeedTrail($otro, [[15.5, -91.5], [15.6, -91.6]]);

    $finalizado = tripService()->finish($team['pilot'], $trip->id);

    expect(PolylineDecoder::decode($finalizado->traveled_polyline))->toBe([[14.6248, -90.5152]])
        ->and($otro->fresh()->traveled_polyline)->toBeNull();
});

it('no escribe traveled_polyline desde update', function () {
    $trip = Trip::factory()->create();

    $editado = tripService()->update($trip->id, [
        'traveledPolyline' => TripFactory::POLYLINE,
        'traveled_polyline' => TripFactory::POLYLINE,
    ]);

    expect($editado->traveled_polyline)->toBeNull()
        ->and($trip->fresh()->traveled_polyline)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| SPEC 32 — Distancia y tiempo reales del viaje
|--------------------------------------------------------------------------
*/

it('suma la distancia Haversine de los segmentos consecutivos del rastro en kilómetros con dos decimales', function () {
    $team = tripServiceTeam();
    $trip = tripServiceDriving($team);
    /** Ciudad de Guatemala → Escuintla → Puerto Quetzal: tres puntos, dos segmentos. */
    $points = [[14.6248, -90.5152], [14.3050, -90.7850], [13.9276, -90.7853]];

    tripServiceSeedTrail($trip, $points);

    $finalizado = tripService()->finish($team['pilot'], $trip->id);

    $esperado = DistanceCalculator::metersBetween(14.6248, -90.5152, 14.3050, -90.7850)
        + DistanceCalculator::metersBetween(14.3050, -90.7850, 13.9276, -90.7853);

    expect($finalizado->fresh()->traveled_kilometers)->toBe(number_format($esperado / 1000, 2, '.', ''))
        /** Orden de magnitud: unos 90 km, no la distancia directa entre extremos. */
        ->and((float) $finalizado->fresh()->traveled_kilometers)->toBeGreaterThan(85.0)
        ->and((float) $finalizado->fresh()->traveled_kilometers)->toBeLessThan(95.0);
});

it('suma el rastro en crudo, sin descartar los segmentos por debajo del umbral de movimiento', function () {
    $team = tripServiceTeam();
    $trip = tripServiceDriving($team);
    /** Un punto repetido (0 m) y un salto de ~3 m: menos del umbral de 5 m de las paradas. */
    $points = [[14.6248, -90.5152], [14.6248, -90.5152], [14.62482, -90.5152], [14.7248, -90.5152]];

    tripServiceSeedTrail($trip, $points);

    $finalizado = tripService()->finish($team['pilot'], $trip->id);

    $esperado = DistanceCalculator::metersBetween(14.6248, -90.5152, 14.62482, -90.5152)
        + DistanceCalculator::metersBetween(14.62482, -90.5152, 14.7248, -90.5152);

    expect($finalizado->fresh()->traveled_kilometers)->toBe(number_format($esperado / 1000, 2, '.', ''));
});

it('persiste 0.00 y no null en traveled_kilometers al cerrar un viaje sin puntos o con uno solo', function (array $points) {
    $team = tripServiceTeam();
    $trip = tripServiceDriving($team);

    tripServiceSeedTrail($trip, $points);

    $finalizado = tripService()->finish($team['pilot'], $trip->id);

    expect($finalizado->fresh()->traveled_kilometers)->toBe('0.00')
        ->and($finalizado->fresh()->traveled_hours)->not->toBeNull();
})->with([
    'sin puntos' => [[]],
    'un solo punto' => [[[14.6248, -90.5152]]],
]);

it('calcula traveled_hours como end_date menos start_date en horas decimales', function () {
    $this->travelTo(now()->setTime(10, 0));

    $team = tripServiceTeam();
    $trip = tripServiceDriving($team, ['start_date' => now()->subHours(2)->subMinutes(30)]);

    $finalizado = tripService()->finish($team['pilot'], $trip->id);

    expect($finalizado->fresh()->traveled_hours)->toBe('2.50')
        ->and($finalizado->fresh()->end_date->equalTo(now()))->toBeTrue();
});

it('redondea traveled_hours a dos decimales', function () {
    $this->travelTo(now()->setTime(10, 0));

    $team = tripServiceTeam();
    /** 1 h 20 min = 1.3333… h → 1.33 */
    $trip = tripServiceDriving($team, ['start_date' => now()->subHours(1)->subMinutes(20)]);

    $finalizado = tripService()->finish($team['pilot'], $trip->id);

    expect($finalizado->fresh()->traveled_hours)->toBe('1.33');
});

it('ejecuta una sola consulta a trip_positions por finish', function () {
    $team = tripServiceTeam();
    $trip = tripServiceDriving($team);
    tripServiceSeedTrail($trip, [[14.6248, -90.5152], [14.6231, -90.5148], [13.9276, -90.7853]]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    tripService()->finish($team['pilot'], $trip->id);

    $consultas = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'trip_positions'));

    DB::disableQueryLog();

    expect($consultas)->toHaveCount(1);
});

it('recalcula y sobrescribe las dos métricas en un segundo finish', function () {
    $this->travelTo(now()->setTime(8, 0));

    $team = tripServiceTeam();
    $trip = tripServiceDriving($team, ['start_date' => now()->subHour()]);
    tripServiceSeedTrail($trip, [[14.6248, -90.5152], [14.6231, -90.5148]]);

    $primero = tripService()->finish($team['pilot'], $trip->id)->fresh();

    /** El hueco de SPEC 24: el administrador devuelve el viaje a in_route sin limpiar nada. */
    Trip::query()->whereKey($trip->id)->update(['status' => TripStatus::InRoute, 'end_date' => null]);
    $this->travelTo(now()->addHours(3));
    tripServiceSeedTrail($trip, [[13.9276, -90.7853]]);

    $segundo = tripService()->finish($team['pilot'], $trip->id)->fresh();

    expect($primero->traveled_hours)->toBe('1.00')
        ->and($segundo->traveled_hours)->toBe('4.00')
        ->and((float) $segundo->traveled_kilometers)->toBeGreaterThan((float) $primero->traveled_kilometers);
});

it('no escribe traveled_kilometers ni traveled_hours desde update', function () {
    $trip = Trip::factory()->create();

    $editado = tripService()->update($trip->id, [
        'traveledKilometers' => 111.4,
        'traveled_kilometers' => 111.4,
        'traveledHours' => 2.1,
        'traveled_hours' => 2.1,
    ]);

    expect($editado->traveled_kilometers)->toBeNull()
        ->and($editado->traveled_hours)->toBeNull()
        ->and($trip->fresh()->traveled_kilometers)->toBeNull()
        ->and($trip->fresh()->traveled_hours)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| SPEC 31 — El viático opcional que escribe assign() y la suma del detalle
|--------------------------------------------------------------------------
*/

it('inserta el primer viático sin confirmar cuando la asignación trae expenseAmount', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create();

    $asignado = tripService()->assign($team['owner'], $trip->id, [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
        'expenseAmount' => 350.5,
        'expenseDescription' => 'Alimentación y peajes',
    ]);

    $expense = TripExpense::query()->where('trip_id', '=', $trip->id)->sole();

    expect(TripExpense::count())->toBe(1)
        ->and($expense->amount)->toBe('350.50')
        ->and($expense->description)->toBe('Alimentación y peajes')
        ->and($expense->received_at)->toBeNull()
        ->and($expense->confirmed_by)->toBeNull()
        /** El autor del primer viático es quien asignó, no el piloto. */
        ->and($expense->registered_by)->toBe($team['owner']->id)
        /** Sin ninguno confirmado el withSum devuelve null, y el Resource lo pinta como "0.00". */
        ->and($asignado->total_expenses_amount)->toBeNull();
});

it('no inserta ningún viático cuando la asignación no trae expenseAmount', function (array $extra) {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create();

    tripService()->assign($team['owner'], $trip->id, [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
        ...$extra,
    ]);

    /** La carga sí; el viático no. Una descripción sola se ignora en silencio. */
    expect(TripFuel::count())->toBe(1)
        ->and(TripExpense::count())->toBe(0)
        ->and($trip->fresh()->assigned_by)->toBe($team['owner']->id);
})->with([
    'sin los dos campos' => [[]],
    'expenseAmount en null' => [['expenseAmount' => null]],
    'solo expenseDescription' => [['expenseDescription' => 'Peajes']],
]);

it('añade otro viático al reasignar con monto, sin pisar ni borrar el anterior', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create();

    $otroPiloto = tripServiceUser(UserRole::Pilot);
    $team['carrier']->pilots()->attach($otroPiloto);

    $data = [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
        'expenseAmount' => 350,
    ];

    tripService()->assign($team['owner'], $trip->id, $data);
    tripService()->assign($team['owner'], $trip->id, [...$data, 'pilotId' => $otroPiloto->id, 'expenseAmount' => 125.25]);

    expect(TripExpense::query()->where('trip_id', '=', $trip->id)->orderBy('id')->pluck('amount')->all())
        ->toBe(['350.00', '125.25']);
});

it('no deja ningún viático cuando la asignación se cae por una de sus guardas', function () {
    $empresaA = tripServiceTeam();
    $empresaB = tripServiceTeam();
    $trip = Trip::factory()->create();

    expect(fn () => tripService()->assign($empresaA['owner'], $trip->id, [
        'pilotId' => $empresaA['pilot']->id,
        'vehicleId' => $empresaB['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
        'expenseAmount' => 350,
    ]))->toThrow(BadRequestError::class);

    expect(TripExpense::count())->toBe(0)
        ->and(TripFuel::count())->toBe(0)
        ->and($trip->fresh()->assigned_by)->toBeNull();
});

it('resuelve total_expenses_amount con los viáticos confirmados en el detalle', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
    ]);

    TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 300.25]);
    TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 124.75]);
    TripExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 1000]);

    $detalle = tripService()->getTripById(tripServiceUser(UserRole::Administrator), $trip->id);

    expect((float) $detalle->total_expenses_amount)->toBe(425.0);
});

it('arranca sin exigir ningún viático confirmado', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
    ]);

    TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id]);
    TripExpense::factory()->create(['trip_id' => $trip->id]);

    expect(tripService()->start($team['pilot'], $trip->id)->status)->toBe(TripStatus::InRoute);
});
