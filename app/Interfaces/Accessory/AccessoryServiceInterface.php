<?php

namespace App\Interfaces\Accessory;

use App\Models\Accessory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface AccessoryServiceInterface
{
    /**
     * List the accessories, oldest first.
     *
     * The inventory is national: every authenticated user reaches every row, with no
     * company scoping. Accessories in every status are returned unless the status
     * filter says otherwise —an accessory taken down with deleteAccessory() keeps
     * showing up—, and an invalid filter is ignored instead of emptying the listing.
     *
     * @param  array{status?: string|null, search?: string|null, limit?: string|null}  $filters
     *                                                                                           status: one of the AccessoryStatus values; anything else is ignored;
     *                                                                                           search: LIKE term matched against the name and the code, both stored upper cased;
     *                                                                                           limit: page size requested by the client, clamped to [10, 100].
     * @return LengthAwarePaginator<int, Accessory>|Collection<int, Accessory>
     */
    public function getAccessories(array $filters): LengthAwarePaginator|Collection;

    /**
     * Register a new accessory.
     *
     * The name and the code are normalized before being persisted and rejected with a
     * BadRequestError when another accessory already holds them. The code is unique
     * globally, whatever the status of the row holding it: unlike a vehicle plate, an
     * inactive accessory does not release its code. The accessory is always born
     * active: a status in the body is ignored.
     *
     * @param  array{name: string, code: string, description?: string|null, price: float|string, purchaseDate: string, annualDepreciation: float|string}  $data
     *                                                                                                                                                           the keys arrive in camelCase, straight from the validated request;
     *                                                                                                                                                           registered_by comes from the given user, never from the body.
     */
    public function createAccessory(array $data, User $user): Accessory;

    /**
     * Return the accessory matching the given id, whatever its status.
     *
     * Throws a NotFoundError when the row does not exist.
     */
    public function getAccessoryById(int $id): Accessory;

    /**
     * Update the given fields on the row matching the given id.
     *
     * Only the given keys are touched, and registered_by is never rewritten: it keeps
     * pointing at whoever registered the accessory. A name or a code that arrives is
     * normalized and checked for availability ignoring this same row, so resending the
     * row's own value is accepted. The status moves freely between the three values,
     * with no transition rules. The description is erased by sending an explicit null.
     * An empty payload is a no-op, not an error. Throws a NotFoundError when the row
     * does not exist and a BadRequestError when the name or the code is already taken.
     *
     * @param  array{name?: string, code?: string, description?: string|null, price?: float|string, purchaseDate?: string, annualDepreciation?: float|string, status?: string}  $data
     */
    public function updateAccessory(array $data, int $id): Accessory;

    /**
     * Take the row matching the given id down.
     *
     * This is a logical delete: the status moves to inactive and the row stays in the
     * table and in the listings, keeping its code taken forever. It is idempotent: an
     * accessory that is already inactive is returned unchanged instead of failing.
     * Throws a NotFoundError when the row does not exist.
     */
    public function deleteAccessory(int $id): Accessory;
}
