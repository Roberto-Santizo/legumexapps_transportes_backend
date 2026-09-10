<?php

namespace App\Interfaces\TripTimeout;

use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Models\TripPosition;
use App\Models\TripTimeout;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface TripTimeoutServiceInterface
{
    /**
     * List the stops detected on the given trip, oldest first.
     *
     * Reading is a calque of the track of SPEC 26: it takes the caller because the
     * scope always depends on who is asking, it takes the trip's id and not a `Trip`,
     * and the scope is **not rewritten here** —it delegates on
     * `TripServiceInterface::getTripById()`, which already throws a NotFoundError
     * outside the reach of the caller and a ForbiddenError outside its company. On top
     * of it there is a single extra rule: a `pilot` never reads the stops, not even
     * those of his own trip.
     *
     * There are **no filters**: no `open`, no `dateFrom`, no `minDurationMinutes`.
     * A trip without stops is an empty list with 200, never a 404.
     *
     * @param  array{limit?: string|null}  $filters
     *                                               limit: page size requested by the client, clamped to [10, 100]. Without it every
     *                                               stop is returned as a Collection, which on an urban trip full of traffic lights
     *                                               may well be dozens of elements.
     * @return LengthAwarePaginator<int, TripTimeout>|Collection<int, TripTimeout>
     *
     * @throws ForbiddenError when the caller is a pilot, or the trip belongs to another company
     * @throws NotFoundError when the trip does not exist or has been deleted
     */
    public function getTimeouts(User $user, int $tripId, array $filters): LengthAwarePaginator|Collection;

    /**
     * Decide what the point just recorded means for the trip's open stop, if any.
     *
     * **The only write path of the domain**: no endpoint creates a `trip_timeout`, they
     * are born here as a side effect of `POST /api/trips/{trip}/positions` and die
     * either here —the point that proves the truck moved— or in `TripService::finish()`.
     *
     * It is called **only when the point was actually written**, never on the 200 of the
     * 15 second floor of SPEC 26: evaluating a discarded request would open stops out of
     * points that never made it into the track.
     *
     * Two rules, in this order:
     *
     * - There is an open stop → the distance is measured against its **anchor**. Under
     *   the threshold nothing happens and **not a single column is touched**; at the
     *   threshold or beyond the stop is closed with the new point's `recorded_at` and id.
     * - There is no open stop → the distance is measured against the trip's **previous
     *   point**. Under the threshold a stop anchored on that previous point is opened;
     *   at the threshold or beyond nothing happens. Without a previous point —the first
     *   point of the trip— there is nothing to measure.
     *
     * A point never closes and opens in the same request: the point that closes a stop
     * is, by definition, a point in motion.
     *
     * Never throws: it is not a guard, it is a consequence.
     */
    public function trackPosition(TripPosition $position): void;
}
