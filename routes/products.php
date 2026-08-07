<?php

use App\Enums\UserRole;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;

Route::prefix('products')->name('products.')->middleware('jwt.auth')->group(function () use ($administrator): void {
    /** Antes del apiResource: si no, el comodín {product} capturaría /toggle-status. */
    Route::patch('/{product}/toggle-status', [ProductController::class, 'toggleStatus'])
        ->middleware("role:{$administrator}")
        ->name('toggle-status');

    /** La lectura no lleva role ni carrier.required: el catálogo es nacional y cualquier autenticado lo consulta. */
    Route::apiResource('/', ProductController::class)
        ->parameters(['' => 'product'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator}"])
        ->middlewareFor('update', ["role:{$administrator}"])
        ->middlewareFor('destroy', ["role:{$administrator}"]);
});
