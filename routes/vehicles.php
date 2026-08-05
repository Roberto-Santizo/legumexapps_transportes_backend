<?php

use App\Enums\UserRole;
use App\Http\Controllers\VehicleController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$carrier = UserRole::Carrier->value;

Route::prefix('vehicles')->name('vehicles.')->middleware('jwt.auth')->group(function () use ($administrator, $carrier): void {
    Route::apiResource('/', VehicleController::class)
        ->parameters(['' => 'vehicle'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('index', ["role:{$carrier},{$administrator}", 'carrier.required'])
        ->middlewareFor('store', ["role:{$carrier}", 'carrier.required'])
        ->middlewareFor('show', ["role:{$carrier},{$administrator}", 'carrier.required'])
        ->middlewareFor('update', ["role:{$carrier},{$administrator}", 'carrier.required'])
        ->middlewareFor('destroy', ["role:{$carrier},{$administrator}", 'carrier.required']);
});
