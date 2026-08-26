<?php

use App\Enums\UserRole;
use App\Http\Controllers\DeparturePointController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;

Route::prefix('departure-points')->name('departure-points.')->middleware('jwt.auth')->group(function () use ($administrator): void {
    /** Antes del apiResource: si no, el comodín {departurePoint} capturaría /toggle-status. */
    Route::patch('/{departurePoint}/toggle-status', [DeparturePointController::class, 'toggleStatus'])
        ->middleware("role:{$administrator}")
        ->name('toggle-status');

    /** La lectura no lleva role ni carrier.required: los puntos de partida son nacionales y cualquier autenticado los consulta. */
    Route::apiResource('/', DeparturePointController::class)
        ->parameters(['' => 'departurePoint'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator}"])
        ->middlewareFor('update', ["role:{$administrator}"])
        ->middlewareFor('destroy', ["role:{$administrator}"]);
});
