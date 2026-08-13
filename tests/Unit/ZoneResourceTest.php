<?php

use App\Http\Resources\Zone\ZoneResource;
use App\Interfaces\Zone\ZoneServiceInterface;
use App\Models\Zone;
use Illuminate\Http\Request;

/**
 * @return array<string, mixed>
 */
function zoneResourceArray(Zone $zone): array
{
    return (new ZoneResource($zone))->toArray(Request::create('/api/zones'));
}

it('devuelve todas las claves en camelCase', function () {
    $zone = Zone::factory()->create();

    expect(array_keys(zoneResourceArray($zone)))->toBe([
        'id', 'name', 'description', 'color', 'area', 'status',
        'registeredByName', 'createdAt', 'updatedAt',
    ]);
});

it('devuelve el polígono como pares [lat, lng] con el anillo abierto', function () {
    $pairs = [[14.6349, -90.5069], [14.6402, -90.4998], [14.6281, -90.4931]];
    $zone = Zone::factory()->withArea($pairs)->create();

    $resource = zoneResourceArray(app(ZoneServiceInterface::class)->getZoneById($zone->id));

    expect($resource['area'])->toBe($pairs);
});

it('devuelve un area vacía en vez de reventar cuando el modelo llega sin la columna calculada', function () {
    $zone = Zone::factory()->create();

    expect(zoneResourceArray(Zone::query()->findOrFail($zone->id))['area'])->toBe([]);
});

it('formatea las fechas como d-m-Y h:i:s A', function () {
    $zone = Zone::factory()->create();

    expect(zoneResourceArray($zone)['createdAt'])
        ->toMatch('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} (AM|PM)$/');
});

it('expone el nombre de quien dio de alta la zona', function () {
    $zone = Zone::factory()->create();

    expect(zoneResourceArray($zone)['registeredByName'])->toBe($zone->registeredBy->name);
});

it('nunca devuelve el color en null', function () {
    $zone = Zone::factory()->create(['color' => '#3388FF']);

    expect(zoneResourceArray($zone)['color'])->toBe('#3388FF');
});
