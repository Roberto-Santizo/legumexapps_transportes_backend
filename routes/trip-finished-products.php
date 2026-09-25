<?php

use App\Enums\UserRole;
use App\Http\Controllers\TripFinishedProductController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$export = UserRole::Export->value;

Route::prefix('trip-finished-products')->name('trip-finished-products.')->middleware('jwt.auth')->group(function () use ($administrator, $export): void {
    /**
     * Sin anidar bajo /api/trips/{trip}: el viaje viaja en el query param obligatorio
     * tripId del listado y en el body del alta, con el precedente de vehicle-expenses
     * (SPEC 14). La lectura va con jwt.auth a secas: el ámbito lo aplica el service con
     * getTripById(), piloto asignado incluido. Escriben los mismos que crean viajes.
     */
    Route::apiResource('/', TripFinishedProductController::class)
        ->parameters(['' => 'tripFinishedProduct'])
        ->only(['index', 'store', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator},{$export}"])
        ->middlewareFor('update', ["role:{$administrator},{$export}"])
        ->middlewareFor('destroy', ["role:{$administrator},{$export}"]);
});
