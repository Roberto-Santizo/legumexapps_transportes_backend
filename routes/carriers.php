<?php

use App\Enums\UserRole;
use App\Http\Controllers\CarrierController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$carrier = UserRole::Carrier->value;
$pilot = UserRole::Pilot->value;

Route::prefix('carriers')->name('carriers.')->middleware('jwt.auth')->group(function () use ($administrator, $carrier, $pilot): void {
    Route::post('/join', [CarrierController::class, 'join'])
        ->middleware("role:{$pilot}")
        ->name('join');

    Route::get('/me', [CarrierController::class, 'me'])
        ->middleware(["role:{$carrier}", 'carrier.required'])
        ->name('me');

    Route::get('/me/pilots', [CarrierController::class, 'pilots'])
        ->middleware(["role:{$carrier}", 'carrier.required'])
        ->name('me.pilots');

    Route::apiResource('/', CarrierController::class)
        ->parameters(['' => 'carrier'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('index', ["role:{$administrator}", 'carrier.required'])
        ->middlewareFor('store', ["role:{$carrier}"])
        ->middlewareFor('show', ["role:{$administrator}", 'carrier.required'])
        ->middlewareFor('update', ["role:{$carrier},{$administrator}", 'carrier.required'])
        ->middlewareFor('destroy', ["role:{$administrator}", 'carrier.required']);
});
