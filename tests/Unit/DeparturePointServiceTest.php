<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\DeparturePoint\DeparturePointServiceInterface;
use App\Models\DeparturePoint;
use App\Models\Location;
use App\Models\User;
use App\Services\DeparturePoint\DeparturePointService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

function departurePointService(): DeparturePointServiceInterface
{
    return app(DeparturePointServiceInterface::class);
}

function departurePointServiceAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * The payload the service expects, with the keys already in camelCase.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function departurePointServiceData(array $overrides = []): array
{
    return array_merge([
        'name' => 'bodega central',
        'googlePlaceId' => 'ChIJd8BlQ2BZwokRAFUEcm_qrcA',
        'latitude' => 14.6349,
        'longitude' => -90.5069,
    ], $overrides);
}

it('resuelve la implementación de puntos de partida registrada en el provider', function () {
    expect(departurePointService())->toBeInstanceOf(DeparturePointService::class);
});

/*
|--------------------------------------------------------------------------
| Base de datos y modelo
|--------------------------------------------------------------------------
*/

it('crea la tabla departure_points con sus columnas', function () {
    expect(Schema::hasTable('departure_points'))->toBeTrue()
        ->and(Schema::getColumnListing('departure_points'))->toEqualCanonicalizing([
            'id',
            'name',
            'description',
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
    expect(DeparturePoint::normalizeName($entrada))->toBe($esperado);
})->with([
    'con espacios de sobra' => ['  bodega   central ', 'BODEGA CENTRAL'],
    'ya normalizado' => ['BODEGA CENTRAL', 'BODEGA CENTRAL'],
    'con saltos de línea' => ["bodega\n\tcentral", 'BODEGA CENTRAL'],
    'vacío' => ['   ', ''],
]);

it('castea las coordenadas a decimal de ocho dígitos y el estado a booleano', function () {
    $departurePoint = DeparturePoint::factory()->active()->create(['latitude' => 14.6349, 'longitude' => -90.5069])->fresh();

    expect($departurePoint->latitude)->toBe('14.63490000')
        ->and($departurePoint->longitude)->toBe('-90.50690000')
        ->and($departurePoint->status)->toBeBool()->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| create()
|--------------------------------------------------------------------------
*/

it('persiste el punto de partida normalizando el nombre y dejándolo activo', function () {
    $admin = departurePointServiceAdmin();

    $departurePoint = departurePointService()->create($admin, departurePointServiceData(['name' => '  bodega   central ']));

    expect($departurePoint->name)->toBe('BODEGA CENTRAL')
        ->and($departurePoint->status)->toBeTrue()
        ->and($departurePoint->description)->toBeNull()
        ->and(DeparturePoint::query()->whereKey($departurePoint->id)->exists())->toBeTrue();
});

it('guarda el googlePlaceId tal cual, sin normalizarlo', function () {
    $departurePoint = departurePointService()->create(departurePointServiceAdmin(), departurePointServiceData(['googlePlaceId' => 'ChIJd8Bl_Q2-BZ']));

    expect($departurePoint->google_place_id)->toBe('ChIJd8Bl_Q2-BZ');
});

it('guarda la descripción cuando llega y la deja en null cuando no', function () {
    $admin = departurePointServiceAdmin();

    $conDescripcion = departurePointService()->create($admin, departurePointServiceData(['description' => 'la principal']));
    $sinDescripcion = departurePointService()->create($admin, departurePointServiceData([
        'name' => 'bodega sur',
        'googlePlaceId' => 'ChIJabc0002',
    ]));

    expect($conDescripcion->description)->toBe('la principal')
        ->and($sinDescripcion->description)->toBeNull();
});

it('ignora el status del body: el punto de partida nace siempre activo', function () {
    $departurePoint = departurePointService()->create(departurePointServiceAdmin(), departurePointServiceData(['status' => false]));

    expect($departurePoint->status)->toBeTrue();
});

it('registra al usuario recibido como responsable del alta', function () {
    $admin = departurePointServiceAdmin();

    $departurePoint = departurePointService()->create($admin, departurePointServiceData());

    expect($departurePoint->registered_by)->toBe($admin->id)
        ->and($departurePoint->registeredBy->name)->toBe($admin->name);
});

it('carga la relación del registrador en el alta', function () {
    $departurePoint = departurePointService()->create(departurePointServiceAdmin(), departurePointServiceData());

    expect($departurePoint->relationLoaded('registeredBy'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Guardas privadas: nombre y lugar
|--------------------------------------------------------------------------
*/

it('rechaza un nombre que ya existe aunque llegue en otra caja', function () {
    $admin = departurePointServiceAdmin();
    departurePointService()->create($admin, departurePointServiceData(['name' => 'BODEGA CENTRAL']));

    departurePointService()->create($admin, departurePointServiceData([
        'name' => 'Bodega   Central',
        'googlePlaceId' => 'ChIJabc0002',
    ]));
})->throws(BadRequestError::class, 'Ya existe un punto de partida con ese nombre');

it('rechaza un googlePlaceId ya registrado nombrando al punto que lo ocupa', function () {
    $admin = departurePointServiceAdmin();
    departurePointService()->create($admin, departurePointServiceData(['name' => 'bodega central', 'googlePlaceId' => 'ChIJabc0001']));

    expect(fn () => departurePointService()->create($admin, departurePointServiceData([
        'name' => 'bodega sur',
        'googlePlaceId' => 'ChIJabc0001',
    ])))->toThrow(BadRequestError::class, 'El lugar seleccionado ya está registrado en el punto de partida BODEGA CENTRAL');
});

it('acepta dos googlePlaceId que solo difieren en la caja', function () {
    $admin = departurePointServiceAdmin();
    departurePointService()->create($admin, departurePointServiceData(['name' => 'bodega central', 'googlePlaceId' => 'ChIJabc0001']));
    departurePointService()->create($admin, departurePointServiceData(['name' => 'bodega sur', 'googlePlaceId' => 'CHIJABC0001']));

    expect(DeparturePoint::query()->count())->toBe(2);
});

it('no consulta la tabla de destinos: el mismo nombre y el mismo lugar pueden estar en las dos', function () {
    Location::factory()->create(['name' => 'BODEGA CENTRAL', 'google_place_id' => 'ChIJabc0001']);

    $departurePoint = departurePointService()->create(departurePointServiceAdmin(), departurePointServiceData([
        'name' => 'bodega central',
        'googlePlaceId' => 'ChIJabc0001',
    ]));

    expect($departurePoint->name)->toBe('BODEGA CENTRAL')
        ->and($departurePoint->google_place_id)->toBe('ChIJabc0001');
});

/*
|--------------------------------------------------------------------------
| update()
|--------------------------------------------------------------------------
*/

it('no altera el resto de campos cuando el update solo cambia el nombre', function () {
    $departurePoint = DeparturePoint::factory()->create([
        'name' => 'BODEGA CENTRAL',
        'description' => 'la de siempre',
        'google_place_id' => 'ChIJabc0001',
        'latitude' => 14.6349,
        'longitude' => -90.5069,
    ]);

    $updated = departurePointService()->update($departurePoint->id, ['name' => '  bodega   norte ']);

    expect($updated->name)->toBe('BODEGA NORTE')
        ->and($updated->description)->toBe('la de siempre')
        ->and($updated->google_place_id)->toBe('ChIJabc0001')
        ->and($updated->latitude)->toBe('14.63490000')
        ->and($updated->longitude)->toBe('-90.50690000');
});

it('corrige las coordenadas conservando el id y el lugar de Google', function () {
    $departurePoint = DeparturePoint::factory()->create(['google_place_id' => 'ChIJabc0001']);

    $updated = departurePointService()->update($departurePoint->id, ['latitude' => 15.5, 'longitude' => -91.25]);

    expect($updated->id)->toBe($departurePoint->id)
        ->and($updated->latitude)->toBe('15.50000000')
        ->and($updated->longitude)->toBe('-91.25000000')
        ->and($updated->google_place_id)->toBe('ChIJabc0001');
});

it('borra la descripción cuando el update manda null', function () {
    $departurePoint = DeparturePoint::factory()->create(['description' => 'la de siempre']);

    expect(departurePointService()->update($departurePoint->id, ['description' => null])->description)->toBeNull();
});

it('rechaza en la edición el nombre de otro punto y acepta el propio', function () {
    $departurePoint = DeparturePoint::factory()->create(['name' => 'BODEGA CENTRAL']);

    expect(departurePointService()->update($departurePoint->id, ['name' => 'bodega central'])->name)->toBe('BODEGA CENTRAL');

    DeparturePoint::factory()->create(['name' => 'BODEGA SUR']);

    expect(fn () => departurePointService()->update($departurePoint->id, ['name' => 'bodega sur']))
        ->toThrow(BadRequestError::class, 'Ya existe un punto de partida con ese nombre');
});

it('rechaza en la edición el googlePlaceId de otro punto y acepta el propio', function () {
    $departurePoint = DeparturePoint::factory()->create(['name' => 'BODEGA CENTRAL', 'google_place_id' => 'ChIJabc0001']);
    DeparturePoint::factory()->create(['name' => 'BODEGA SUR', 'google_place_id' => 'ChIJabc0002']);

    expect(departurePointService()->update($departurePoint->id, ['googlePlaceId' => 'ChIJabc0001'])->google_place_id)
        ->toBe('ChIJabc0001')
        ->and(departurePointService()->update($departurePoint->id, ['googlePlaceId' => 'ChIJabc0003'])->google_place_id)
        ->toBe('ChIJabc0003');

    expect(fn () => departurePointService()->update($departurePoint->id, ['googlePlaceId' => 'ChIJabc0002']))
        ->toThrow(BadRequestError::class, 'El lugar seleccionado ya está registrado en el punto de partida BODEGA SUR');
});

it('reapunta el lugar sin tocar las coordenadas', function () {
    $departurePoint = DeparturePoint::factory()->create([
        'google_place_id' => 'ChIJabc0001',
        'latitude' => 14.6349,
        'longitude' => -90.5069,
    ]);

    $updated = departurePointService()->update($departurePoint->id, ['googlePlaceId' => 'ChIJabc0009']);

    expect($updated->google_place_id)->toBe('ChIJabc0009')
        ->and($updated->latitude)->toBe('14.63490000')
        ->and($updated->longitude)->toBe('-90.50690000');
});

it('cambia el estado cuando el update lo manda', function () {
    $departurePoint = DeparturePoint::factory()->active()->create();

    expect(departurePointService()->update($departurePoint->id, ['status' => false])->status)->toBeFalse();
});

it('no reescribe al responsable del alta al editar', function () {
    $departurePoint = DeparturePoint::factory()->create();

    expect(departurePointService()->update($departurePoint->id, ['name' => 'otro nombre'])->registered_by)
        ->toBe($departurePoint->registered_by);
});

it('acepta un body vacío como no-op', function () {
    $departurePoint = DeparturePoint::factory()->create();

    $updated = departurePointService()->update($departurePoint->id, []);

    expect($updated->name)->toBe($departurePoint->name)
        ->and($updated->google_place_id)->toBe($departurePoint->google_place_id)
        ->and($updated->status)->toBe($departurePoint->status);
});

it('lanza NotFoundError al editar un id inexistente', function () {
    departurePointService()->update(9999, ['name' => 'bodega central']);
})->throws(NotFoundError::class, 'El punto de partida no existe');

/*
|--------------------------------------------------------------------------
| destroy(), toggleStatus() y getDeparturePointById()
|--------------------------------------------------------------------------
*/

it('da de baja sin borrar la fila y es idempotente', function () {
    $departurePoint = DeparturePoint::factory()->active()->create();

    expect(departurePointService()->destroy($departurePoint->id)->status)->toBeFalse()
        ->and(departurePointService()->destroy($departurePoint->id)->status)->toBeFalse()
        ->and(DeparturePoint::query()->whereKey($departurePoint->id)->count())->toBe(1);
});

it('alterna el estado en los dos sentidos', function () {
    $departurePoint = DeparturePoint::factory()->inactive()->create();

    expect(departurePointService()->toggleStatus($departurePoint->id)->status)->toBeTrue()
        ->and(departurePointService()->toggleStatus($departurePoint->id)->status)->toBeFalse();
});

it('devuelve el punto de partida por id sea cual sea su estado', function (string $estado) {
    $departurePoint = DeparturePoint::factory()->{$estado}()->create();

    expect(departurePointService()->getDeparturePointById($departurePoint->id)->id)->toBe($departurePoint->id);
})->with(['active', 'inactive']);

it('lanza NotFoundError sobre un id inexistente', function (string $method) {
    departurePointService()->{$method}(9999);
})->with(['getDeparturePointById', 'toggleStatus', 'destroy'])->throws(NotFoundError::class, 'El punto de partida no existe');

/*
|--------------------------------------------------------------------------
| getDeparturePoints(): listado, filtros y paginación
|--------------------------------------------------------------------------
*/

it('devuelve la colección completa sin limit y pagina con él', function () {
    DeparturePoint::factory()->count(3)->create();

    expect(departurePointService()->getDeparturePoints([]))->toBeInstanceOf(Collection::class)
        ->and(departurePointService()->getDeparturePoints(['limit' => null]))->toBeInstanceOf(Collection::class)
        ->and(departurePointService()->getDeparturePoints(['limit' => 'abc']))->toBeInstanceOf(Collection::class)
        ->and(departurePointService()->getDeparturePoints(['limit' => '10']))->toBeInstanceOf(LengthAwarePaginator::class);
});

it('acota el tamaño de página a [10, 100]', function (string $limit, int $esperado) {
    expect(departurePointService()->getDeparturePoints(['limit' => $limit])->perPage())->toBe($esperado);
})->with([
    'por debajo' => ['1', 10],
    'en el mínimo' => ['10', 10],
    'dentro' => ['25', 25],
    'en el máximo' => ['100', 100],
    'por encima' => ['500', 100],
]);

it('filtra por estado e ignora cualquier valor que no sea booleano', function () {
    DeparturePoint::factory()->active()->create();
    DeparturePoint::factory()->inactive()->create();

    expect(departurePointService()->getDeparturePoints(['status' => 'true']))->toHaveCount(1)
        ->and(departurePointService()->getDeparturePoints(['status' => 'false']))->toHaveCount(1)
        ->and(departurePointService()->getDeparturePoints(['status' => '1']))->toHaveCount(1)
        ->and(departurePointService()->getDeparturePoints(['status' => '0']))->toHaveCount(1)
        ->and(departurePointService()->getDeparturePoints(['status' => 'quizas']))->toHaveCount(2)
        ->and(departurePointService()->getDeparturePoints(['status' => null]))->toHaveCount(2);
});

it('busca por nombre normalizando el término antes del LIKE', function () {
    DeparturePoint::factory()->create(['name' => 'BODEGA CENTRAL']);
    DeparturePoint::factory()->create(['name' => 'PLANTA DE EMPAQUE']);

    expect(departurePointService()->getDeparturePoints(['search' => 'bodega']))->toHaveCount(1)
        ->and(departurePointService()->getDeparturePoints(['search' => 'BODEGA']))->toHaveCount(1)
        ->and(departurePointService()->getDeparturePoints(['search' => '  bodega   central ']))->toHaveCount(1)
        ->and(departurePointService()->getDeparturePoints(['search' => '   ']))->toHaveCount(2)
        ->and(departurePointService()->getDeparturePoints(['search' => null]))->toHaveCount(2);
});

it('combina la búsqueda con el filtro de estado', function () {
    DeparturePoint::factory()->active()->create(['name' => 'BODEGA CENTRAL']);
    DeparturePoint::factory()->inactive()->create(['name' => 'BODEGA SUR']);

    $activos = departurePointService()->getDeparturePoints(['search' => 'bodega', 'status' => 'true']);

    expect($activos)->toHaveCount(1)
        ->and($activos->first()->name)->toBe('BODEGA CENTRAL');
});

it('devuelve los puntos de partida ordenados por id ascendente', function () {
    $departurePoints = DeparturePoint::factory()->count(5)->create();

    $ids = $departurePoints->pluck('id')->sort()->values()->all();

    expect(departurePointService()->getDeparturePoints([])->pluck('id')->all())->toBe($ids);
});

it('devuelve una colección vacía cuando el catálogo está vacío', function () {
    expect(departurePointService()->getDeparturePoints([]))->toBeInstanceOf(Collection::class)->toHaveCount(0);
});

it('carga el responsable del alta con el listado, sin N+1', function () {
    DeparturePoint::factory()->count(3)->create();

    $departurePoints = departurePointService()->getDeparturePoints([]);

    expect($departurePoints->every(fn (DeparturePoint $departurePoint) => $departurePoint->relationLoaded('registeredBy')))->toBeTrue();
});

it('carga el responsable del alta también en el detalle', function () {
    $departurePoint = DeparturePoint::factory()->create();

    expect(departurePointService()->getDeparturePointById($departurePoint->id)->relationLoaded('registeredBy'))->toBeTrue();
});
