<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\Zone\ZoneServiceInterface;
use App\Models\User;
use App\Models\Zone;
use App\Services\Zone\ZoneService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

function zoneService(): ZoneServiceInterface
{
    return app(ZoneServiceInterface::class);
}

function zoneServiceAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * Un cuadrado de un grado con esquina inferior izquierda en el punto dado.
 *
 * @return array<int, array{0: float, 1: float}>
 */
function zoneSquare(float $latitude, float $longitude): array
{
    return [
        [$latitude, $longitude],
        [$latitude + 1.0, $longitude],
        [$latitude + 1.0, $longitude + 1.0],
        [$latitude, $longitude + 1.0],
    ];
}

it('resuelve la implementación de zonas registrada en el provider', function () {
    expect(zoneService())->toBeInstanceOf(ZoneService::class);
});

/*
|--------------------------------------------------------------------------
| Alta y lectura del polígono
|--------------------------------------------------------------------------
*/

it('guarda el polígono y lo devuelve como los mismos pares con el anillo abierto', function () {
    $pairs = [[14.6349, -90.5069], [14.6402, -90.4998], [14.6281, -90.4931]];

    $zone = zoneService()->create(zoneServiceAdmin(), ['name' => 'zona norte', 'area' => $pairs]);

    expect(Zone::geoJsonToPairs($zone->area_geojson))->toBe($pairs);
});

it('normaliza el nombre y aplica el azul por defecto cuando no llega color', function () {
    $zone = zoneService()->create(zoneServiceAdmin(), [
        'name' => '  zona   norte ',
        'area' => zoneSquare(14.0, -90.0),
    ]);

    expect($zone->name)->toBe('ZONA NORTE')
        ->and($zone->color)->toBe('#3388FF')
        ->and($zone->status)->toBeTrue();
});

it('pasa el color a mayúsculas', function () {
    $zone = zoneService()->create(zoneServiceAdmin(), [
        'name' => 'zona sur',
        'color' => '#ff0000',
        'area' => zoneSquare(14.0, -90.0),
    ]);

    expect($zone->color)->toBe('#FF0000');
});

it('rechaza un nombre que ya existe aunque llegue en minúsculas', function () {
    $admin = zoneServiceAdmin();
    zoneService()->create($admin, ['name' => 'ZONA NORTE', 'area' => zoneSquare(14.0, -90.0)]);

    zoneService()->create($admin, ['name' => 'zona norte', 'area' => zoneSquare(15.0, -91.0)]);
})->throws(BadRequestError::class, 'Ya existe una zona con ese nombre');

it('registra al usuario autenticado como responsable del alta', function () {
    $admin = zoneServiceAdmin();

    $zone = zoneService()->create($admin, ['name' => 'zona sur', 'area' => zoneSquare(14.0, -90.0)]);

    expect($zone->registered_by)->toBe($admin->id)
        ->and($zone->registeredBy->name)->toBe($admin->name);
});

/*
|--------------------------------------------------------------------------
| Edición
|--------------------------------------------------------------------------
*/

it('sustituye el polígono entero cuando el body trae area', function () {
    $zone = Zone::factory()->create();
    $pairs = zoneSquare(13.0, -89.0);

    $updated = zoneService()->update($zone->id, ['area' => $pairs]);

    expect(Zone::geoJsonToPairs($updated->area_geojson))->toBe($pairs);
});

it('no altera el resto de campos cuando solo cambia el nombre', function () {
    $zone = Zone::factory()->create(['color' => '#123456', 'description' => 'la de siempre']);
    $area = Zone::geoJsonToPairs(zoneService()->getZoneById($zone->id)->area_geojson);

    $updated = zoneService()->update($zone->id, ['name' => 'otro nombre']);

    expect($updated->name)->toBe('OTRO NOMBRE')
        ->and($updated->color)->toBe('#123456')
        ->and($updated->description)->toBe('la de siempre')
        ->and(Zone::geoJsonToPairs($updated->area_geojson))->toBe($area);
});

it('rechaza en la edición el nombre de otra zona y acepta el propio', function () {
    $zone = Zone::factory()->create(['name' => 'ZONA NORTE']);

    expect(zoneService()->update($zone->id, ['name' => 'zona norte'])->name)->toBe('ZONA NORTE');

    Zone::factory()->create(['name' => 'ZONA SUR']);

    expect(fn () => zoneService()->update($zone->id, ['name' => 'zona sur']))
        ->toThrow(BadRequestError::class, 'Ya existe una zona con ese nombre');
});

it('no reescribe al responsable del alta al editar', function () {
    $zone = Zone::factory()->create();

    expect(zoneService()->update($zone->id, ['name' => 'otro nombre'])->registered_by)->toBe($zone->registered_by);
});

it('lanza NotFoundError al editar un id inexistente', function () {
    zoneService()->update(9999, ['name' => 'zona norte']);
})->throws(NotFoundError::class, 'La zona no existe');

it('acepta un body vacío como no-op', function () {
    $zone = Zone::factory()->create();

    $updated = zoneService()->update($zone->id, []);

    expect($updated->name)->toBe($zone->name)
        ->and($updated->color)->toBe($zone->color)
        ->and($updated->status)->toBe($zone->status);
});

/*
|--------------------------------------------------------------------------
| Baja lógica
|--------------------------------------------------------------------------
*/

