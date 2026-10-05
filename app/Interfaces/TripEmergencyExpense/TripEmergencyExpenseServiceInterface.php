<?php

namespace App\Interfaces\TripEmergencyExpense;

use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Models\TripEmergencyExpense;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

interface TripEmergencyExpenseServiceInterface
{
    /**
     * List the emergency expenses registered on the given trip, oldest first.
     *
     * Every method takes the caller because the scope and the authorship always depend
     * on who is asking, and none of them takes a `Trip` or a `TripEmergencyExpense`: they
     * take an id and resolve the row inside, so no caller can hand in something it never
     * had the right to load.
     *
     * The scope is **not rewritten here**: it delegates on
     * `TripServiceInterface::getTripById()`, which already throws a NotFoundError for a
     * missing or deleted trip and a ForbiddenError outside the caller's reach. As with the
     * allowances of SPEC 31, the assigned pilot **does** read the expenses of their own
     * trip; `shipment` does not, because it is money.
     *
     * There are **no filters**. A trip without expenses is an empty list with 200, never
     * a 404. The order is fixed to `id ASC`: `created_at` ties between two rows of the
     * same second, so the id is the real chronology of registration.
     *
     * @param  array{limit?: string|null}  $filters
     *                                               limit: page size requested by the client, clamped to [10, 100]. Without it the
     *                                               whole list is returned as a Collection.
     * @return array{emergencyExpenses: LengthAwarePaginator<int, TripEmergencyExpense>|Collection<int, TripEmergencyExpense>, totalAmount: string}
     *                                                                                                                                              totalAmount is the sum of **every** expense of the trip —there is no
     *                                                                                                                                              confirmation to wait for—, formatted to two decimals, computed over the cloned
     *                                                                                                                                              query **before** paginating and present with or without paging.
     *
     * @throws NotFoundError when the trip does not exist or has been deleted
     * @throws ForbiddenError when the caller is `shipment` or the trip is out of their scope
     */
    public function getTripEmergencyExpenses(User $user, int $tripId, array $filters): array;

    /**
     * Register one emergency expense on the given trip, on behalf of a carrier user or
     * the administrator.
     *
     * `registered_by` comes from the given user and never from the body. The receipt, if
     * any, is stored **as is** with `storeUpload()` —a receipt cropped to a square is
     * unreadable— and only after every guard passed, so a refusal never leaves an orphan
     * in the bucket; if the row fails to persist, the freshly uploaded file is deleted.
     *
     * Four guards run in this exact order, and the order is contract: the trip must exist
     * (NotFoundError), must not be deleted (BadRequestError), must have been taken by the
     * caller's company —for the administrator, merely assigned (BadRequestError)—
     * (ForbiddenError) and must be `in_route` (BadRequestError). An unforeseen road
     * expense does not happen on a pending trip, and a late one on a finished trip is a
     * correction for `PATCH`, not a new row.
     *
     * @param  array{amount: float|string, description: string, receipt?: UploadedFile|null}  $data
     *                                                                                               `amount` already validated as a positive number; `description` required.
     *
     * @throws NotFoundError when the trip does not exist
     * @throws BadRequestError when the trip has been deleted, is unassigned (administrator) or is not in route
     * @throws ForbiddenError when the trip is unassigned or was taken by another company
     */
    public function create(User $user, int $tripId, array $data): TripEmergencyExpense;

    /**
     * Correct the amount, the description or the receipt of one emergency expense.
     *
     * `trip_id` and `registered_by` are immutable and ignored if they arrive. A new
     * `receipt` replaces the stored one and `removeReceipt` drops it; in both cases the
     * previous file is deleted from the bucket **after** the row is saved, so a failed
     * write never leaves the row pointing to a deleted object. An empty payload is a
     * no-op that writes nothing and leaves `updated_at` untouched.
     *
     * Four guards run in this exact order: the expense must exist (NotFoundError), its
     * trip must not be deleted (BadRequestError), must have been taken by the caller's
     * company (ForbiddenError) and must not be `pending` (BadRequestError) — a state only
     * reachable through the administrator's general `PATCH` of SPEC 24. A `finished` trip
     * is accepted: the receipt usually arrives after the trip is closed.
     *
     * @param  array{amount?: float|string, description?: string, receipt?: UploadedFile|null, removeReceipt?: bool}  $data
     *
     * @throws NotFoundError when the expense does not exist
     * @throws BadRequestError when its trip has been deleted or is pending
     * @throws ForbiddenError when its trip was taken by another company
     */
    public function update(User $user, int $tripEmergencyExpenseId, array $data): TripEmergencyExpense;

    /**
     * Physically delete one emergency expense and its receipt.
     *
     * Same four guards and order as update(). The row goes away for real —a mistyped
     * expense is garbage, not history, as in SPEC 19— and its receipt is deleted from the
     * bucket afterwards with `delete()`, which never throws. Returns the deleted row.
     *
     * @throws NotFoundError when the expense does not exist
     * @throws BadRequestError when its trip has been deleted or is pending
     * @throws ForbiddenError when its trip was taken by another company
     */
    public function delete(User $user, int $tripEmergencyExpenseId): TripEmergencyExpense;
}
