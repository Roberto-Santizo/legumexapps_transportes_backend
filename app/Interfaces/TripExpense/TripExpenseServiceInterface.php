<?php

namespace App\Interfaces\TripExpense;

use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Models\TripExpense;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface TripExpenseServiceInterface
{
    /**
     * List the travel allowances registered on the given trip, oldest first.
     *
     * All three methods take the caller because the scope and the authorship always
     * depend on who is asking, and none of them takes a `Trip` or a `TripExpense`: they
     * take an id and resolve the row inside, so no caller can hand in something it
     * never had the right to load.
     *
     * The scope is **not rewritten here**: it delegates on
     * `TripServiceInterface::getTripById()`, which already throws a NotFoundError
     * outside the reach of the caller and a ForbiddenError outside its company. As with
     * the fuel loads of SPEC 27, there is **no extra rule against the pilot**: the
     * assigned pilot does read the allowances of their own trip, because here the data
     * is about them.
     *
     * There are **no filters**: nothing by confirmation state. A trip without allowances
     * is an empty list with 200, never a 404. The order is fixed to `id ASC` —
     * `received_at` is nullable and `created_at` ties between two rows of the same
     * second, so the id is the real chronology of registration.
     *
     * @param  array{limit?: string|null}  $filters
     *                                               limit: page size requested by the client, clamped to [10, 100]. Without it the
     *                                               whole list is returned as a Collection.
     * @return array{expenses: LengthAwarePaginator<int, TripExpense>|Collection<int, TripExpense>, totalAmount: string}
     *                                                                                                                   totalAmount is the sum of the **confirmed** allowances of the trip, formatted to
     *                                                                                                                   two decimals, computed over the cloned query **before** paginating and present
     *                                                                                                                   with or without paging. Only the confirmed ones count, because the number that
     *                                                                                                                   matters is how much money actually reached the pilot: a freshly assigned trip
     *                                                                                                                   reports "0.00" while already holding one registered allowance. Literal precedent
     *                                                                                                                   of `totalGallons` in SPEC 27 and `totalAmount` in SPEC 14.
     *
     * @throws NotFoundError when the trip does not exist or has been deleted
     * @throws ForbiddenError when the trip is out of the caller's scope
     */
    public function getTripExpenses(User $user, int $tripId, array $filters): array;

    /**
     * Register one unconfirmed travel allowance on the given trip, on behalf of a carrier user.
     *
     * `registered_by` comes from the given user and never from the body, and both
     * `received_at` and `confirmed_by` are born `null`: registering is not confirming.
     *
     * Four guards run in this exact order, and the order is contract —the literal
     * precedent of SPEC 26 and SPEC 27—: the trip must exist (NotFoundError), must not
     * be deleted (BadRequestError), must have been assigned by the caller's company
     * (ForbiddenError) and must not be `finished` (BadRequestError). Hence a trip that
     * is both deleted and someone else's answers 400 and not 403.
     *
     * An allowance is accepted on a `pending` trip **and on an `in_route` one** —an
     * extra handed over on the road is the real case—, never after it is closed.
     * Nothing serializes two simultaneous rows: they are independent and summing is
     * commutative, so two identical allowances in a row are legitimate.
     *
     * @param  array{amount: float|string, description?: string|null}  $data
     *                                                                        `amount` already validated as a positive number; `description` already trimmed,
     *                                                                        `null` when absent or empty. Nothing else is checked: not against a business
     *                                                                        ceiling, not against the distance, not against the fuel loads.
     *
     * @throws NotFoundError when the trip does not exist
     * @throws BadRequestError when the trip has been deleted or is already finished
     * @throws ForbiddenError when the trip is unassigned or was taken by another company
     */
    public function create(User $user, int $tripId, array $data): TripExpense;

    /**
     * Confirm one travel allowance, on behalf of the assigned pilot of its trip.
     *
     * `received_at` is written with the server's `now()` and `confirmed_by` with the
     * given user: both columns are written together, never one without the other, and
     * neither is ever taken from the body — the route accepts no payload at all, like
     * `/start` and `/finish` of SPEC 24.
     *
     * Confirming is the assigned pilot's and **only** theirs: any other pilot gets a
     * 403, and the other three roles never reach the route. It does **not** look at the
     * trip's status: a pilot may confirm an allowance of an already `finished` trip,
     * because forbidding late paperwork would only create rows impossible to close.
     *
     * Re-confirming is **200 without writing anything**, returning the row with its
     * original `received_at`: the date is a fact that already happened and repeating
     * the call does not change it. Deliberate silence, with the precedent of the fuel
     * confirmation of SPEC 27.
     *
     * There is no way back: `received_at` never returns to `null` by any route.
     *
     * @throws NotFoundError when the allowance does not exist
     * @throws ForbiddenError when the caller is not the assigned pilot of the allowance's trip
     */
    public function confirm(User $user, int $tripExpenseId): TripExpense;
}
