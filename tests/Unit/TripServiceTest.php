<?php

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
use App\Models\Location;
use App\Models\ShippingLine;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Trip\TripService;
use Database\Factories\TripFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
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
    return array_merge([
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
        'observations' => 'Cargar a primera hora',
    ], $overrides);
}

it('resuelve la implementación de viajes registrada en el provider', function () {
    expect(tripService())->toBeInstanceOf(TripService::class);
});

it('declara los ocho métodos del contrato', function () {
    expect(get_class_methods(TripServiceInterface::class))->toEqualCanonicalizing([
        'getTrips', 'getTripById', 'create', 'update', 'destroy', 'assign', 'start', 'finish',
    ]);
});

/*
|--------------------------------------------------------------------------
| Base de datos, modelo y enum
|--------------------------------------------------------------------------
*/

it('crea la tabla trips con sus columnas, incluida deleted_at', function () {
    expect(Schema::hasTable('trips'))->toBeTrue()
        ->and(Schema::getColumnListing('trips'))->toEqualCanonicalizing([
            'id', 'order', 'client_id', 'shipping_line_id', 'departure_point_id', 'location_id',
            'destination', 'container', 'transport',
            'recolection_date', 'ship_date', 'start_date', 'end_date',
            'polyline', 'observations', 'status',
            'pilot_id', 'vehicle_id', 'assigned_by', 'registered_by',
            'created_at', 'updated_at', 'deleted_at',
        ]);
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
    ]);
})->throws(BadRequestError::class, 'El usuario seleccionado no es un piloto');

it('lanza BadRequestError cuando el piloto no pertenece a ninguna empresa', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create();

    tripService()->assign($team['owner'], $trip->id, [
        'pilotId' => tripServiceUser(UserRole::Pilot)->id,
        'vehicleId' => $team['vehicle']->id,
    ]);
})->throws(BadRequestError::class, 'El piloto seleccionado no pertenece a ninguna empresa transportista');

it('lanza BadRequestError cuando el vehículo no está activo', function (VehicleStatus $status) {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create();

    tripService()->assign($team['owner'], $trip->id, [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => Vehicle::factory()->create(['carrier_id' => $team['carrier']->id, 'status' => $status])->id,
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
    ]);
})->throws(BadRequestError::class, 'El piloto y el vehículo deben pertenecer a la misma empresa transportista');

it('lanza NotFoundError al asignar un id inexistente y BadRequestError sobre uno borrado', function () {
    $team = tripServiceTeam();
    $data = ['pilotId' => $team['pilot']->id, 'vehicleId' => $team['vehicle']->id];
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

it('arranca el viaje con la hora del servidor y lo pone en ruta', function () {
    $team = tripServiceTeam();
    $trip = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
    ]);

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
