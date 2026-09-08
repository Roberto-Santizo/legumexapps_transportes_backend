<?php

use App\Enums\UserRole;
use App\Http\Controllers\TripController;
use App\Http\Controllers\TripPositionController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$carrier = UserRole::Carrier->value;
$pilot = UserRole::Pilot->value;

Route::prefix('trips')->name('trips.')->middleware('jwt.auth')->group(function () use ($administrator, $carrier, $pilot): void {
    /**
     * Las tres rutas fijas van antes del apiResource: si no, las captura el comodín
     * {trip} y `/assignment` se resolvería como el detalle de un viaje llamado así.
     *
     * Cada una lleva su propio rol, que es justo lo que se gana al no meter las tres
     * acciones dentro del PATCH general: quién puede hacer qué se lee aquí, en el
     * archivo de rutas, sin abrir el service.
     */

    /**
     * Tomar un viaje es exclusivo del transportista: el administrador no asigna por
     * ninguna vía, porque asignar es el acto por el que una empresa toma el viaje.
     */
    Route::patch('/{trip}/assignment', [TripController::class, 'assign'])
        ->middleware(["role:{$carrier}", 'carrier.required'])
        ->name('assignment');

    /** Las dos marcas de ejecución son del piloto asignado, y solo suyas. */
    Route::patch('/{trip}/start', [TripController::class, 'start'])
        ->middleware(["role:{$pilot}"])
        ->name('start');

    Route::patch('/{trip}/finish', [TripController::class, 'finish'])
        ->middleware(["role:{$pilot}"])
        ->name('finish');

    /**
     * Primera ruta anidada del proyecto, contra el precedente de SPEC 14 y SPEC 18: se
     * anida porque {trip} ya es el parámetro del grupo y porque una posición sin viaje
     * no significa nada — no hay listado global de posiciones que tenga sentido.
     *
     * Reportar es del piloto asignado y solo suyo; leer el rastro no lleva role: porque
     * lo decide el ámbito dentro del service, que además deja fuera a cualquier pilot.
     * Ninguna de las dos lleva carrier.required.
     */
    Route::post('/{trip}/positions', [TripPositionController::class, 'store'])
        ->middleware(["role:{$pilot}"])
        ->name('positions.store');

    Route::get('/{trip}/positions', [TripPositionController::class, 'index'])
        ->name('positions.index');

    /**
     * La lectura no lleva role: los cuatro roles listan y consultan, y lo que cada uno
     * alcanza lo decide el ámbito dentro del service, no el middleware. La escritura
     * general es solo del administrador.
     */
    Route::apiResource('/', TripController::class)
        ->parameters(['' => 'trip'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator}"])
        ->middlewareFor('update', ["role:{$administrator}"])
        ->middlewareFor('destroy', ["role:{$administrator}"]);
});
