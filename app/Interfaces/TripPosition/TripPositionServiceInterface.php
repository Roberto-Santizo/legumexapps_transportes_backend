<?php

namespace App\Interfaces\TripPosition;

use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Models\TripPosition;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface TripPositionServiceInterface
{
    /**
     * List the track recorded for the given trip, oldest point first.
     *
     * Both methods take the caller because the scope and the authorship always depend
     * on who is asking, and neither takes a `Trip`: they take its id, and the trip is
     * resolved inside, so no caller can hand in a trip it never had the right to load.
     *
     * The scope is **not rewritten here**: it delegates on
     * `TripServiceInterface::getTripById()`, which already throws a NotFoundError
     * outside the reach of the caller and a ForbiddenError outside its company. On top
     * of it there is a single extra rule — a `pilot` never reads the track, not even
     * the one of his own trip: he reports and nothing else.
     *
     * There are **no filters**: no `dateFrom`, no `dateTo`, nothing. A trip without
     * points is an empty list with 200, never a 404.
     *
     * @param  array{limit?: string|null}  $filters
     *                                               limit: page size requested by the client, clamped to [10, 100]. Without it
     *                                               the whole track is returned as a Collection, which on a long trip may well
     *                                               be thousands of elements.
     * @return LengthAwarePaginator<int, TripPosition>|Collection<int, TripPosition>
     *
     * @throws ForbiddenError when the caller is a pilot, or the trip belongs to another company
     * @throws NotFoundError when the trip does not exist or has been deleted
     */
    public function getPositions(User $user, int $tripId, array $filters): LengthAwarePaginator|Collection;

    /**
     * Record one point of the given trip's track, on behalf of its assigned pilot.
     *
     * `recorded_at` is written with the server's `now()` and `pilot_id` comes from the
     * given user: neither is ever taken from the body, so there is no way to lie about
     * when one was where.
     *
     * Four guards run in this exact order, and the order is contract: the trip must
     * exist (NotFoundError), must not be deleted (BadRequestError), must belong to the
     * caller (ForbiddenError) and must be `in_route` (BadRequestError).
     *
     * A fifth rule is **not** an error: if the last recorded point is less than 15
     * seconds old, that point is returned as it is, **nothing is written and nothing is
     * broadcast**. The pilot's app retries when the network is bad, and answering it an
     * error for retrying would push it into defensive logic of its own — same silence
     * as the file ignored by an expense with `is_invoiced=false` in SPEC 19.
     *
     * When the point is recorded, a `TripPositionUpdated` is broadcast on the trip's
     * private channel. The dispatch is wrapped in a try/catch that logs and carries on:
     * Reverb being down loses the live notice, never the row.
     *
     * @param  array{latitude: float|string, longitude: float|string}  $data
     *                                                                        Already validated as a coordinate pair; nothing checks that it falls near the
     *                                                                        trip's polyline, inside Guatemala, or within a physically possible jump from
     *                                                                        the previous point.
     *
     * @throws NotFoundError when the trip does not exist
     * @throws BadRequestError when the trip has been deleted or is not in route
     * @throws ForbiddenError when the caller is not the trip's assigned pilot
     */
    public function create(User $user, int $tripId, array $data): TripPosition;
}
