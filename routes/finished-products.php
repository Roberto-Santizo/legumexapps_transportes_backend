<?php

use App\Enums\UserRole;
use App\Http\Controllers\FinishedProductController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$export = UserRole::Export->value;

/** Lectura para todo rol salvo pilot: primer catálogo abierto también a user y shipment. */
$readers = UserRole::allExcept(UserRole::Pilot);

Route::prefix('finished-products')->name('finished-products.')->middleware(['jwt.auth', "role:{$readers}"])->group(function () use ($administrator, $export): void {
    /**
     * Sin ninguna ruta fija antes del apiResource: no hay /toggle-status —un SKU no se
     * pausa, se borra— ni /restore. Sin carrier.required: el catálogo es nacional.
     */
    Route::apiResource('/', FinishedProductController::class)
        ->parameters(['' => 'finishedProduct'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator},{$export}"])
        ->middlewareFor('update', ["role:{$administrator},{$export}"])
        ->middlewareFor('destroy', ["role:{$administrator},{$export}"]);
});
