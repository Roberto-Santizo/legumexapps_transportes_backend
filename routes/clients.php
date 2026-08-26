<?php

use App\Enums\UserRole;
use App\Http\Controllers\ClientController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;

Route::prefix('clients')->name('clients.')->middleware('jwt.auth')->group(function () use ($administrator): void {
    /**
     * Sin ninguna ruta fija antes del apiResource: este dominio no tiene ninguna.
     * No hay /toggle-status —un cliente no se pausa, se borra— ni /restore —un
     * borrado no se deshace por API—, así que el comodín {client} no captura nada.
     *
     * La lectura no lleva role ni carrier.required: el catálogo es nacional y
     * cualquier autenticado lo consulta.
     */
    Route::apiResource('/', ClientController::class)
        ->parameters(['' => 'client'])
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('store', ["role:{$administrator}"])
        ->middlewareFor('update', ["role:{$administrator}"])
        ->middlewareFor('destroy', ["role:{$administrator}"]);
});
