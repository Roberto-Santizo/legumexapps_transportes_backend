<?php

use App\Enums\UserRole;
use App\Http\Controllers\PlaceController;
use Illuminate\Support\Facades\Route;

/** Todo rol salvo user y shipment, que solo consultan viajes. */
$readers = UserRole::allExcept(UserRole::User, UserRole::Shipment);

/**
 * Ninguna ruta lleva carrier.required: buscar una dirección es una lectura abierta a
 * cualquier rol salvo user y shipment, incluido un carrier que todavía no ha
 * registrado su empresa.
 */
Route::prefix('places')->name('places.')->middleware(['jwt.auth', "role:{$readers}"])->group(function (): void {
    /**
     * Se declara ANTES del apiResource: si no, el comodín {place} captura /directions y
     * la ruta responde 404 buscando una dirección llamada "directions".
     */
    Route::get('/directions', [PlaceController::class, 'directions'])->name('directions');

    Route::apiResource('/', PlaceController::class)
        ->parameters(['' => 'place'])
        ->only(['index', 'show']);
});
