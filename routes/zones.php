<?php

use App\Enums\UserRole;
use App\Http\Controllers\ZoneController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;

Route::prefix('zones')->name('zones.')->middleware('jwt.auth')->group(function () use ($administrator): void {
    /** Antes del apiResource: si no, el comodín {zone} capturaría /toggle-status. */
    Route::patch('/{zone}/toggle-status', [ZoneController::class, 'toggleStatus'])
        ->middleware("role:{$administrator}")
        ->name('toggle-status');

    /** La lectura no lleva role ni carrier.required: las zonas son nacionales y cualquier autenticado las consulta. */
    Route::apiResource('/', ZoneController::class)
        ->parameters(['' => 'zone'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator}"])
        ->middlewareFor('update', ["role:{$administrator}"])
        ->middlewareFor('destroy', ["role:{$administrator}"]);
});
