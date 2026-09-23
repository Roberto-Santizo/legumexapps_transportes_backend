<?php

use App\Enums\UserRole;
use App\Http\Controllers\AccessoryCharacteristicController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;

/** Lectura para todo rol salvo user y shipment, que solo consultan viajes. */
$readers = UserRole::allExcept(UserRole::User, UserRole::Shipment);

Route::prefix('accessory-characteristics')->name('accessory-characteristics.')->middleware(['jwt.auth', "role:{$readers}"])->group(function () use ($administrator): void {
    /**
     * Ninguna ruta fija que declarar antes del apiResource, y ninguna ruta anidada: no
     * existe /api/accessories/{accessory}/characteristics. El vínculo con el accesorio
     * viaja en el cuerpo y en el query param obligatorio del listado.
     *
     * La lectura no lleva carrier.required: las características cuelgan del
     * inventario nacional y cualquier rol salvo user y shipment las consulta.
     */
    Route::apiResource('/', AccessoryCharacteristicController::class)
        ->parameters(['' => 'accessoryCharacteristic'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator}"])
        ->middlewareFor('update', ["role:{$administrator}"])
        ->middlewareFor('destroy', ["role:{$administrator}"]);
});
