<?php

namespace App\Interfaces\TripFuel;

use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Models\TripFuel;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface TripFuelServiceInterface
{
    /**
     * List the fuel loads registered on the given trip, oldest first.
     *
     * All three methods take the caller because the scope and the authorship always
     * depend on who is asking, and none of them takes a `Trip` or a `TripFuel`: they
     * take an id and resolve the row inside, so no caller can hand in something it
     * never had the right to load.
     *
     * The scope is **not rewritten here**: it delegates on
     * `TripServiceInterface::getTripById()`, which already throws a NotFoundError
     * outside the reach of the caller and a ForbiddenError outside its company. Unlike
     * `TripPositionService::getPositions()`, there is **no extra rule against the
     * pilot**: the assigned pilot does read the loads of his own trip, because here the
     * data is about him.
     *
     * There are **no filters**: nothing by `fuelType`, nothing by confirmation state. A
     * trip without loads is an empty list with 200, never a 404. The order is fixed to
     * `id ASC` — `loaded_at` is nullable and `created_at` ties between two loads of the
     * same second, so the id is the real chronology of registration.
     *
     * @param  array{limit?: string|null}  $filters
     *                                               limit: page size requested by the client, clamped to [10, 100]. Without it the
     *                                               whole list is returned as a Collection.
     * @return array{fuels: LengthAwarePaginator<int, TripFuel>|Collection<int, TripFuel>, totalGallons: string}
     *                                                                                                           totalGallons is the sum of the **confirmed** loads of the trip, formatted to two
     *                                                                                                           decimals, computed over the cloned query **before** paginating — so `?limit=10`
     *                                                                                                           on a trip of 25 loads still reports the total of the trip and not of the page —
     *                                                                                                           and present with or without paging. Only the confirmed ones count, because the
     *                                                                                                           number that matters is how much fuel actually reached the truck: a freshly
     *                                                                                                           assigned trip reports "0.00" while already holding one registered load. Literal
     *                                                                                                           precedent of `totalAmount` in SPEC 14: business data, not paginator metadata.
     *
     * @throws NotFoundError when the trip does not exist or has been deleted
     * @throws ForbiddenError when the trip is out of the caller's scope
     */
    public function getTripFuels(User $user, int $tripId, array $filters): array;

    /**
     * Register one unconfirmed fuel load on the given trip, on behalf of a carrier user.
     *
     * `registered_by` comes from the given user and never from the body, and both
     * `loaded_at` and `confirmed_by` are born `null`: registering is not confirming.
     *
     * Four guards run in this exact order, and the order is contract —the literal
     * precedent of SPEC 26—: the trip must exist (NotFoundError), must not be deleted
     * (BadRequestError), must have been assigned by the caller's company
     * (ForbiddenError) and must not be `finished` (BadRequestError). Hence a trip that
     * is both deleted and someone else's answers 400 and not 403.
     *
     * A load is accepted on a `pending` trip **and on an `in_route` one** —a refill on
     * the road is the real case—, never after it is closed. Nothing serializes two
     * simultaneous loads: they are independent rows and summing is commutative, so two
     * identical loads in a row are legitimate and no unique index stands in the way.
     *
     * @param  array{gallons: float|string, fuelType: string}  $data
     *                                                                Already validated as a positive amount and one of the four `FuelType` cases.
     *                                                                Nothing else is checked: not against `vehicles.kilometers_per_gallon`, not
     *                                                                against the distance, not against a business ceiling, and not against
     *                                                                `fuel_prices` — `fuelType` is a label, not a foreign key, and no price is stored.
     *
     * @throws NotFoundError when the trip does not exist
     * @throws BadRequestError when the trip has been deleted or is already finished
     * @throws ForbiddenError when the trip is unassigned or was taken by another company
     */
    public function create(User $user, int $tripId, array $data): TripFuel;

    /**
     * Confirm one fuel load, on behalf of the assigned pilot of its trip.
     *
     * `loaded_at` is written with the server's `now()` and `confirmed_by` with the given
     * user: both columns are written together, never one without the other, and neither
     * is ever taken from the body — the route accepts no payload at all, like `/start`
     * and `/finish` of SPEC 24.
     *
     * Confirming is the assigned pilot's and **only** his: any other pilot gets a 403,
     * and the other three roles never reach the route. It does **not** look at the
     * trip's status: a pilot may confirm a load of an already `finished` trip, because
     * forbidding late paperwork would only create rows impossible to close.
     *
     * Re-confirming is **200 without writing anything**, returning the load with its
     * original `loaded_at`: the date is a fact that already happened and repeating the
     * call does not change it. Deliberate silence, with the precedent of the 15 second
     * floor of SPEC 26 — a phone on a bad network retries and must not see an error.
     *
     * There is no way back: `loaded_at` never returns to `null` by any route.
     *
     * @throws NotFoundError when the load does not exist
     * @throws ForbiddenError when the caller is not the assigned pilot of the load's trip
     */
    public function confirm(User $user, int $tripFuelId): TripFuel;
}
