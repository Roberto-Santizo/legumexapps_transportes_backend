<?php

use App\Http\Controllers\PlaceController;
use Illuminate\Support\Facades\Route;

/**
 * Ninguna ruta lleva role ni carrier.required: buscar una dirección es una lectura
 * abierta a cualquier autenticado, incluido un carrier que todavía no ha registrado
 * su empresa.
 */
Route::prefix('places')->name('places.')->middleware('jwt.auth')->group(function (): void {
    /**
     * Se declara ANTES del apiResource: si no, el comodín {place} captura /directions y
     * la ruta responde 404 buscando una dirección llamada "directions".
     */
    Route::get('/directions', [PlaceController::class, 'directions'])->name('directions');

    Route::apiResource('/', PlaceController::class)
        ->parameters(['' => 'place'])
        ->only(['index', 'show']);
});
