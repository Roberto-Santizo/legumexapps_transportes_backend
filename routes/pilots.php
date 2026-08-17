<?php

use App\Enums\UserRole;
use App\Http\Controllers\PilotController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$carrier = UserRole::Carrier->value;
$manager = UserRole::Manager->value;

Route::prefix('pilots')->name('pilots.')->middleware('jwt.auth')->group(function () use ($administrator, $carrier, $manager): void {
    /** Las rutas fijas van antes del apiResource: si no, las captura el comodín {pilot}. */
    Route::patch('/{pilot}/salary', [PilotController::class, 'updateSalary'])
        ->middleware(["role:{$carrier},{$administrator}", 'carrier.required'])
        ->name('salary.update');

    Route::get('/{pilot}/salary-history', [PilotController::class, 'salaryHistory'])
        ->middleware(["role:{$carrier},{$administrator},{$manager}", 'carrier.required'])
        ->name('salary.history');

    /** Solo index: el resto del CRUD queda deliberadamente sin generar. */
    Route::apiResource('/', PilotController::class)
        ->parameters(['' => 'pilot'])
        ->only(['index'])
        ->middlewareFor('index', ["role:{$carrier},{$administrator},{$manager}", 'carrier.required']);
});
