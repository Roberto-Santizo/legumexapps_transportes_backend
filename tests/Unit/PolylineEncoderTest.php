<?php

use App\Services\Place\PolylineDecoder;
use App\Services\Place\PolylineEncoder;

/*
|--------------------------------------------------------------------------
| Codificación de la polilínea
|--------------------------------------------------------------------------
|
| PolylineEncoder es el espejo exacto de PolylineDecoder: mismas constantes, mismo
| zigzag, mismos grupos de 5 bits y misma escala de 1e5. Como el decoder, se prueba
| entero sin red y sin base de datos.
|
| La invariante que fija esta suite es el round-trip: decode(encode($p)) === $p para
| cualquier lista ya redondeada a cinco decimales. Cualquier asimetría entre los dos
| —un cambio en uno sin el otro— rompe aquí antes de llegar a un viaje real.
|
*/

/**
 * Cuatro puntos guatemaltecos, de Ciudad de Guatemala hacia Puerto Quetzal, con su
 * codificación conocida (la misma que decodifica PolylineDecoderTest).
 *
 * @return list<array{0: float, 1: float}>
 */
function encoderGuatemalaPoints(): array
{
    return [
        [14.6248, -90.5152],
        [14.6231, -90.5148],
        [14.59, -90.53],
        [13.9276, -90.7853],
    ];
}

function encoderGuatemalaPolyline(): string
{
    return '_lgxA~vmgPrIoAzmE~}A~j`Crzp@';
}

it('devuelve una cadena vacía para una lista vacía', function () {
    expect(PolylineEncoder::encode([]))->toBe('');
});

it('codifica un solo punto a la polilínea conocida', function () {
    expect(PolylineEncoder::encode([[14.6248, -90.5152]]))->toBe('_lgxA~vmgP');
});

it('codifica el ejemplo canónico de Google', function () {
    expect(PolylineEncoder::encode([
        [38.5, -120.2],
        [40.7, -120.95],
        [43.252, -126.453],
    ]))->toBe('_p~iF~ps|U_ulLnnqC_mqNvxq`@');
});

it('codifica varios puntos con deltas negativos', function () {
    expect(PolylineEncoder::encode(encoderGuatemalaPoints()))->toBe(encoderGuatemalaPolyline());
});

it('hace round-trip exacto con el decoder para pares de cinco decimales', function () {
    $points = encoderGuatemalaPoints();

    expect(PolylineDecoder::decode(PolylineEncoder::encode($points)))->toBe($points);
});

it('hace round-trip con deltas de cero, negativos y de más de un carácter', function () {
    $points = [
        [0.0, 0.0],
        [0.0, 0.0],
        [-0.00001, 0.00001],
        [14.6248, -90.5152],
        [14.6248, -90.5152],
        [-33.86882, 151.20929],
        [89.99999, -179.99999],
    ];

    expect(PolylineDecoder::decode(PolylineEncoder::encode($points)))->toBe($points);
});

it('redondea a cinco decimales una entrada con ocho', function () {
    $points = [
        [14.62481234, -90.51525678],
        [14.62310001, -90.51479999],
    ];

    expect(PolylineDecoder::decode(PolylineEncoder::encode($points)))->toBe([
        [14.62481, -90.51526],
        [14.6231, -90.5148],
    ]);
});
