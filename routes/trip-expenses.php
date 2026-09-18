<?php

use App\Enums\UserRole;
use App\Http\Controllers\TripExpenseController;
use Illuminate\Support\Facades\Route;

$pilot = UserRole::Pilot->value;

/**
 * Una sola ruta, y no anidada bajo {trip} como las otras dos del dominio: el id del
 * viático ya identifica el viaje, así que {trip} sería un parámetro de adorno. Misma
 * excepción declarada que PATCH /api/trip-fuels/{tripFuel}/confirm (SPEC 27).
 *
 * Confirmar es del piloto asignado y solo suyo: el middleware deja fuera a los otros
 * tres roles y el service rechaza con 403 a cualquier otro piloto. Sin FormRequest y sin
 * cuerpo, como /start y /finish de SPEC 24: el efecto único es la fecha del servidor.
 */
Route::prefix('trip-expenses')->name('trip-expenses.')->middleware('jwt.auth')->group(function () use ($pilot): void {
    Route::patch('/{tripExpense}/confirm', [TripExpenseController::class, 'confirm'])
        ->middleware(["role:{$pilot}"])
        ->name('confirm');
});
