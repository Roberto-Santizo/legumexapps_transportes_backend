<?php

namespace App\Interfaces\Product;

use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ProductServiceInterface
{
    /**
     * List the product catalogue, oldest first.
     *
     * The catalogue is national: every authenticated user reaches every row,
     * with no company scoping. Both active and inactive products are returned
     * unless the status filter says otherwise, and invalid filters are ignored
     * instead of failing.
     *
     * @param  array{status?: string|null, search?: string|null, limit?: string|null}  $filters
     *                                                                                           status: anything filter_var resolves to a boolean (true, false, 1, 0);
     *                                                                                           search: LIKE term matched against the name, normalized to upper case;
     *                                                                                           limit: page size requested by the client, clamped to [10, 100].
     * @return LengthAwarePaginator<int, Product>|Collection<int, Product>
     */
    public function getProducts(array $filters): LengthAwarePaginator|Collection;

    /**
     * Return the product matching the given id, whatever its status.
     *
     * Throws a NotFoundError when the row does not exist.
     */
    public function getProductById(int $id): Product;

    /**
     * Register a new product in the catalogue.
     *
     * The name is normalized before being persisted and rejected with a
     * BadRequestError when another product already holds it. The product is
     * always born active: the body cannot set its status.
     *
     * @param  array{name: string}  $data
     *                                     registered_by comes from the given user, never from the body.
     */
    public function create(User $user, array $data): Product;

    /**
     * Update the name, the status or both on the row matching the given id.
     *
     * Only the given keys are touched, and registered_by is never rewritten: it
     * keeps pointing at whoever registered the product. A name that arrives is
     * normalized and checked for availability ignoring this same row. Throws a
     * NotFoundError when the row does not exist and a BadRequestError when the
     * name is already taken.
     *
     * @param  array{name?: string, status?: bool}  $data
     */
    public function update(int $id, array $data): Product;

    /**
     * Flip the status of the row matching the given id.
     *
     * An active product becomes inactive and an inactive one becomes active, so
     * this is also the way back for a product taken down with destroy(). Throws
     * a NotFoundError when the row does not exist.
     */
    public function toggleStatus(int $id): Product;

    /**
     * Take the row matching the given id down from the catalogue.
     *
     * This is a logical delete: the status moves to false and the row stays in
     * the table and in the listings. It is idempotent — a product that is already
     * inactive is returned unchanged instead of failing. Throws a NotFoundError
     * when the row does not exist.
     */
    public function destroy(int $id): Product;
}
