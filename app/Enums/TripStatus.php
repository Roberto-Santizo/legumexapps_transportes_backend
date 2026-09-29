<?php

namespace App\Enums;

/**
 * The three moments of an export trip.
 *
 * `Pending` is where every trip is born and where it stays until its pilot starts
 * it; `InRoute` and `Finished` are written by `/start` and `/finish`, and by the
 * administrator's general `PATCH`, which accepts any of the three without checking
 * the order.
 *
 * There is no `Cancelled` case on purpose: a trip that will not happen is deleted,
 * and `SoftDeletes` already tells that story.
 *
 * `label()` is the Spanish name: `TripListResource` sends it instead of the raw value,
 * and the Excel reports use it too. Filters still take the raw English value.
 */
enum TripStatus: string
{
    case Pending = 'pending';
    case InRoute = 'in_route';
    case Finished = 'finished';

    /**
     * Human readable status name, in Spanish, for user facing output.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::InRoute => 'En ruta',
            self::Finished => 'Finalizado',
        };
    }
}
