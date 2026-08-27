<?php

use App\Enums\UserRole;
use App\Http\Controllers\ShippingLineController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;

Route::prefix('shipping-lines')->name('shipping-lines.')->middleware('jwt.auth')->group(function () use ($administrator): void {
    /**
     * Sin ninguna ruta fija antes del apiResource: este dominio no tiene ninguna.
     * No hay /toggle-status —una naviera no se pausa, se borra— ni /restore —un
     * borrado no se deshace por API—, así que el comodín {shippingLine} no captura nada.
     *
     * La lectura no lleva role ni carrier.required: el catálogo es nacional y
     * cualquier autenticado lo consulta.
     */
    Route::apiResource('/', ShippingLineController::class)
        ->parameters(['' => 'shippingLine'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator}"])
        ->middlewareFor('update', ["role:{$administrator}"])
        ->middlewareFor('destroy', ["role:{$administrator}"]);
});
