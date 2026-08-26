<?php

namespace App\Interfaces\Client;

use App\Models\Client;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ClientServiceInterface
{
    /**
     * List the clients, oldest first.
     *
     * The catalog is national: every authenticated user reaches every row, with no
     * company scoping. Deleted clients are never returned and there is no filter that
     * brings them back — reading withTrashed() is an internal detail of the service,
     * not a capability of this contract. An invalid filter is ignored instead of
     * emptying the listing.
     *
     * @param  array{search?: string|null, limit?: string|null}  $filters
     *                                                                     search: LIKE term matched against the code and the name, both stored upper cased;
     *                                                                     limit: page size requested by the client, clamped to [10, 100].
     * @return LengthAwarePaginator<int, Client>|Collection<int, Client>
     */
    public function getClients(array $filters): LengthAwarePaginator|Collection;

    /**
     * Return the client matching the given id.
     *
     * Throws a NotFoundError when the row does not exist **and also when it has been
     * deleted**: for a reader a deleted client simply is not there, so this is a 404
     * and never the 400 that the writing methods raise.
     */
    public function getClientById(int $id): Client;

    /**
     * Register a new client.
     *
     * The code and the name are normalized before being persisted and rejected with a
     * BadRequestError when another client already holds them. That check looks at the
     * deleted rows too: deleting a client does **not** release its code or its name,
     * so a value taken by an invisible, already deleted client is still refused.
     *
     * @param  array{code: string, name: string}  $data
     *                                                   the keys arrive straight from the validated request;
     *                                                   registered_by comes from the given user, never from the body.
     */
    public function create(User $user, array $data): Client;

    /**
     * Update the given fields on the row matching the given id.
     *
     * Only the given keys are touched, and registered_by is never rewritten: it keeps
     * pointing at whoever registered the client. A code or a name that arrives is
     * normalized and checked for availability ignoring this same row, so resending the
     * row's own value is accepted. An empty payload is a no-op, not an error. Throws a
     * NotFoundError when the row does not exist, a BadRequestError when the row has
     * already been deleted, and a BadRequestError when the code or the name is taken.
     *
     * @param  array{code?: string, name?: string}  $data
     */
    public function update(int $id, array $data): Client;

    /**
     * Delete the row matching the given id.
     *
     * This is a soft delete and it is **not** idempotent nor reversible: the row keeps
     * living in the table with its deleted_at set —and keeps its code and its name
     * taken forever— but it disappears from every endpoint, and a second call throws a
     * BadRequestError instead of returning 200 like the boolean catalogs do. Throws a
     * NotFoundError when the row does not exist, which is how a wrong id stays
     * distinguishable from an already deleted client.
     *
     * There is no restore() alongside this one on purpose: undoing a deletion is out of
     * scope and has to be done straight in the database.
     */
    public function destroy(int $id): Client;
}
