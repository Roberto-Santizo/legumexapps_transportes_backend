<?php

namespace App\Interfaces\Trip;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface TripServiceInterface
{
    /**
     * List the trips the given user is allowed to see, newest collection date first.
     *
     * The scope is **acquired, not inherited**: a trip owns no carrier_id, so who sees
     * it depends on who acted on it. An administrator and a manager reach every trip; a
     * carrier reaches the pending ones with no pilot and no vehicle —the pool of
     * available trips— **plus** every trip assigned by its own company; a pilot reaches
     * only the trips whose pilot_id is himself, and never the pool.
     *
     * The company is derived from assigned_by, which stores a **user**: the filter
     * compares that user's company against the caller's, so a trip does not fall out of
     * sight when the person who took it leaves.
     *
     * The scope is applied **before** the caller's filters, so no filter combination
     * reveals a trip outside it. Every filter is tolerant: an invalid value is ignored
     * instead of emptying the listing or failing. Deleted trips are never returned.
     *
     * @param  array{status?: string|null, clientId?: string|null, shippingLineId?: string|null, locationId?: string|null, pilotId?: string|null, vehicleId?: string|null, dateFrom?: string|null, dateTo?: string|null, search?: string|null, limit?: string|null}  $filters
     *                                                                                                                                                                                                                                                                         status: value of TripStatus, exact match;
     *                                                                                                                                                                                                                                                                         clientId, shippingLineId, locationId, pilotId, vehicleId: numeric ids, exact match;
     *                                                                                                                                                                                                                                                                         dateFrom, dateTo: parseable dates matched against recolection_date;
     *                                                                                                                                                                                                                                                                         search: LIKE term matched against the order **and** the container, both stored upper cased;
     *                                                                                                                                                                                                                                                                         limit: page size requested by the client, clamped to [10, 100].
     * @return LengthAwarePaginator<int, Trip>|Collection<int, Trip>
     */
    public function getTrips(User $user, array $filters): LengthAwarePaginator|Collection;

    /**
     * Return the trip matching the given id, within the user's scope.
     *
     * Resolved **without** withTrashed(): for a reader a deleted trip simply is not
     * there, so it throws a NotFoundError and never the BadRequestError the writing
     * methods raise. A trip that exists but falls outside the caller's scope throws a
     * ForbiddenError — a 403, not a 404: the scope hides rows from the listing, it does
     * not pretend they were never published.
     */
    public function getTripById(User $user, int $id): Trip;

    /**
     * Publish a new trip.
     *
     * The order and the container are normalized to upper case with collapsed inner
     * whitespace; destination, transport and observations are stored exactly as typed.
     * Neither of the two references is unique: two trips may share both.
     *
     * status is forced to pending and registered_by comes from the given user, never
     * from the body; pilot_id, vehicle_id and assigned_by are **discarded** if they
     * arrive, because a trip is born owned by nobody and only /assignment fills them.
     *
     * Throws a BadRequestError, each one with its own Spanish message, when the client
     * or the shipping line have been deleted, when the location is not a port or is
     * inactive, or when the departure point is inactive.
     *
     * @param  array{order: string, clientId: int, shippingLineId: int, departurePointId: int, locationId: int, destination: string, container: string, transport: string, recolectionDate: string, shipDate: string, polyline: string, observations: string}  $data
     *                                                                                                                                                                                                                                                                recolectionDate and shipDate arrive already validated as future dates, with
     *                                                                                                                                                                                                                                                                shipDate at or after recolectionDate; polyline is the encoded route the
     *                                                                                                                                                                                                                                                                frontend resolved through GET /api/places/directions — this service never calls
     *                                                                                                                                                                                                                                                                Google, and never recomputes it.
     */
    public function create(User $user, array $data): Trip;

    /**
     * Update the given fields on the trip matching the given id.
     *
     * It takes no User because only the administrator reaches it, and an administrator
     * sees every trip: there is no scope left to check.
     *
     * Only the given keys are touched and an empty payload is a no-op, not an error.
     * The catalogs are revalidated even when the payload only moves a date, so a trip
     * cannot be edited while pointing at a port that was deactivated in the meantime.
     *
     * status is accepted here and **not checked against any transition**: a finished
     * trip may go back to pending keeping its start_date and end_date. pilot_id,
     * vehicle_id, assigned_by and registered_by are never written by this method:
     * reassigning is what /assignment is for, and the two authors are never rewritten.
     *
     * Throws a NotFoundError when the trip does not exist, a BadRequestError when it
     * has already been deleted, and the same catalog BadRequestErrors as create().
     *
     * @param  array{order?: string, clientId?: int, shippingLineId?: int, departurePointId?: int, locationId?: int, destination?: string, container?: string, transport?: string, recolectionDate?: string, shipDate?: string, polyline?: string, observations?: string, status?: string}  $data
     */
    public function update(int $id, array $data): Trip;

    /**
     * Delete the trip matching the given id.
     *
     * A soft delete, and like the rest of the soft deleting domains it is **not**
     * idempotent nor reversible: the row keeps living in the table with its deleted_at
     * set —which is why the client and shipping line guards check withTrashed()— but it
     * disappears from every endpoint, and a second call throws a BadRequestError
     * instead of returning 200. Throws a NotFoundError when the trip does not exist,
     * which is how a wrong id stays distinguishable from an already deleted trip.
     *
     * There is no restore() alongside this one on purpose.
     */
    public function destroy(int $id): Trip;

    /**
     * Assign a pilot and a vehicle to the trip matching the given id.
     *
     * Only a carrier reaches this method, and it is the act by which a company **takes**
     * a trip: it writes pilot_id, vehicle_id and assigned_by together, with assigned_by
     * taken from the given user and never from the body. An administrator cannot assign
     * by any route.
     *
     * Throws a ForbiddenError when the trip was already taken by **another company** —
     * the pool is free for anyone, a taken trip only for the company of its assigned_by,
     * whoever of its users calls. Throws a BadRequestError when the trip is not pending
     * any more, so reassigning stops the moment the pilot starts it; when the pilot is
     * not a user with the pilot role linked to a company; when the vehicle is not
     * active; and when pilot and vehicle belong to different companies.
     *
     * The write runs inside a transaction holding a lockForUpdate on the row, so two
     * carriers racing for the same free trip cannot both win it.
     *
     * There is no way to undo it: neither field ever goes back to null.
     *
     * @param  array{pilotId: int, vehicleId: int}  $data
     *                                                     both are required: a trip is never assigned a pilot without a vehicle.
     */
    public function assign(User $user, int $id, array $data): Trip;

    /**
     * Start the trip matching the given id, on behalf of its assigned pilot.
     *
     * start_date is written with the **server's** now() and status moves to in_route.
     * The route takes no body: an execution mark coming from the client could be
     * backdated, and there is no log that would notice.
     *
     * Throws a ForbiddenError when the caller is not the trip's pilot_id, a
     * BadRequestError when the trip has already been started, a NotFoundError when it
     * does not exist and a BadRequestError when it has been deleted.
     */
    public function start(User $user, int $id): Trip;

    /**
     * Finish the trip matching the given id, on behalf of its assigned pilot.
     *
     * end_date is written with the server's now() and status moves to finished. Same
     * ForbiddenError as start(), plus a BadRequestError when the trip has already been
     * finished and another when it was never started: a trip cannot be closed before it
     * begins.
     */
    public function finish(User $user, int $id): Trip;
}
