<?php

use App\Enums\UserRole;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;

/** Lectura para todo rol salvo user y shipment, que solo consultan viajes. */
$readers = UserRole::allExcept(UserRole::User, UserRole::Shipment);

Route::prefix('products')->name('products.')->middleware(['jwt.auth', "role:{$readers}"])->group(function () use ($administrator): void {
    /** Antes del apiResource: si no, el comodín {product} capturaría /toggle-status. */
    Route::patch('/{product}/toggle-status', [ProductController::class, 'toggleStatus'])
        ->middleware("role:{$administrator}")
        ->name('toggle-status');

    /** La lectura no lleva carrier.required: el catálogo es nacional y cualquier rol salvo user y shipment lo consulta. */
    Route::apiResource('/', ProductController::class)
        ->parameters(['' => 'product'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator}"])
        ->middlewareFor('update', ["role:{$administrator}"])
        ->middlewareFor('destroy', ["role:{$administrator}"]);
});
