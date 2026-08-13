<?php

use App\Enums\UserRole;
use App\Models\Zone;

/*
|--------------------------------------------------------------------------
| Geometría del polígono
|--------------------------------------------------------------------------
|
| Zone::pairsToWkt() y Zone::geoJsonToPairs() son el único punto del proyecto que
| conoce el orden `lng lat` de PostGIS y el punto de cierre repetido. Invertir un par
| no lanza ninguna excepción: solo pone la zona en otro lugar del mapa, así que la
| ida y vuelta se prueba antes que el service.
|
*/

/**
 * El triángulo de referencia de la spec, alrededor de Ciudad de Guatemala.
 *
 * @return array<int, array{0: float, 1: float}>
 */
function zoneTriangle(): array
{
    return [
        [14.6349, -90.5069],
        [14.6402, -90.4998],
        [14.6281, -90.4931],
    ];
}

/**
 * El GeoJSON que PostGIS devuelve para ese mismo triángulo, copiado de ST_AsGeoJSON.
 */
function zoneTriangleGeoJson(): string
{
    return '{"type":"Polygon","coordinates":[[[-90.5069,14.6349],[-90.4998,14.6402],[-90.4931,14.6281],[-90.5069,14.6349]]]}';
}

it('invierte cada par a lng lat y repite el primer punto al final', function () {
    expect(Zone::pairsToWkt(zoneTriangle()))
        ->toBe('POLYGON((-90.5069 14.6349, -90.4998 14.6402, -90.4931 14.6281, -90.5069 14.6349))');
});

it('acepta coordenadas que llegan como texto desde el request', function () {
    expect(Zone::pairsToWkt([['14.6349', '-90.5069'], ['14.6402', '-90.4998'], ['14.6281', '-90.4931']]))
        ->toBe(Zone::pairsToWkt(zoneTriangle()));
});

it('reindexa los pares aunque lleguen con claves salteadas', function () {
    $pairs = [3 => [14.6349, -90.5069], 7 => [14.6402, -90.4998], 9 => [14.6281, -90.4931]];

    expect(Zone::pairsToWkt($pairs))->toBe(Zone::pairsToWkt(zoneTriangle()));
});

it('deshace el GeoJSON de PostGIS devolviendo los pares originales con el anillo abierto', function () {
    expect(Zone::geoJsonToPairs(zoneTriangleGeoJson()))->toBe(zoneTriangle());
});

it('descarta el punto de cierre repetido', function () {
    expect(Zone::geoJsonToPairs(zoneTriangleGeoJson()))->toHaveCount(3);
});

it('es una ida y vuelta exacta sobre cualquier polígono', function (array $pairs, string $geoJson) {
    expect(Zone::pairsToWkt($pairs))->toContain(implode(' ', [$pairs[0][1], $pairs[0][0]]))
        ->and(Zone::geoJsonToPairs($geoJson))->toBe($pairs);
})->with([
    'triángulo' => [
        [[14.6349, -90.5069], [14.6402, -90.4998], [14.6281, -90.4931]],
        '{"type":"Polygon","coordinates":[[[-90.5069,14.6349],[-90.4998,14.6402],[-90.4931,14.6281],[-90.5069,14.6349]]]}',
    ],
    'cuadrado' => [
        [[15.0, -91.0], [15.0, -90.0], [14.0, -90.0], [14.0, -91.0]],
        '{"type":"Polygon","coordinates":[[[-91,15],[-90,15],[-90,14],[-91,14],[-91,15]]]}',
    ],
    'ambos valores en el rango de latitud' => [
        [[14.5, -14.5], [15.5, -13.5], [13.5, -13.0]],
        '{"type":"Polygon","coordinates":[[[-14.5,14.5],[-13.5,15.5],[-13,13.5],[-14.5,14.5]]]}',
    ],
]);

it('devuelve un array vacío cuando el GeoJSON no se puede leer', function (string $geoJson) {
    expect(Zone::geoJsonToPairs($geoJson))->toBe([]);
})->with([
    'texto vacío' => [''],
    'json inválido' => ['no soy json'],
    'sin coordenadas' => ['{"type":"Polygon"}'],
    'anillo incompleto' => ['{"type":"Polygon","coordinates":[[[-90.5,14.6],[-90.4,14.7]]]}'],
]);

it('normaliza el nombre recortando, colapsando espacios y pasando a mayúsculas', function () {
    expect(Zone::normalizeName('  zona   norte  '))->toBe('ZONA NORTE');
});

/*
|--------------------------------------------------------------------------
| Factory
|--------------------------------------------------------------------------
*/

it('persiste una zona con su polígono y su administrador', function () {
    $zone = Zone::factory()->create();

    expect($zone->exists)->toBeTrue()
        ->and($zone->name)->toBe(mb_strtoupper($zone->name))
        ->and($zone->registeredBy->role)->toBe(UserRole::Administrator)
        ->and(Zone::whereNull('area')->count())->toBe(0);
});

it('crea zonas activas e inactivas', function () {
    expect(Zone::factory()->active()->create()->status)->toBeTrue()
        ->and(Zone::factory()->inactive()->create()->status)->toBeFalse();
});

it('acepta un polígono concreto en la factory', function () {
    $zone = Zone::factory()->withArea(zoneTriangle())->create();

    expect(Zone::whereKey($zone->id)->count())->toBe(1);
});

it('deja el area fuera de fillable', function () {
    expect((new Zone)->getFillable())->not->toContain('area')
        ->and((new Zone)->getFillable())->toContain('name', 'description', 'color', 'status', 'registered_by');
});
