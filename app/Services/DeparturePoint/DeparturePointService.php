<?php

namespace App\Services\DeparturePoint;

use App\Errors\NotFoundError;
use App\Interfaces\DeparturePoint\DeparturePointServiceInterface;
use App\Models\DeparturePoint;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Override;

class DeparturePointService implements DeparturePointServiceInterface
{
    /**
     * Smallest page size accepted, so nobody sweeps the table row by row.
     */
    private const MIN_PER_PAGE = 10;

    /**
     * Largest page size accepted, so nobody asks for the whole table at once.
     */
    private const MAX_PER_PAGE = 100;

    #[Override]
    public function getDeparturePoints(array $filters): LengthAwarePaginator|Collection
    {
        $query = DeparturePoint::query()->with('registeredBy');

        /** Cualquier valor que no sea booleano se ignora en vez de vaciar el listado. */
        $status = isset($filters['status'])
            ? filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        if ($status !== null) {
            $query->where('status', '=', $status);
        }

        $search = DeparturePoint::normalizeName($filters['search'] ?? '');

        if ($search !== '') {
            /** El name está siempre en mayúsculas, así que normalizar el término basta para ser insensible a mayúsculas. */
            $query->where('name', 'LIKE', '%'.$search.'%');
        }

        /** Los puntos de partida se leen en el orden en que se dieron de alta. */
        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getDeparturePointById(int $id): DeparturePoint
    {
        $departurePoint = DeparturePoint::query()->with('registeredBy')->find($id);

        if ($departurePoint === null) {
            throw new NotFoundError('El punto de partida no existe');
        }

        return $departurePoint;
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
