<?php

namespace App\Interfaces\Zone;

use App\Models\User;
use App\Models\Zone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ZoneServiceInterface
{
    /**
     * List the zones, oldest first.
     *
     * The zones are national: every authenticated user reaches every row, with no
     * company scoping. Both active and inactive zones are returned unless the status
     * filter says otherwise, and invalid filters are ignored instead of failing.
     *
     * Every returned model carries its polygon ready to be read, so the resource
     * never has to touch the geometry column itself.
     *
     * @param  array{status?: string|null, search?: string|null, lat?: string|null, lng?: string|null, limit?: string|null}  $filters
     *                                                                                                                                 status: anything filter_var resolves to a boolean (true, false, 1, 0);
     *                                                                                                                                 search: LIKE term matched against the name, normalized to upper case;
     *                                                                                                                                 lat/lng: point the zone must contain, only applied when both arrive, are numeric and are in range;
     *                                                                                                                                 limit: page size requested by the client, clamped to [10, 100].
     * @return LengthAwarePaginator<int, Zone>|Collection<int, Zone>
     */
    public function getZones(array $filters): LengthAwarePaginator|Collection;

    /**
     * Return the zone matching the given id, whatever its status.
     *
     * Throws a NotFoundError when the row does not exist.
     */
    public function getZoneById(int $id): Zone;

    /**
     * Register a new zone.
     *
     * The name is normalized before being persisted and rejected with a
     * BadRequestError when another zone already holds it. The colour is upper cased
     * and falls back to the default blue when it does not arrive. The zone is always
     * born active: the body cannot set its status.
     *
     * @param  array{name: string, description?: string|null, color?: string|null, area: array<int, array{0: float, 1: float}>}  $data
     *                                                                                                                                 area: `[lat, lng]` pairs with an open ring, at least three of them;
     *                                                                                                                                 registered_by comes from the given user, never from the body.
     */
    public function create(User $user, array $data): Zone;

    /**
     * Update the given fields on the row matching the given id.
     *
     * Only the given keys are touched, and registered_by is never rewritten: it keeps
     * pointing at whoever registered the zone. A name that arrives is normalized and
     * checked for availability ignoring this same row; an area that arrives replaces
     * the previous polygon whole, with no trace of the old one. An empty payload is a
     * no-op, not an error. Throws a NotFoundError when the row does not exist and a
     * BadRequestError when the name is already taken.
     *
     * @param  array{name?: string, description?: string|null, color?: string, status?: bool, area?: array<int, array{0: float, 1: float}>}  $data
     */
    public function update(int $id, array $data): Zone;

    /**
     * Flip the status of the row matching the given id.
     *
     * An active zone becomes inactive and an inactive one becomes active, so this is
     * also the way back for a zone taken down with destroy(). Throws a NotFoundError
     * when the row does not exist.
     */
    public function toggleStatus(int $id): Zone;

    /**
     * Take the row matching the given id down.
     *
     * This is a logical delete: the status moves to false and the row —polygon
     * included— stays in the table and in the listings. It is idempotent: a zone that
     * is already inactive is returned unchanged instead of failing. Throws a
     * NotFoundError when the row does not exist.
     */
    public function destroy(int $id): Zone;
}
