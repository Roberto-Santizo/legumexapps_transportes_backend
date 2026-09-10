<?php

use App\Services\TripTimeout\DistanceCalculator;

/*
|--------------------------------------------------------------------------
| Distancia entre dos puntos del rastro
|--------------------------------------------------------------------------
|
| DistanceCalculator es el único punto del proyecto que decide si el camión se movió:
| Haversine sobre una esfera de 6 371 000 m y un umbral de 5 metros. Como
| PolylineDecoder (SPEC 16) y Zone::pairsToWkt(), se prueba entero sin base de datos
| y sin red: aquí no hay factory, ni Http::fake(), ni una sola consulta.
|
| Los casos van con tolerancia explícita porque el modelo esférico no pretende ser
| exacto al milímetro: a la escala del umbral su error son milímetros, y eso es justo
| lo que se comprueba.
|
*/

it('devuelve cero para dos coordenadas idénticas', function () {
    expect(DistanceCalculator::metersBetween(14.6282, -90.5229, 14.6282, -90.5229))->toBe(0.0);
});

it('mide un grado de latitud como unos 111 kilómetros', function () {
    $meters = DistanceCalculator::metersBetween(14.0, -90.5229, 15.0, -90.5229);

    expect($meters)->toBeGreaterThan(111_000.0)
        ->and($meters)->toBeLessThan(111_400.0);
});

it('da el mismo resultado en los dos sentidos', function () {
    $forward = DistanceCalculator::metersBetween(14.6282, -90.5229, 14.7000, -90.4000);
    $backward = DistanceCalculator::metersBetween(14.7000, -90.4000, 14.6282, -90.5229);

    expect(round($forward, 6))->toBe(round($backward, 6));
});

it('deja un desplazamiento de 0,00004° de latitud por debajo del umbral', function () {
    $meters = DistanceCalculator::metersBetween(14.6282, -90.5229, 14.62824, -90.5229);

    expect($meters)->toBeLessThan(DistanceCalculator::MOVEMENT_THRESHOLD_METERS)
        ->and($meters)->toBeGreaterThan(4.0);
});

it('deja un desplazamiento de 0,0001° de latitud por encima del umbral', function () {
    $meters = DistanceCalculator::metersBetween(14.6282, -90.5229, 14.6283, -90.5229);

    expect($meters)->toBeGreaterThanOrEqual(DistanceCalculator::MOVEMENT_THRESHOLD_METERS)
        ->and($meters)->toBeLessThan(12.0);
});

it('mide también sobre la longitud, acortada por el coseno de la latitud', function () {
    $meters = DistanceCalculator::metersBetween(14.6282, -90.5229, 14.6282, -90.5219);

    /** 0,001° de longitud a 14,6° de latitud son unos 107 metros, no los 111 del ecuador. */
    expect($meters)->toBeGreaterThan(100.0)
        ->and($meters)->toBeLessThan(112.0);
});

it('mantiene el umbral del movimiento en cinco metros', function () {
    expect(DistanceCalculator::MOVEMENT_THRESHOLD_METERS)->toBe(5);
});
