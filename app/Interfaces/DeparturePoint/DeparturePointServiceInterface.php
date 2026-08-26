<?php

namespace App\Interfaces\DeparturePoint;

use App\Models\DeparturePoint;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface DeparturePointServiceInterface
{
    /**
     * List the departure points, oldest first.
     *
     * The departure points are national: every authenticated user reaches every row,
     * with no company scoping. Both active and inactive rows are returned unless the
     * status filter says otherwise, and an invalid filter is ignored instead of
     * emptying the listing.
     *
     * @param  array{status?: string|null, search?: string|null, limit?: string|null}  $filters
     *                                                                                           status: anything filter_var resolves to a boolean (true, false, 1, 0);
     *                                                                                           search: LIKE term matched against the name, normalized to upper case;
     *                                                                                           limit: page size requested by the client, clamped to [10, 100].
     * @return LengthAwarePaginator<int, DeparturePoint>|Collection<int, DeparturePoint>
     */
    public function getDeparturePoints(array $filters): LengthAwarePaginator|Collection;

    /**
     * Return the departure point matching the given id, whatever its status.
     *
     * Throws a NotFoundError when the row does not exist.
     *
     * There is no getActiveDeparturePointById() alongside this one on purpose: no
     * freight rate hangs from a departure point, so nobody needs to demand that it be
     * active. A method without a caller is debt, not foresight.
     */
    public function getDeparturePointById(int $id): DeparturePoint;

    /**
     * Register a new departure point.
     *
     * The name is normalized before being persisted and rejected with a
     * BadRequestError when another departure point already holds it. The google place
     * id is stored exactly as it arrives —it is an opaque, case sensitive identifier—
     * and rejected with a BadRequestError naming the row that already points at that
     * place. The departure point is always born active: the body cannot set its status.
     *
     * This service never calls Google: the coordinates arrive already resolved by the
     * client, and the google place id is validated against nobody. Uniqueness is
     * checked within this table only: the same place may also exist as a location.
     *
     * @param  array{name: string, description?: string|null, googlePlaceId: string, latitude: float|string, longitude: float|string}  $data
     *                                                                                                                                        the keys arrive in camelCase, straight from the validated request;
     *                                                                                                                                        registered_by comes from the given user, never from the body.
     */
    public function create(User $user, array $data): DeparturePoint;

    /**
     * Update the given fields on the row matching the given id.
     *
     * Only the given keys are touched, and registered_by is never rewritten: it keeps
     * pointing at whoever registered the departure point. A name that arrives is
     * normalized and checked for availability ignoring this same row, and so is a
     * google place id, which may be repointed at another place while the row keeps its
     * id. The coordinates may be corrected freely, and they are not cross-checked
     * against the google place id. An empty payload is a no-op, not an error. Throws a
     * NotFoundError when the row does not exist and a BadRequestError when the name or
     * the google place id is already taken.
     *
     * @param  array{name?: string, description?: string|null, googlePlaceId?: string, latitude?: float|string, longitude?: float|string, status?: bool}  $data
     */
    public function update(int $id, array $data): DeparturePoint;

    /**
     * Flip the status of the row matching the given id.
     *
     * An active departure point becomes inactive and an inactive one becomes active, so
     * this is also the way back for one taken down with destroy(). Throws a
     * NotFoundError when the row does not exist.
     */
    public function toggleStatus(int $id): DeparturePoint;

    /**
     * Take the row matching the given id down.
     *
     * This is a logical delete: the status moves to false and the row stays in the
     * table and in the listings. It is idempotent: a departure point that is already
     * inactive is returned unchanged instead of failing. Throws a NotFoundError when
     * the row does not exist.
     */
    public function destroy(int $id): DeparturePoint;
}
