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
     * Record a batch of points of the given trip's track, on behalf of its assigned pilot.
     *
     * Each point carries the **device's** `recordedAt` —converted to the app timezone and
     * truncated to the second— because a batch stamped with the server's `now()` would
     * give every point the same time. `pilot_id` still comes from the given user.
     *
     * Guards, in this exact order, and the order is contract: the four of a single point
     * —the trip must exist (NotFoundError), must not be deleted (BadRequestError), must
     * belong to the caller (ForbiddenError) and must be `in_route` (BadRequestError)— and
     * then no point may be earlier than the trip's `start_date` (BadRequestError). Any
     * guard aborts the whole batch: nothing is written.
     *
     * The batch is sorted by `recordedAt` and, inside one transaction that locks the
     * trip's row, points **earlier than or equal to** the last stored one are discarded
     * —a retried queue is idempotent— and so are points less than 5 seconds after the
     * previous kept one, measured between device times. Discarding is silent: it only
     * shows in the counters.
     *
     * Every kept point is inserted and handed to
     * `TripTimeoutServiceInterface::trackPosition()` in order, inside the same
     * transaction: if the detection fails halfway, no point stays.
     *
     * After the commit a single `TripPositionUpdated` is broadcast with the last written
     * point, if any, wrapped in a try/catch that logs and carries on.
     *
     * @param  array{positions: list<array{latitude: float|string, longitude: float|string, recordedAt: string}>}  $data
     *                                                                                                                    Already validated: 1 to 1000 points, each a coordinate pair and an ISO 8601
     *                                                                                                                    time with offset no later than one minute from now. Nothing checks that the
     *                                                                                                                    points fall near the trip's polyline.
     * @return array{received: int, saved: int, discarded: int, lastPosition: TripPosition}
     *                                                                                      lastPosition is the last written point or, when everything was discarded, the
     *                                                                                      last point already stored; it is never null, because a point is only ever
     *                                                                                      discarded by comparing it with another one.
     *
     * @throws NotFoundError when the trip does not exist
     * @throws BadRequestError when the trip has been deleted, is not in route or a point is earlier than its start
     * @throws ForbiddenError when the caller is not the trip's assigned pilot
     */
    public function storePositions(User $user, int $tripId, array $data): array;
}
