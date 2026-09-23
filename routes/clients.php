<?php

use App\Enums\UserRole;
use App\Http\Controllers\ClientController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$export = UserRole::Export->value;

/** Lectura para todo rol salvo user y shipment, que solo consultan viajes. */
$readers = UserRole::allExcept(UserRole::User, UserRole::Shipment);

Route::prefix('clients')->name('clients.')->middleware(['jwt.auth', "role:{$readers}"])->group(function () use ($administrator, $export): void {
    /**
     * Sin ninguna ruta fija antes del apiResource: este dominio no tiene ninguna.
     * No hay /toggle-status —un cliente no se pausa, se borra— ni /restore —un
     * borrado no se deshace por API—, así que el comodín {client} no captura nada.
     *
     * La lectura no lleva carrier.required: el catálogo es nacional y
     * cualquier rol salvo user y shipment lo consulta.
     */
    Route::apiResource('/', ClientController::class)
        ->parameters(['' => 'client'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator},{$export}"])
        ->middlewareFor('update', ["role:{$administrator},{$export}"])
        ->middlewareFor('destroy', ["role:{$administrator},{$export}"]);
});
