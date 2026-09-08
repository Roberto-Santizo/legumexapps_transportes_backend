<?php

use App\Enums\UserRole;
use App\Interfaces\Trip\TripServiceInterface;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Único canal del proyecto. Dos reglas, en este orden: el piloto nunca escucha
 * —emite y ya—, y el resto solo alcanza los viajes que ya podría ver por HTTP.
 *
 * El ámbito no se reescribe aquí: se delega en getTripById(), que lanza 404 fuera
 * del alcance y 403 fuera de la empresa. Duplicar la matriz de SPEC 24 sería la
 * forma más rápida de que el canal y el endpoint dejen de decir lo mismo.
 */
Broadcast::channel('trips.{tripId}', function (User $user, int $tripId): bool {
    if ($user->role === UserRole::Pilot) {
        return false;
    }

    try {
        app(TripServiceInterface::class)->getTripById($user, $tripId);

        return true;
    } catch (Throwable) {
        return false;
    }
});
