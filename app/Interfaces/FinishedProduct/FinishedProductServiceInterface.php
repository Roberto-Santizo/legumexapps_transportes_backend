<?php

namespace App\Interfaces\FinishedProduct;

use App\Models\FinishedProduct;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface FinishedProductServiceInterface
{
    /**
     * List the finished products, oldest first.
     *
     * The catalog is national: every reader reaches every row, with no company
     * scoping. Deleted products are never returned and there is no filter that brings
     * them back. An invalid filter is ignored instead of emptying the listing.
     *
     * @param  array{search?: string|null, clientId?: string|null, limit?: string|null}  $filters
     *                                                                                             search: LIKE term matched against the code and the name, both stored upper cased;
     *                                                                                             clientId: exact client id, ignored when not numeric;
     *                                                                                             limit: page size requested by the client, clamped to [10, 100].
     * @return LengthAwarePaginator<int, FinishedProduct>|Collection<int, FinishedProduct>
     */
    public function getFinishedProducts(array $filters): LengthAwarePaginator|Collection;

    /**
     * Return the finished product matching the given id.
     *
     * Throws a NotFoundError when the row does not exist **and also when it has been
     * deleted**: for a reader a deleted product simply is not there.
     */
    public function getFinishedProductById(int $id): FinishedProduct;

    /**
     * Register a new finished product.
     *
     * The code is trimmed and upper cased, the name only upper cased. Throws a
     * BadRequestError when another product —deleted ones included— already holds the
     * code, and when the client has been deleted.
     *
     * @param  array{code: string, name: string, presentation: numeric-string|int|float, boxesPerPallet: numeric-string|int|float, clientId: int}  $data
     *                                                                                                                                                    the keys arrive straight from the validated request;
     *                                                                                                                                                    registered_by comes from the given user, never from the body.
     */
    public function createFinishedProduct(array $data, User $user): FinishedProduct;

    /**
     * Update the given fields on the row matching the given id.
     *
     * Only the given keys are touched, and registered_by is never rewritten. An empty
     * payload is a no-op, not an error. Throws a NotFoundError when the row does not
     * exist, and a BadRequestError when it has already been deleted, when the code is
     * taken by another product or when the given client has been deleted.
     *
     * @param  array{code?: string, name?: string, presentation?: numeric-string|int|float, boxesPerPallet?: numeric-string|int|float, clientId?: int}  $data
     */
    public function updateFinishedProduct(int $id, array $data): FinishedProduct;

    /**
     * Soft delete the row matching the given id and return it, already deleted.
     *
     * Not idempotent nor reversible: the row keeps its code taken forever, and a second
     * call throws a BadRequestError. Throws a NotFoundError when the row does not exist.
     */
    public function deleteFinishedProduct(int $id): FinishedProduct;
}
