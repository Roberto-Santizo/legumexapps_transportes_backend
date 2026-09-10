<?php

use App\Enums\UserRole;
use App\Http\Controllers\TripFuelController;
use Illuminate\Support\Facades\Route;

$pilot = UserRole::Pilot->value;

/**
 * Una sola ruta, y no anidada bajo {trip} como las otras dos del dominio: el id de la
 * carga ya identifica el viaje, así que {trip} sería un parámetro de adorno y la ruta
 * sería la más profunda del proyecto.
 *
 * Confirmar es del piloto asignado y solo suyo: el middleware deja fuera a los otros
 * tres roles y el service rechaza con 403 a cualquier otro piloto. Sin FormRequest y sin
 * cuerpo, como /start y /finish de SPEC 24: el efecto único es la fecha del servidor.
 */
Route::prefix('trip-fuels')->name('trip-fuels.')->middleware('jwt.auth')->group(function () use ($pilot): void {
    Route::patch('/{tripFuel}/confirm', [TripFuelController::class, 'confirm'])
        ->middleware(["role:{$pilot}"])
        ->name('confirm');
});
