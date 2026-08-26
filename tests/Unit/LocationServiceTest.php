<?php

use App\Enums\LocationType;
use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\Location\LocationServiceInterface;
use App\Models\Location;
use App\Models\User;
use App\Services\Location\LocationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function locationService(): LocationServiceInterface
{
    return app(LocationServiceInterface::class);
}

function locationServiceAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * The payload the service expects, with the keys already in camelCase.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function locationServiceData(array $overrides = []): array
{
    return array_merge([
        'name' => 'bodega central',
        'type' => 'destination',
        'googlePlaceId' => 'ChIJd8BlQ2BZwokRAFUEcm_qrcA',
        'latitude' => 14.6349,
        'longitude' => -90.5069,
    ], $overrides);
}

it('resuelve la implementación de destinos registrada en el provider', function () {
    expect(locationService())->toBeInstanceOf(LocationService::class);
});

/*
|--------------------------------------------------------------------------
| Base de datos y modelo
|--------------------------------------------------------------------------
*/

it('crea la tabla locations con sus columnas', function () {
    expect(Schema::hasTable('locations'))->toBeTrue()
        ->and(Schema::getColumnListing('locations'))->toEqualCanonicalizing([
            'id',
            'name',
            'description',
            'type',
            'google_place_id',
            'latitude',
            'longitude',
            'status',
            'registered_by',
            'created_at',
            'updated_at',
        ]);
});

it('normaliza el nombre trimando, colapsando espacios y pasando a mayúsculas', function (string $entrada, string $esperado) {
    expect(Location::normalizeName($entrada))->toBe($esperado);
})->with([
    'con espacios de sobra' => ['  bodega   central ', 'BODEGA CENTRAL'],
    'ya normalizado' => ['BODEGA CENTRAL', 'BODEGA CENTRAL'],
    'con saltos de línea' => ["bodega\n\tcentral", 'BODEGA CENTRAL'],
    'vacío' => ['   ', ''],
]);

it('castea las coordenadas a decimal de ocho dígitos y el estado a booleano', function () {
    $location = Location::factory()->active()->create(['latitude' => 14.6349, 'longitude' => -90.5069])->fresh();

    expect($location->latitude)->toBe('14.63490000')
        ->and($location->longitude)->toBe('-90.50690000')
        ->and($location->status)->toBeBool()->toBeTrue();
});

