<?php

namespace App\Interfaces\Location;

use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface LocationServiceInterface
{
    /**
     * List the locations, oldest first.
     *
     * The locations are national: every authenticated user reaches every row, with no
     * company scoping. Both active and inactive locations are returned unless the
     * status filter says otherwise, and an invalid filter is ignored instead of
     * emptying the listing.
     *
     * @param  array{status?: string|null, search?: string|null, limit?: string|null}  $filters
     *                                                                                           status: anything filter_var resolves to a boolean (true, false, 1, 0);
     *                                                                                           search: LIKE term matched against the name, normalized to upper case;
     *                                                                                           limit: page size requested by the client, clamped to [10, 100].
     * @return LengthAwarePaginator<int, Location>|Collection<int, Location>
     */
    public function getLocations(array $filters): LengthAwarePaginator|Collection;

    /**
     * Return the location matching the given id, whatever its status.
     *
     * Throws a NotFoundError when the row does not exist.
     */
    public function getLocationById(int $id): Location;

    /**
     * Return the active location matching the given id.
     *
     * Throws a NotFoundError when the row does not exist and a BadRequestError when it
     * exists but is inactive: a location taken down draws no routes, exactly as it
     * quotes no freight. Answering 404 for an inactive one would say it does not
     * exist and send the client hunting for an id that is perfectly valid.
     *
     * The rule lives in this contract and not in the one of whoever consumes it,
     * because "which location may be used" is knowledge of this domain.
     */
    public function getActiveLocationById(int $id): Location;

    /**
     * Register a new location.
     *
     * The name is normalized before being persisted and rejected with a
     * BadRequestError when another location already holds it. The google place id is
     * stored exactly as it arrives —it is an opaque, case sensitive identifier— and
     * rejected with a BadRequestError when another location already points at that
     * place. The location is always born active: the body cannot set its status.
     *
     * This service never calls Google: the coordinates arrive already resolved by the
     * client, and the google place id is validated against nobody.
     *
     * @param  array{name: string, description?: string|null, googlePlaceId: string, latitude: float|string, longitude: float|string}  $data
     *                                                                                                                                        the keys arrive in camelCase, straight from the validated request;
     *                                                                                                                                        registered_by comes from the given user, never from the body.
     */
    public function create(User $user, array $data): Location;

    /**
     * Update the given fields on the row matching the given id.
     *
     * Only the given keys are touched, and registered_by is never rewritten: it keeps
     * pointing at whoever registered the location. A name that arrives is normalized
     * and checked for availability ignoring this same row, and so is a google place
     * id, which may be repointed at another place while the row keeps its id and its
     * freight rates. The coordinates may be corrected freely, and they are not
     * cross-checked against the google place id. An empty payload is a no-op, not an
     * error. Throws a NotFoundError when the row does not exist and a BadRequestError
     * when the name or the google place id is already taken.
     *
     * @param  array{name?: string, description?: string|null, googlePlaceId?: string, latitude?: float|string, longitude?: float|string, status?: bool}  $data
     */
    public function update(int $id, array $data): Location;

    /**
     * Flip the status of the row matching the given id.
     *
     * An active location becomes inactive and an inactive one becomes active, so this
     * is also the way back for a location taken down with destroy(). It never looks at
     * whether the location has freight rates. Throws a NotFoundError when the row does
     * not exist.
     */
    public function toggleStatus(int $id): Location;

    /**
     * Take the row matching the given id down.
     *
     * This is a logical delete: the status moves to false and the row stays in the
     * table and in the listings. It is idempotent: a location that is already inactive
     * is returned unchanged instead of failing, and it never looks at whether the
     * location has freight rates. Throws a NotFoundError when the row does not exist.
     */
    public function destroy(int $id): Location;
}
