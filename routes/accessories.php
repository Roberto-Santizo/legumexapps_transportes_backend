<?php

use App\Enums\UserRole;
use App\Http\Controllers\AccessoryController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;

Route::prefix('accessories')->name('accessories.')->middleware('jwt.auth')->group(function () use ($administrator): void {
    /**
     * No hay ninguna ruta fija que declarar antes del apiResource: con tres estados un
     * /toggle-status no significa nada, así que el cambio de estado va por el PATCH.
     *
     * La lectura no lleva role ni carrier.required: el inventario es nacional y
     * cualquier autenticado lo consulta.
     */
    Route::apiResource('/', AccessoryController::class)
        ->parameters(['' => 'accessory'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator}"])
        ->middlewareFor('update', ["role:{$administrator}"])
        ->middlewareFor('destroy', ["role:{$administrator}"]);
});
