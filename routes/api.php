<?php

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Punto de entrada de la API. Cada recurso registra sus rutas en su propio
| archivo dentro de routes/ y se incluye aquí.
|
*/

require __DIR__.'/auth.php';
require __DIR__.'/carriers.php';
require __DIR__.'/fuel_prices.php';
require __DIR__.'/products.php';
require __DIR__.'/vehicles.php';
require __DIR__.'/zones.php';
