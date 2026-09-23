<?php

use App\Enums\UserRole;
use App\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$export = UserRole::Export->value;

/** Lectura para todo rol salvo user y shipment, que solo consultan viajes. */
$readers = UserRole::allExcept(UserRole::User, UserRole::Shipment);

Route::prefix('locations')->name('locations.')->middleware(['jwt.auth', "role:{$readers}"])->group(function () use ($administrator, $export): void {
    /** Antes del apiResource: si no, el comodín {location} capturaría /toggle-status. */
    Route::patch('/{location}/toggle-status', [LocationController::class, 'toggleStatus'])
        ->middleware("role:{$administrator},{$export}")
        ->name('toggle-status');

    /** La lectura no lleva carrier.required: los destinos son nacionales y cualquier rol salvo user y shipment los consulta. */
    Route::apiResource('/', LocationController::class)
        ->parameters(['' => 'location'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator},{$export}"])
        ->middlewareFor('update', ["role:{$administrator},{$export}"])
        ->middlewareFor('destroy', ["role:{$administrator},{$export}"]);
});
