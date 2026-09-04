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
 * It deliberately declares no `label()`: the raw English value goes out through the
 * Resource, exactly like `LocationType`, and translating it belongs to the frontend.
 */
enum TripStatus: string
{
    case Pending = 'pending';
    case InRoute = 'in_route';
    case Finished = 'finished';
}
