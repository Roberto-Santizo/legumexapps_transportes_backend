<?php

use App\Enums\UserRole;
use App\Http\Controllers\TripEmergencyExpenseController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$carrier = UserRole::Carrier->value;

/**
 * Corregir y borrar un gasto emergente, sin anidar bajo {trip} como las otras dos rutas
 * del dominio: el id del gasto ya identifica el viaje, así que {trip} sería un parámetro
 * de adorno. Misma excepción declarada que PATCH /api/trip-expenses/{tripExpense}/confirm.
 *
 * Las dos son del transportista que tomó el viaje o del administrador; la empresa la
 * comprueba el service, no el middleware, por eso no llevan carrier.required.
 */
Route::prefix('trip-emergency-expenses')->name('trip-emergency-expenses.')->middleware('jwt.auth')->group(function () use ($administrator, $carrier): void {
    Route::patch('/{tripEmergencyExpense}', [TripEmergencyExpenseController::class, 'update'])
        ->middleware(["role:{$carrier},{$administrator}"])
        ->name('update');

    Route::delete('/{tripEmergencyExpense}', [TripEmergencyExpenseController::class, 'destroy'])
        ->middleware(["role:{$carrier},{$administrator}"])
        ->name('destroy');
});
