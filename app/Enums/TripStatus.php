<?php

namespace App\Enums;

/**
 * The four moments of an export trip.
 *
 * `Pending` is where every trip is born and where it stays until its pilot starts
 * it; `InRoute` and `Finished` are written by `/start` and `/finish`, and by the
 * administrator's general `PATCH`, which accepts any of those three without checking
 * the order.
 *
 * `Cancelled` (SPEC 42) is written only by `PATCH /{trip}/cancel`, only from `Pending`,
 * and is final: a cancelled trip accepts no further write and the general `PATCH`
 * rejects it with 422. It is not the same as deleting: a trip created by mistake is
 * garbage and is still soft deleted (404 when read), while a real trip that will not
 * happen is history and stays visible everywhere as `Cancelled`. Both paths coexist,
 * and a cancelled trip may still be deleted afterwards.
 *
 * `label()` is the Spanish name: `TripListResource` sends it instead of the raw value,
 * and the Excel reports use it too. Filters still take the raw English value.
 */
enum TripStatus: string
{
    case Pending = 'pending';
    case InRoute = 'in_route';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    /**
     * Human readable status name, in Spanish, for user facing output.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::InRoute => 'En ruta',
            self::Finished => 'Finalizado',
            self::Cancelled => 'Cancelado',
        };
    }
}
