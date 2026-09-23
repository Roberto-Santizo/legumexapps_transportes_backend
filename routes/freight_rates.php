<?php

use App\Enums\UserRole;
use App\Http\Controllers\FreightRateController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$manager = UserRole::Manager->value;

/** Lectura para todo rol salvo user y shipment, que solo consultan viajes. */
$readers = UserRole::allExcept(UserRole::User, UserRole::Shipment);

Route::prefix('freight-rates')->name('freight-rates.')->middleware(['jwt.auth', "role:{$readers}"])->group(function () use ($administrator, $manager): void {
    /** Antes del apiResource: si no, el comodín {freightRate} capturaría /quote. */
    Route::get('/quote', [FreightRateController::class, 'quote'])
        ->name('quote');

    /**
     * Ninguna ruta lleva carrier.required: la tarifa es un dato nacional.
     * El listado y la cotización quedan abiertos a cualquier rol salvo user y shipment; el
     * detalle solo al administrador y al manager —que lo lee todo—, porque quien no
     * administra tarifas cotiza con /quote en vez de leer la fila.
     */
    Route::apiResource('/', FreightRateController::class)
        ->parameters(['' => 'freightRate'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator}"])
        ->middlewareFor('show', ["role:{$administrator},{$manager}"])
        ->middlewareFor('update', ["role:{$administrator}"])
        ->middlewareFor('destroy', ["role:{$administrator}"]);
});
