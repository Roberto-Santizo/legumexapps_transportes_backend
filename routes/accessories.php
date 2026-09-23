<?php

use App\Enums\UserRole;
use App\Http\Controllers\AccessoryController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;

/** Lectura para todo rol salvo user y shipment, que solo consultan viajes. */
$readers = UserRole::allExcept(UserRole::User, UserRole::Shipment);

Route::prefix('accessories')->name('accessories.')->middleware(['jwt.auth', "role:{$readers}"])->group(function () use ($administrator): void {
    /**
     * No hay ninguna ruta fija que declarar antes del apiResource: con tres estados un
     * /toggle-status no significa nada, así que el cambio de estado va por el PATCH.
     *
     * La lectura no lleva carrier.required: el inventario es nacional y
     * cualquier rol salvo user y shipment lo consulta.
     */
    Route::apiResource('/', AccessoryController::class)
        ->parameters(['' => 'accessory'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator}"])
        ->middlewareFor('update', ["role:{$administrator}"])
        ->middlewareFor('destroy', ["role:{$administrator}"]);
});
