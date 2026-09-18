<?php

use App\Enums\UserRole;
use App\Http\Controllers\AssistantController;
use Illuminate\Support\Facades\Route;

$administrator = UserRole::Administrator->value;
$manager = UserRole::Manager->value;
$carrier = UserRole::Carrier->value;

/**
 * Una sola ruta, protegida exactamente como el tablero que consulta por detrás: los
 * tres roles que pueden leer /api/dashboard y nadie más. El ámbito del carrier lo
 * aplican las herramientas del agente desde el usuario autenticado, no el modelo.
 */
Route::prefix('assistant')->name('assistant.')->middleware(['jwt.auth', "role:{$administrator},{$manager},{$carrier}", 'carrier.required'])->group(function (): void {
    Route::post('/chat', [AssistantController::class, 'chat'])->name('chat');
});
