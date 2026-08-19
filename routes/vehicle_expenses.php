<?php

use App\Enums\UserRole;
use App\Http\Controllers\VehicleExpenseController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$carrier = UserRole::Carrier->value;
$manager = UserRole::Manager->value;

Route::prefix('vehicle-expenses')->name('vehicle-expenses.')->middleware('jwt.auth')->group(function () use ($administrator, $carrier, $manager): void {
    Route::apiResource('/', VehicleExpenseController::class)
        ->parameters(['' => 'vehicleExpense'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('index', ["role:{$carrier},{$administrator},{$manager}"])
        ->middlewareFor('store', ["role:{$carrier},{$administrator}"])
        ->middlewareFor('show', ["role:{$carrier},{$administrator},{$manager}"])
        ->middlewareFor('update', ["role:{$carrier},{$administrator}"])
        ->middlewareFor('destroy', ["role:{$carrier},{$administrator}"]);
});
