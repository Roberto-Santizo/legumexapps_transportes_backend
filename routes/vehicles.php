<?php

use App\Enums\UserRole;
use App\Http\Controllers\VehicleController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$carrier = UserRole::Carrier->value;
$manager = UserRole::Manager->value;
$export = UserRole::Export->value;

/**
 * Leer es del transportista (su empresa), del administrador y de los dos roles que
 * consultan sin escribir —manager y export—; escribir, del transportista y del
 * administrador, que al dar de alta elige la empresa en el cuerpo.
 */
Route::prefix('vehicles')->name('vehicles.')->middleware('jwt.auth')->group(function () use ($administrator, $carrier, $manager, $export): void {
    Route::apiResource('/', VehicleController::class)
        ->parameters(['' => 'vehicle'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('index', ["role:{$carrier},{$administrator},{$manager},{$export}", 'carrier.required'])
        ->middlewareFor('store', ["role:{$carrier},{$administrator}", 'carrier.required'])
        ->middlewareFor('show', ["role:{$carrier},{$administrator},{$manager},{$export}", 'carrier.required'])
        ->middlewareFor('update', ["role:{$carrier},{$administrator}", 'carrier.required'])
        ->middlewareFor('destroy', ["role:{$carrier},{$administrator}", 'carrier.required']);
});
