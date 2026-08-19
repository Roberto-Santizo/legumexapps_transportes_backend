<?php

use App\Services\Place\PolylineDecoder;

/*
|--------------------------------------------------------------------------
| Decodificación de la polilínea
|--------------------------------------------------------------------------
|
| PolylineDecoder es el único punto del proyecto que conoce el formato codificado del
| proveedor: enteros con signo en zigzag, troceados en grupos de 5 bits, acumulados
| como deltas y escalados por 1e5. Como Zone::pairsToWkt(), se prueba entero sin red
| y sin base de datos: aquí no hay Http::fake() ni RefreshDatabase porque no hace
| falta ninguno de los dos.
|
| Invertir un par no lanza ninguna excepción: solo pone la ruta en otro lugar del
| mapa —y en Guatemala, con longitudes negativas, la manda al otro hemisferio—, así
| que el orden [lat, lng] y el signo se comprueban explícitamente.
|
*/

/**
 * La polilínea del ejemplo canónico de Google, con sus tres puntos documentados.
 */
function referencePolyline(): string
{
    return '_p~iF~ps|U_ulLnnqC_mqNvxq`@';
}

/**
 * Cuatro puntos guatemaltecos, de Ciudad de Guatemala hacia Puerto Quetzal.
 *
 * @return list<array{0: float, 1: float}>
 */
function guatemalaPoints(): array
{
    return [
        [14.6248, -90.5152],
        [14.6231, -90.5148],
        [14.59, -90.53],
        [13.9276, -90.7853],
    ];
}

function guatemalaPolyline(): string
{
    return '_lgxA~vmgPrIoAzmE~}A~j`Crzp@';
}

it('decodifica una polilínea conocida a sus pares con cinco decimales', function () {
    expect(PolylineDecoder::decode(referencePolyline()))->toBe([
        [38.5, -120.2],
        [40.7, -120.95],
        [43.252, -126.453],
    ]);
});

it('devuelve una lista vacía para una cadena vacía y no lanza', function () {
    expect(PolylineDecoder::decode(''))->toBe([]);
});

it('devuelve un solo par para una polilínea de un solo punto', function () {
    expect(PolylineDecoder::decode('_lgxA~vmgP'))->toBe([[14.6248, -90.5152]]);
});

it('conserva el signo de las longitudes negativas', function () {
    $points = PolylineDecoder::decode(guatemalaPolyline());

    expect($points)->toBe(guatemalaPoints());

    foreach ($points as $point) {
        expect($point[1])->toBeLessThan(0);
    }
});

it('devuelve los pares en orden lat lng, no lng lat', function () {
    [$latitude, $longitude] = PolylineDecoder::decode(guatemalaPolyline())[0];

    /** Guatemala vive en latitudes positivas de un dígito o dos y longitudes negativas. */
    expect($latitude)->toBe(14.6248)
        ->and($longitude)->toBe(-90.5152);
});

it('acumula cada punto como delta del anterior', function () {
    $points = PolylineDecoder::decode(guatemalaPolyline());

    expect($points)->toHaveCount(4)
        ->and($points[1][0])->toBeLessThan($points[0][0])
        ->and($points[3][0])->toBeLessThan($points[2][0]);
});

it('corta en el último par completo cuando la cadena viene truncada', function () {
    expect(PolylineDecoder::decode('_lgxA'))->toBe([]);
    expect(PolylineDecoder::decode('_lgxA~vmgPrIo'))->toBe([[14.6248, -90.5152]]);
});

it('no lanza con caracteres que no pertenecen al formato', function () {
    expect(fn (): array => PolylineDecoder::decode('###'))->not->toThrow(Throwable::class);
});
