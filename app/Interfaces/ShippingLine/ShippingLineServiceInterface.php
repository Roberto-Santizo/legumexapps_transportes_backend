<?php

namespace App\Interfaces\ShippingLine;

use App\Models\ShippingLine;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ShippingLineServiceInterface
{
    /**
     * List the shipping lines, oldest first.
     *
     * The catalog is national: every authenticated user reaches every row, with no
     * company scoping. Deleted shipping lines are never returned and there is no filter
     * that brings them back — reading withTrashed() is an internal detail of the
     * service, not a capability of this contract. An invalid filter is ignored instead
     * of emptying the listing.
     *
     * @param  array{search?: string|null, limit?: string|null}  $filters
     *                                                                     search: LIKE term matched against the name, stored upper cased;
     *                                                                     limit: page size requested by the client, clamped to [10, 100].
     * @return LengthAwarePaginator<int, ShippingLine>|Collection<int, ShippingLine>
     */
    public function getShippingLines(array $filters): LengthAwarePaginator|Collection;

    /**
     * Return the shipping line matching the given id.
     *
     * Throws a NotFoundError when the row does not exist **and also when it has been
     * deleted**: for a reader a deleted shipping line simply is not there, so this is a
     * 404 and never the 400 that the writing methods raise.
     */
    public function getShippingLineById(int $id): ShippingLine;

    /**
     * Register a new shipping line.
     *
     * The name is normalized before being persisted and rejected with a BadRequestError
     * when another shipping line already holds it. That check looks at the deleted rows
     * too: deleting a shipping line does **not** release its name, so a value taken by
     * an invisible, already deleted row is still refused.
     *
     * @param  array{name: string}  $data
     *                                     the key arrives straight from the validated request;
     *                                     registered_by comes from the given user, never from the body.
     */
    public function create(User $user, array $data): ShippingLine;

    /**
     * Update the given fields on the row matching the given id.
     *
     * Only the given keys are touched, and registered_by is never rewritten: it keeps
     * pointing at whoever registered the shipping line. A name that arrives is
     * normalized and checked for availability ignoring this same row, so resending the
     * row's own value is accepted. An empty payload is a no-op, not an error. Throws a
     * NotFoundError when the row does not exist, a BadRequestError when the row has
     * already been deleted, and a BadRequestError when the name is taken.
     *
     * @param  array{name?: string}  $data
     */
    public function update(int $id, array $data): ShippingLine;

    /**
     * Delete the row matching the given id.
     *
     * This is a soft delete and it is **not** idempotent nor reversible: the row keeps
     * living in the table with its deleted_at set —and keeps its name taken forever—
     * but it disappears from every endpoint, and a second call throws a BadRequestError
     * instead of returning 200 like the boolean catalogs do. Throws a NotFoundError
     * when the row does not exist, which is how a wrong id stays distinguishable from
     * an already deleted shipping line.
     *
     * There is no restore() alongside this one on purpose: undoing a deletion is out of
     * scope and has to be done straight in the database.
     */
    public function destroy(int $id): ShippingLine;
}