it('da de baja sin borrar la fila y es idempotente', function () {
    $zone = Zone::factory()->active()->create();

    expect(zoneService()->destroy($zone->id)->status)->toBeFalse()
        ->and(zoneService()->destroy($zone->id)->status)->toBeFalse()
        ->and(Zone::whereKey($zone->id)->count())->toBe(1);
});

it('alterna el estado en los dos sentidos', function () {
    $zone = Zone::factory()->inactive()->create();

    expect(zoneService()->toggleStatus($zone->id)->status)->toBeTrue()
        ->and(zoneService()->toggleStatus($zone->id)->status)->toBeFalse();
});

it('lanza NotFoundError sobre un id inexistente', function (string $method) {
    zoneService()->{$method}(9999);
})->with(['getZoneById', 'toggleStatus', 'destroy'])->throws(NotFoundError::class, 'La zona no existe');

/*
|--------------------------------------------------------------------------
| Listado y filtros
|--------------------------------------------------------------------------
*/

it('devuelve las zonas que contienen el punto', function () {
    $inside = Zone::factory()->withArea(zoneSquare(14.0, -91.0))->create();
    Zone::factory()->withArea(zoneSquare(0.0, 0.0))->create();

    $zones = zoneService()->getZones(['lat' => '14.5', 'lng' => '-90.5']);

    expect($zones->pluck('id')->all())->toBe([$inside->id]);
});

it('devuelve las dos zonas cuando el punto cae en un solape', function () {
    Zone::factory()->withArea(zoneSquare(14.0, -91.0))->create();
    Zone::factory()->withArea(zoneSquare(14.2, -90.8))->create();

    expect(zoneService()->getZones(['lat' => '14.5', 'lng' => '-90.5']))->toHaveCount(2);
});

it('devuelve una colección vacía cuando el punto no cae en ninguna zona', function () {
    Zone::factory()->withArea(zoneSquare(14.0, -91.0))->create();

    expect(zoneService()->getZones(['lat' => '-33.0', 'lng' => '18.0']))->toHaveCount(0);
});

it('ignora el filtro de punto cuando falta una coordenada o está fuera de rango', function (array $filters) {
    Zone::factory()->withArea(zoneSquare(14.0, -91.0))->create();

    expect(zoneService()->getZones($filters))->toHaveCount(1);
})->with([
    'solo lat' => [['lat' => '14.5']],
    'solo lng' => [['lng' => '-90.5']],
    'lat fuera de rango' => [['lat' => '200', 'lng' => '-90.5']],
    'lng fuera de rango' => [['lat' => '14.5', 'lng' => '-500']],
    'no numérico' => [['lat' => 'norte', 'lng' => 'sur']],
]);

it('combina el punto con el filtro de estado', function () {
    Zone::factory()->withArea(zoneSquare(14.0, -91.0))->active()->create();
    Zone::factory()->withArea(zoneSquare(14.0, -91.0))->inactive()->create();

    expect(zoneService()->getZones(['lat' => '14.5', 'lng' => '-90.5']))->toHaveCount(2)
        ->and(zoneService()->getZones(['lat' => '14.5', 'lng' => '-90.5', 'status' => 'true']))->toHaveCount(1);
});

it('busca por nombre sin distinguir mayúsculas', function () {
    Zone::factory()->create(['name' => 'ZONA NORTE']);
    Zone::factory()->create(['name' => 'ZONA SUR']);

    expect(zoneService()->getZones(['search' => 'nor']))->toHaveCount(1)
        ->and(zoneService()->getZones(['search' => 'NOR']))->toHaveCount(1)
        ->and(zoneService()->getZones(['search' => '   ']))->toHaveCount(2);
});

it('devuelve la colección completa sin limit y pagina con él', function () {
    Zone::factory()->count(3)->create();

    expect(zoneService()->getZones([]))->toBeInstanceOf(Collection::class)
        ->and(zoneService()->getZones(['limit' => 'muchas']))->toBeInstanceOf(Collection::class)
        ->and(zoneService()->getZones(['limit' => '10']))->toBeInstanceOf(LengthAwarePaginator::class);
});

it('acota el tamaño de página a [10, 100]', function (string $limit, int $expected) {
    $zones = zoneService()->getZones(['limit' => $limit]);

    expect($zones->perPage())->toBe($expected);
})->with([
    'por debajo' => ['5', 10],
    'dentro' => ['25', 25],
    'por encima' => ['500', 100],
]);

it('filtra por estado e ignora cualquier valor que no sea booleano', function () {
    Zone::factory()->active()->create();
    Zone::factory()->inactive()->create();

    expect(zoneService()->getZones(['status' => 'true']))->toHaveCount(1)
        ->and(zoneService()->getZones(['status' => 'false']))->toHaveCount(1)
        ->and(zoneService()->getZones(['status' => 'quiza']))->toHaveCount(2);
});

it('devuelve las zonas ordenadas por id ascendente', function () {
    $zones = Zone::factory()->count(5)->create();

    $ids = $zones->pluck('id')->sort()->values()->all();

    expect(zoneService()->getZones([])->pluck('id')->all())->toBe($ids);
});

it('carga el responsable del alta con el listado, sin N+1', function () {
    Zone::factory()->count(3)->create();

    $zones = zoneService()->getZones([]);

    expect($zones->every(fn (Zone $zone) => $zone->relationLoaded('registeredBy')))->toBeTrue();
});
