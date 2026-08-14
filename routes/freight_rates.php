<?php

use App\Enums\UserRole;
use App\Http\Controllers\FreightRateController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;

Route::prefix('freight-rates')->name('freight-rates.')->middleware('jwt.auth')->group(function () use ($administrator): void {
    /** Antes del apiResource: si no, el comodín {freightRate} capturaría /quote. */
    Route::get('/quote', [FreightRateController::class, 'quote'])
        ->name('quote');

    /**
     * Ninguna ruta lleva carrier.required: la tarifa es un dato nacional.
     * El listado y la cotización quedan abiertos a cualquier autenticado; el detalle no,
     * porque quien no administra tarifas cotiza con /quote en vez de leer la fila.
     */
    Route::apiResource('/', FreightRateController::class)
        ->parameters(['' => 'freightRate'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator}"])
        ->middlewareFor('show', ["role:{$administrator}"])
        ->middlewareFor('update', ["role:{$administrator}"])
        ->middlewareFor('destroy', ["role:{$administrator}"]);
});
