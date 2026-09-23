<?php

use App\Http\Controllers\DeviceTokenController;
use Illuminate\Support\Facades\Route;

/**
 * Tokens FCM de los dispositivos del usuario (SPEC 34). `jwt.auth` a secas, sin `role:`
 * ni `carrier.required`: cualquier rol puede llegar a recibir notificaciones, y tener
 * empresa no tiene que ver con tener un teléfono.
 *
 * El `{token}` del DELETE es el token FCM, no el id de la fila: el móvil conoce su
 * token, no el id.
 */
Route::prefix('device-tokens')->name('device-tokens.')->middleware('jwt.auth')->group(function (): void {
    Route::post('/', [DeviceTokenController::class, 'store'])->name('store');
    Route::delete('/{token}', [DeviceTokenController::class, 'destroy'])->name('destroy');
});
