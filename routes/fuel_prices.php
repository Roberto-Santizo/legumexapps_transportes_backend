<?php

use App\Enums\UserRole;
use App\Http\Controllers\FuelPriceController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;

Route::prefix('fuel-prices')->name('fuel-prices.')->middleware('jwt.auth')->group(function () use ($administrator): void {
    /** Antes del apiResource: si no, el comodín {fuelPrice} capturaría /current. */
    Route::get('/current', [FuelPriceController::class, 'current'])
        ->name('current');

    Route::patch('/{fuelPrice}/deactivate', [FuelPriceController::class, 'deactivate'])
        ->middleware("role:{$administrator}")
        ->name('deactivate');

    /** La lectura no lleva role ni carrier.required: el precio es un dato nacional que cualquier autenticado consulta. */
    Route::apiResource('/', FuelPriceController::class)
        ->parameters(['' => 'fuelPrice'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator}"])
        ->middlewareFor('update', ["role:{$administrator}"])
        ->middlewareFor('destroy', ["role:{$administrator}"]);
});
