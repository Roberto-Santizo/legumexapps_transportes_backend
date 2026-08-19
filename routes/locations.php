<?php

use App\Enums\UserRole;
use App\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;

Route::prefix('locations')->name('locations.')->middleware('jwt.auth')->group(function () use ($administrator): void {
    /** Antes del apiResource: si no, el comodín {location} capturaría /toggle-status. */
    Route::patch('/{location}/toggle-status', [LocationController::class, 'toggleStatus'])
        ->middleware("role:{$administrator}")
        ->name('toggle-status');

    /** La lectura no lleva role ni carrier.required: los destinos son nacionales y cualquier autenticado los consulta. */
    Route::apiResource('/', LocationController::class)
        ->parameters(['' => 'location'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator}"])
        ->middlewareFor('update', ["role:{$administrator}"])
        ->middlewareFor('destroy', ["role:{$administrator}"]);
});