it('deja en destination la fila insertada directamente sin tipo, por el default de la columna', function () {
    /** Inserción directa: es lo único contra lo que protege el default, porque el FormRequest ya exige el campo. */
    DB::table('locations')->insert([
        'name' => 'BODEGA MIGRADA',
        'google_place_id' => 'ChIJfilaVieja0001',
        'latitude' => 14.6349,
        'longitude' => -90.5069,
        'status' => true,
        'registered_by' => locationServiceAdmin()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migrada = Location::query()->where('name', '=', 'BODEGA MIGRADA')->first();

    expect($migrada->type)->toBe(LocationType::Destination)
        ->and($migrada->getRawOriginal('type'))->toBe('destination');
});

it('castea el tipo al enum y ofrece el estado port en la factory', function () {
    expect(Location::factory()->create()->fresh()->type)->toBe(LocationType::Destination)
        ->and(Location::factory()->port()->create()->fresh()->type)->toBe(LocationType::Port);
});

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('persiste el destino normalizando el nombre y dejándolo activo', function () {
    $admin = locationServiceAdmin();

    $location = locationService()->create($admin, locationServiceData(['name' => '  bodega   central ']));

    expect($location->name)->toBe('BODEGA CENTRAL')
        ->and($location->status)->toBeTrue()
        ->and($location->description)->toBeNull()
        ->and(Location::query()->whereKey($location->id)->exists())->toBeTrue();
});

it('guarda el googlePlaceId tal cual, sin normalizarlo', function () {
    $location = locationService()->create(locationServiceAdmin(), locationServiceData(['googlePlaceId' => 'ChIJd8Bl_Q2-BZ']));

    expect($location->google_place_id)->toBe('ChIJd8Bl_Q2-BZ');
});

it('guarda la descripción cuando llega y la deja en null cuando no', function () {
    $admin = locationServiceAdmin();

    $conDescripcion = locationService()->create($admin, locationServiceData(['description' => 'la principal']));
    $sinDescripcion = locationService()->create($admin, locationServiceData([
        'name' => 'bodega sur',
        'googlePlaceId' => 'ChIJabc0002',
    ]));

    expect($conDescripcion->description)->toBe('la principal')
        ->and($sinDescripcion->description)->toBeNull();
});

it('ignora el status del body: el destino nace siempre activo', function () {
    $location = locationService()->create(locationServiceAdmin(), locationServiceData(['status' => false]));

    expect($location->status)->toBeTrue();
});

it('registra al usuario recibido como responsable del alta', function () {
    $admin = locationServiceAdmin();

    $location = locationService()->create($admin, locationServiceData());

    expect($location->registered_by)->toBe($admin->id)
        ->and($location->registeredBy->name)->toBe($admin->name);
});

it('rechaza un nombre que ya existe aunque llegue en otra caja', function () {
    $admin = locationServiceAdmin();
    locationService()->create($admin, locationServiceData(['name' => 'BODEGA CENTRAL']));

    locationService()->create($admin, locationServiceData([
        'name' => 'Bodega   Central',
        'googlePlaceId' => 'ChIJabc0002',
    ]));
})->throws(BadRequestError::class, 'Ya existe un destino con ese nombre');

it('rechaza un googlePlaceId ya registrado nombrando al destino que lo ocupa', function () {
    $admin = locationServiceAdmin();
    locationService()->create($admin, locationServiceData(['name' => 'bodega central', 'googlePlaceId' => 'ChIJabc0001']));

    expect(fn () => locationService()->create($admin, locationServiceData([
        'name' => 'bodega sur',
        'googlePlaceId' => 'ChIJabc0001',
    ])))->toThrow(BadRequestError::class, 'El lugar seleccionado ya está registrado en el destino BODEGA CENTRAL');
});

it('acepta dos googlePlaceId que solo difieren en la caja', function () {
    $admin = locationServiceAdmin();
    locationService()->create($admin, locationServiceData(['name' => 'bodega central', 'googlePlaceId' => 'ChIJabc0001']));
    locationService()->create($admin, locationServiceData(['name' => 'bodega sur', 'googlePlaceId' => 'CHIJABC0001']));

    expect(Location::query()->count())->toBe(2);
});

it('persiste el tipo recibido tal cual llega', function (string $type, LocationType $esperado) {
    $location = locationService()->create(locationServiceAdmin(), locationServiceData(['type' => $type]));

    expect($location->type)->toBe($esperado)
        ->and($location->fresh()->type)->toBe($esperado);
})->with([
    'puerto' => ['port', LocationType::Port],
    'destino ordinario' => ['destination', LocationType::Destination],
]);

/*
|--------------------------------------------------------------------------
| Edición
|--------------------------------------------------------------------------
*/

it('no altera el resto de campos cuando el update solo cambia el nombre', function () {
    $location = Location::factory()->create([
        'name' => 'BODEGA CENTRAL',
        'description' => 'la de siempre',
        'google_place_id' => 'ChIJabc0001',
        'latitude' => 14.6349,
        'longitude' => -90.5069,
    ]);

    $updated = locationService()->update($location->id, ['name' => '  bodega   norte ']);

    expect($updated->name)->toBe('BODEGA NORTE')
        ->and($updated->description)->toBe('la de siempre')
        ->and($updated->google_place_id)->toBe('ChIJabc0001')
        ->and($updated->latitude)->toBe('14.63490000')
        ->and($updated->longitude)->toBe('-90.50690000');
});

it('corrige las coordenadas conservando el id y el lugar de Google', function () {
    $location = Location::factory()->create(['google_place_id' => 'ChIJabc0001']);

    $updated = locationService()->update($location->id, ['latitude' => 15.5, 'longitude' => -91.25]);

    expect($updated->id)->toBe($location->id)
        ->and($updated->latitude)->toBe('15.50000000')
        ->and($updated->longitude)->toBe('-91.25000000')
        ->and($updated->google_place_id)->toBe('ChIJabc0001');
});

it('borra la descripción cuando el update manda null', function () {
    $location = Location::factory()->create(['description' => 'la de siempre']);

    expect(locationService()->update($location->id, ['description' => null])->description)->toBeNull();
});

it('rechaza en la edición el nombre de otro destino y acepta el propio', function () {
    $location = Location::factory()->create(['name' => 'BODEGA CENTRAL']);

    expect(locationService()->update($location->id, ['name' => 'bodega central'])->name)->toBe('BODEGA CENTRAL');

    Location::factory()->create(['name' => 'BODEGA SUR']);

    expect(fn () => locationService()->update($location->id, ['name' => 'bodega sur']))
        ->toThrow(BadRequestError::class, 'Ya existe un destino con ese nombre');
});

it('rechaza en la edición el googlePlaceId de otro destino y acepta el propio', function () {
    $location = Location::factory()->create(['name' => 'BODEGA CENTRAL', 'google_place_id' => 'ChIJabc0001']);
    Location::factory()->create(['name' => 'BODEGA SUR', 'google_place_id' => 'ChIJabc0002']);

    expect(locationService()->update($location->id, ['googlePlaceId' => 'ChIJabc0001'])->google_place_id)
        ->toBe('ChIJabc0001')
        ->and(locationService()->update($location->id, ['googlePlaceId' => 'ChIJabc0003'])->google_place_id)
        ->toBe('ChIJabc0003');

    expect(fn () => locationService()->update($location->id, ['googlePlaceId' => 'ChIJabc0002']))
        ->toThrow(BadRequestError::class, 'El lugar seleccionado ya está registrado en el destino BODEGA SUR');
});

it('no reescribe al responsable del alta al editar', function () {
    $location = Location::factory()->create();

    expect(locationService()->update($location->id, ['name' => 'otro nombre'])->registered_by)
        ->toBe($location->registered_by);
});

it('acepta un body vacío como no-op', function () {
    $location = Location::factory()->create();

    $updated = locationService()->update($location->id, []);

    expect($updated->name)->toBe($location->name)
        ->and($updated->google_place_id)->toBe($location->google_place_id)
        ->and($updated->status)->toBe($location->status);
});

it('lanza NotFoundError al editar un id inexistente', function () {
    locationService()->update(9999, ['name' => 'bodega central']);
})->throws(NotFoundError::class, 'El destino no existe');

it('cambia el tipo sin ninguna guarda y lo deja intacto cuando el update lo omite', function () {
    $location = Location::factory()->create();

    expect(locationService()->update($location->id, ['type' => 'port'])->type)->toBe(LocationType::Port)
        ->and(locationService()->update($location->id, ['name' => 'bodega norte'])->type)->toBe(LocationType::Port)
        ->and(locationService()->update($location->id, ['type' => 'destination'])->type)->toBe(LocationType::Destination)
        ->and($location->fresh()->type)->toBe(LocationType::Destination);
});

/*
|--------------------------------------------------------------------------
| Baja lógica y toggle
|--------------------------------------------------------------------------
*/

it('da de baja sin borrar la fila y es idempotente', function () {
    $location = Location::factory()->active()->create();

    expect(locationService()->destroy($location->id)->status)->toBeFalse()
        ->and(locationService()->destroy($location->id)->status)->toBeFalse()
        ->and(Location::query()->whereKey($location->id)->count())->toBe(1);
});

it('alterna el estado en los dos sentidos', function () {
    $location = Location::factory()->inactive()->create();

    expect(locationService()->toggleStatus($location->id)->status)->toBeTrue()
        ->and(locationService()->toggleStatus($location->id)->status)->toBeFalse();
});

it('devuelve el destino por id sea cual sea su estado', function (string $estado) {
    $location = Location::factory()->{$estado}()->create();

    expect(locationService()->getLocationById($location->id)->id)->toBe($location->id);
})->with(['active', 'inactive']);

it('lanza NotFoundError sobre un id inexistente', function (string $method) {
    locationService()->{$method}(9999);
})->with(['getLocationById', 'toggleStatus', 'destroy', 'getActiveLocationById'])->throws(NotFoundError::class, 'El destino no existe');

it('devuelve el destino activo por id', function () {
    $location = Location::factory()->active()->create();

    expect(locationService()->getActiveLocationById($location->id)->id)->toBe($location->id);
});

it('rechaza con BadRequestError un destino inactivo, que existe pero no se usa', function () {
    $location = Location::factory()->inactive()->create();

    locationService()->getActiveLocationById($location->id);
})->throws(BadRequestError::class, 'El destino seleccionado no está activo');

/*
|--------------------------------------------------------------------------
| Listado y filtros
|--------------------------------------------------------------------------
*/

it('devuelve la colección completa sin limit y pagina con él', function () {
    Location::factory()->count(3)->create();

    expect(locationService()->getLocations([]))->toBeInstanceOf(Collection::class)
        ->and(locationService()->getLocations(['limit' => null]))->toBeInstanceOf(Collection::class)
        ->and(locationService()->getLocations(['limit' => 'abc']))->toBeInstanceOf(Collection::class)
        ->and(locationService()->getLocations(['limit' => '10']))->toBeInstanceOf(LengthAwarePaginator::class);
});

it('acota el tamaño de página a [10, 100]', function (string $limit, int $esperado) {
    expect(locationService()->getLocations(['limit' => $limit])->perPage())->toBe($esperado);
})->with([
    'por debajo' => ['5', 10],
    'en el mínimo' => ['10', 10],
    'dentro' => ['25', 25],
    'en el máximo' => ['100', 100],
    'por encima' => ['500', 100],
]);

it('filtra por estado e ignora cualquier valor que no sea booleano', function () {
    Location::factory()->active()->create();
    Location::factory()->inactive()->create();

    expect(locationService()->getLocations(['status' => 'true']))->toHaveCount(1)
        ->and(locationService()->getLocations(['status' => 'false']))->toHaveCount(1)
        ->and(locationService()->getLocations(['status' => '1']))->toHaveCount(1)
        ->and(locationService()->getLocations(['status' => '0']))->toHaveCount(1)
        ->and(locationService()->getLocations(['status' => 'quizas']))->toHaveCount(2)
        ->and(locationService()->getLocations(['status' => null]))->toHaveCount(2);
});

it('busca por nombre normalizando el término antes del LIKE', function () {
    Location::factory()->create(['name' => 'BODEGA CENTRAL']);
    Location::factory()->create(['name' => 'PLANTA DE EMPAQUE']);

    expect(locationService()->getLocations(['search' => 'bodega']))->toHaveCount(1)
        ->and(locationService()->getLocations(['search' => 'BODEGA']))->toHaveCount(1)
        ->and(locationService()->getLocations(['search' => '  bodega   central ']))->toHaveCount(1)
        ->and(locationService()->getLocations(['search' => '   ']))->toHaveCount(2)
        ->and(locationService()->getLocations(['search' => null]))->toHaveCount(2);
});

it('combina la búsqueda con el filtro de estado', function () {
    Location::factory()->active()->create(['name' => 'BODEGA CENTRAL']);
    Location::factory()->inactive()->create(['name' => 'BODEGA SUR']);

    $activas = locationService()->getLocations(['search' => 'bodega', 'status' => 'true']);

    expect($activas)->toHaveCount(1)
        ->and($activas->first()->name)->toBe('BODEGA CENTRAL');
});

it('devuelve los destinos ordenados por id ascendente', function () {
    $locations = Location::factory()->count(5)->create();

    $ids = $locations->pluck('id')->sort()->values()->all();

    expect(locationService()->getLocations([])->pluck('id')->all())->toBe($ids);
});

it('carga el responsable del alta con el listado, sin N+1', function () {
    Location::factory()->count(3)->create();

    $locations = locationService()->getLocations([]);

    expect($locations->every(fn (Location $location) => $location->relationLoaded('registeredBy')))->toBeTrue();
});

it('carga el responsable del alta también en el detalle', function () {
    $location = Location::factory()->create();

    expect(locationService()->getLocationById($location->id)->relationLoaded('registeredBy'))->toBeTrue();
});

it('filtra por tipo con coincidencia exacta e ignora cualquier valor fuera del enum', function () {
    Location::factory()->port()->count(2)->create();
    Location::factory()->count(3)->create();

    /** LocationType::tryFrom() es toda la tolerancia: lo que no encaja no filtra nada. */
    expect(locationService()->getLocations(['type' => 'port']))->toHaveCount(2)
        ->and(locationService()->getLocations(['type' => 'destination']))->toHaveCount(3)
        ->and(locationService()->getLocations(['type' => 'PORT']))->toHaveCount(5)
        ->and(locationService()->getLocations(['type' => 'puerto']))->toHaveCount(5)
        ->and(locationService()->getLocations(['type' => '']))->toHaveCount(5)
        ->and(locationService()->getLocations(['type' => null]))->toHaveCount(5)
        ->and(locationService()->getLocations([]))->toHaveCount(5);
});

it('combina el filtro de tipo con el estado y con la búsqueda', function () {
    Location::factory()->port()->active()->create(['name' => 'PUERTO QUETZAL']);
    Location::factory()->port()->inactive()->create(['name' => 'PUERTO BARRIOS']);
    Location::factory()->active()->create(['name' => 'BODEGA CENTRAL']);

    $activos = locationService()->getLocations(['type' => 'port', 'status' => 'true']);

    expect($activos)->toHaveCount(1)
        ->and($activos->first()->name)->toBe('PUERTO QUETZAL')
        ->and(locationService()->getLocations(['type' => 'port', 'search' => 'puerto']))->toHaveCount(2)
        ->and(locationService()->getLocations(['type' => 'destination', 'search' => 'puerto']))->toHaveCount(0);
});
