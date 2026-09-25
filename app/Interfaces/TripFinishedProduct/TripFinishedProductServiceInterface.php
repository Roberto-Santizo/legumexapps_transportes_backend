<?php

namespace App\Interfaces\TripFinishedProduct;

use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Models\TripFinishedProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface TripFinishedProductServiceInterface
{
    /**
     * List the finished product lines of the given trip, in `id ASC` order.
     *
     * The scope is **not rewritten here**: it delegates on
     * `TripServiceInterface::getTripById()`, which throws a NotFoundError for a missing
     * or deleted trip and a ForbiddenError outside the caller's company. As with the
     * fuel loads of SPEC 27, the assigned pilot does read the lines: they are the boxes
     * they carry.
     *
     * **Never paginates**, like `FreightRate`: a trip carries a handful of lines. A trip
     * without lines —one created before SPEC 37— is an empty Collection, never a 404.
     *
     * @return Collection<int, TripFinishedProduct>
     *
     * @throws NotFoundError when the trip does not exist or has been deleted
     * @throws ForbiddenError when the trip is out of the caller's scope
     */
    public function getTripFinishedProducts(User $user, int $tripId): Collection;

    /**
     * Add one finished product line to an existing trip.
     *
     * `registered_by` comes from the given user, never from the body. Five guards run in
     * this exact order, and the order is contract, each one a BadRequestError with its
     * own message: the trip is deleted, the trip is not `pending`, the finished product
     * is deleted, the finished product belongs to another client than the trip's, and
     * the finished product is already on the trip. The last one is backed by the unique
     * index `(trip_id, finished_product_id)`: a race that slips past the check is
     * translated to the same 400 instead of a 500.
     *
     * @param  array{tripId: int|string, finishedProductId: int|string, boxes: int|string}  $data
     *                                                                                             `tripId` and `finishedProductId` already validated with `exists:` (which reads
     *                                                                                             deleted rows too); `boxes` an integer in [1, 999999].
     *
     * @throws NotFoundError when the trip or the finished product do not exist (only on a direct call)
     * @throws BadRequestError when any of the five guards fails
     */
    public function createTripFinishedProduct(array $data, User $user): TripFinishedProduct;

    /**
     * Change the boxes of the given line, and nothing else.
     *
     * `trip_id`, `finished_product_id` and `registered_by` are immutable: switching the
     * product is deleting the line and creating another one. The trip must still be
     * `pending` and not deleted.
     *
     * @param  array{boxes: int|string}  $data
     *
     * @throws NotFoundError when the line does not exist
     * @throws BadRequestError when the trip is deleted or is not pending
     */
    public function updateTripFinishedProduct(int $id, array $data): TripFinishedProduct;

    /**
     * Physically delete the given line, returning it already deleted.
     *
     * A trip always keeps at least one line: deleting the last one is a BadRequestError.
     * The lines of the trip are locked (`lockForUpdate`) before counting, so two
     * simultaneous deletes cannot leave the trip empty.
     *
     * @throws NotFoundError when the line does not exist
     * @throws BadRequestError when the trip is deleted, is not pending, or this is its last line
     */
    public function deleteTripFinishedProduct(int $id): TripFinishedProduct;
}
