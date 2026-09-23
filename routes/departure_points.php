<?php

use App\Enums\UserRole;
use App\Http\Controllers\DeparturePointController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$export = UserRole::Export->value;

/** Lectura para todo rol salvo user y shipment, que solo consultan viajes. */
$readers = UserRole::allExcept(UserRole::User, UserRole::Shipment);

Route::prefix('departure-points')->name('departure-points.')->middleware(['jwt.auth', "role:{$readers}"])->group(function () use ($administrator, $export): void {
    /** Antes del apiResource: si no, el comodín {departurePoint} capturaría /toggle-status. */
    Route::patch('/{departurePoint}/toggle-status', [DeparturePointController::class, 'toggleStatus'])
        ->middleware("role:{$administrator},{$export}")
        ->name('toggle-status');

    /** La lectura no lleva carrier.required: los puntos de partida son nacionales y cualquier rol salvo user y shipment los consulta. */
    Route::apiResource('/', DeparturePointController::class)
        ->parameters(['' => 'departurePoint'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator},{$export}"])
        ->middlewareFor('update', ["role:{$administrator},{$export}"])
        ->middlewareFor('destroy', ["role:{$administrator},{$export}"]);
});
