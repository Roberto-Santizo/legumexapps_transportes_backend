<?php

use App\Enums\UserRole;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$manager = UserRole::Manager->value;
$carrier = UserRole::Carrier->value;

/**
 * Cuatro rutas de solo lectura para administrator y manager —los dos roles que ya ven
 * todo, dominio a dominio— y para carrier, que solo ve su propia empresa: el ámbito lo
 * fija el service desde el usuario, no el query param. carrier.required deja fuera al
 * carrier sin empresa (administrator y manager están exentos); pilot recibe 403.
 *
 * Sin FormRequest: no hay cuerpo que validar y todos los filtros son tolerantes.
 */
Route::prefix('dashboard')->name('dashboard.')->middleware(['jwt.auth', "role:{$administrator},{$manager},{$carrier}", 'carrier.required'])->group(function (): void {
    /** Antes que /trips por orden de lectura, aunque no haya comodín que la capture. */
    Route::get('/trips/in-route', [DashboardController::class, 'tripsInRoute'])->name('trips.in-route');
    Route::get('/trips', [DashboardController::class, 'trips'])->name('trips');
    Route::get('/vehicle-expenses', [DashboardController::class, 'vehicleExpenses'])->name('vehicle-expenses');
    Route::get('/vehicles', [DashboardController::class, 'vehicles'])->name('vehicles');
});
