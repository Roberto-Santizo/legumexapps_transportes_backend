<?php

namespace App\Services\Zone;

use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\Zone\ZoneServiceInterface;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Override;

class ZoneService implements ZoneServiceInterface
{
    /**
     * Smallest page size accepted, so nobody sweeps the table row by row.
     */
    private const MIN_PER_PAGE = 10;

    /**
     * Largest page size accepted, so nobody asks for the whole table at once.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * The blue Leaflet paints with by default, so a zone always looks right on a map.
     */
    private const DEFAULT_COLOR = '#3388FF';

    #[Override]
    public function getZones(array $filters): LengthAwarePaginator|Collection
    {
        $query = $this->readQuery();

        /** Cualquier valor que no sea booleano se ignora en vez de vaciar el listado. */
        $status = isset($filters['status'])
            ? filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        if ($status !== null) {
            $query->where('status', '=', $status);
        }

        $search = Zone::normalizeName($filters['search'] ?? '');

        if ($search !== '') {
            /** El name está siempre en mayúsculas, así que normalizar el término basta para ser insensible a mayúsculas. */
            $query->where('name', 'LIKE', '%'.$search.'%');
        }

        $this->applyPointFilter($query, $filters['lat'] ?? null, $filters['lng'] ?? null);

        /** Las zonas se leen en el orden en que se dieron de alta. */
        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getZoneById(int $id): Zone
    {
        $zone = $this->readQuery()->find($id);

        if ($zone === null) {
            throw new NotFoundError('La zona no existe');
        }

        return $zone;
    }

    #[Override]
    public function create(User $user, array $data): Zone
    {
        /** Se normaliza aquí aunque el FormRequest ya lo haya hecho: el service es llamable directamente. */
        $name = Zone::normalizeName($data['name']);

        $this->ensureNameIsAvailable($name);

        $zone = new Zone([
            'name' => $name,
            'description' => $data['description'] ?? null,
            'color' => $this->normalizeColor($data['color'] ?? null),
            /** El estado no sale del body: una zona nace siempre publicada. */
            'status' => true,
            'registered_by' => $user->id,
        ]);

        $zone->area = $this->areaValue($data['area']);

        $zone->save();

        /** Se relee para que la fila vuelva con su polígono ya traducido. */
        return $this->getZoneById($zone->id);
    }

    #[Override]
    public function update(int $id, array $data): Zone
    {
        $zone = $this->getZoneById($id);

        if (isset($data['name'])) {
            $name = Zone::normalizeName($data['name']);

            /** Se ignora la propia fila: reenviar su mismo nombre no puede chocar consigo misma. */
            $this->ensureNameIsAvailable($name, $zone->id);

            $zone->name = $name;
        }

        /** La descripción se borra mandando null, así que no basta con isset(). */
        if (array_key_exists('description', $data)) {
            $zone->description = $data['description'];
        }

        if (isset($data['color'])) {
            $zone->color = $this->normalizeColor($data['color']);
        }

        if (isset($data['status'])) {
            $zone->status = $data['status'];
        }

        /** registered_by no se reescribe: sigue apuntando a quien dio de alta la zona. */
        $zone->save();

        /** El polígono solo se toca si el body lo trae, y se sustituye entero. */
        if (isset($data['area'])) {
            $this->writeArea($zone->id, $data['area']);
        }

        return $this->getZoneById($zone->id);
    }

    #[Override]
    public function toggleStatus(int $id): Zone
    {
        $zone = $this->getZoneById($id);

        $zone->status = ! $zone->status;

        $zone->save();

        return $zone;
    }

    #[Override]
    public function destroy(int $id): Zone
    {
        $zone = $this->getZoneById($id);

        /** Baja lógica e idempotente: sobre una zona ya inactiva no falla y la deja igual. */
        $zone->status = false;

        $zone->save();

        return $zone;
    }

    /**
     * Start a query that brings the polygon along, ready for the resource.
     *
     * PostgreSQL hands the raw geometry over as hexadecimal WKB, unreadable from PHP
     * without a binary decoder; asking the database for GeoJSON costs one decode in
     * the model and nothing else.
     *
     * @return Builder<Zone>
     */
    private function readQuery(): Builder
    {
        return Zone::query()
            ->with('registeredBy')
            ->select('zones.*')
            ->selectRaw('ST_AsGeoJSON(area) as area_geojson');
    }

    /**
     * Narrow the listing to the zones containing the given point.
     *
     * The filter is applied only when both coordinates arrive, are numeric and are in
     * range; anything else is ignored whole, exactly like a non boolean status. Note
     * that the point is built as `lng, lat`, the reverse of the query string.
     *
     * @param  Builder<Zone>  $query
     */
    private function applyPointFilter(Builder $query, mixed $latitude, mixed $longitude): void
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return;
        }

        /** ST_Contains no acepta geography; el cast a geometry mantiene el índice GiST en juego. */
        $query->whereRaw(
            'ST_Contains(area::geometry, ST_SetSRID(ST_MakePoint(?, ?), '.Zone::SRID.'))',
            [$longitude, $latitude],
        );
    }

    /**
     * Replace the polygon of the given row.
     *
     * The coordinates travel as a binding, never interpolated: it is the only way for
     * them to reach the database through PDO instead of being concatenated into the
     * statement.
     *
     * @param  array<int, array{0: float, 1: float}>  $pairs
     */
    private function writeArea(int $id, array $pairs): void
    {
        DB::statement(
            'UPDATE zones SET area = ST_GeogFromText(?) WHERE id = ?',
            [$this->areaValue($pairs), $id],
        );
    }

    /**
     * Build the value the geography column accepts: the polygon prefixed with its SRID.
     *
     * @param  array<int, array{0: float, 1: float}>  $pairs
     */
    private function areaValue(array $pairs): string
    {
        return 'SRID='.Zone::SRID.';'.Zone::pairsToWkt($pairs);
    }

    /**
     * Upper case the given colour, falling back to the default blue when none arrives.
     */
    private function normalizeColor(?string $color): string
    {
        $color = mb_strtoupper(trim($color ?? ''));

        return $color === '' ? self::DEFAULT_COLOR : $color;
    }

    /**
     * Refuse a name that another zone already holds.
     *
     * Duplicates what the unique rule of the request and the unique index of the table
     * already cover, on purpose: without it a direct call to the service would surface
     * the index violation as a 500 instead of a business error.
     * Throws a BadRequestError when the name is taken.
     *
     * @param  int|null  $ignoreId  row allowed to hold the name, so an update can resend its own.
     */
    private function ensureNameIsAvailable(string $name, ?int $ignoreId = null): void
    {
        $exists = Zone::query()
            ->where('name', '=', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new BadRequestError('Ya existe una zona con ese nombre');
        }
    }

    /**
     * Resolve the page size requested by the client.
     *
     * A missing or non numeric limit means "do not paginate"; a numeric one is clamped
     * to [10, 100].
     */
    private function resolvePerPage(?string $limit): ?int
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }
}
