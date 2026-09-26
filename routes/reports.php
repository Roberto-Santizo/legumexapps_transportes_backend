<?php

use App\Enums\UserRole;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/** Todo rol salvo pilot: el ámbito y la matriz de columnas los resuelve el service. */
$readers = UserRole::allExcept(UserRole::Pilot);

/**
 * Reportes descargables en binario. Sin carrier.required, igual que GET /api/trips:
 * el ámbito del carrier lo resuelve getTrips().
 */
Route::prefix('reports')->name('reports.')->middleware(['jwt.auth', "role:{$readers}"])->group(function (): void {
    Route::get('/trips', [ReportController::class, 'trips'])->name('trips');
});
