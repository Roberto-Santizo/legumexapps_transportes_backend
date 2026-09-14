<?php

use App\Enums\UserRole;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$manager = UserRole::Manager->value;

/**
 * Cuatro rutas de solo lectura, todas para administrator y manager —los dos roles que
 * ya ven todo, dominio a dominio— y sin carrier.required, del que ambos están exentos.
 * carrier y pilot reciben 403 en las cuatro: no hay un tablero por empresa.
 *
 * Sin FormRequest: no hay cuerpo que validar y todos los filtros son tolerantes.
 */
Route::prefix('dashboard')->name('dashboard.')->middleware(['jwt.auth', "role:{$administrator},{$manager}"])->group(function (): void {
    /** Antes que /trips por orden de lectura, aunque no haya comodín que la capture. */
    Route::get('/trips/in-route', [DashboardController::class, 'tripsInRoute'])->name('trips.in-route');
    Route::get('/trips', [DashboardController::class, 'trips'])->name('trips');
    Route::get('/vehicle-expenses', [DashboardController::class, 'vehicleExpenses'])->name('vehicle-expenses');
    Route::get('/vehicles', [DashboardController::class, 'vehicles'])->name('vehicles');
});
