<?php

namespace App\Services\ShippingLine;

use App\Errors\NotFoundError;
use App\Interfaces\ShippingLine\ShippingLineServiceInterface;
use App\Models\ShippingLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Override;

class ShippingLineService implements ShippingLineServiceInterface
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
    public function getShippingLines(array $filters): LengthAwarePaginator|Collection
    {
        /**
         * Sin withTrashed(): el scope del trait deja fuera a las borradas y no hay ningún
         * filtro que las devuelva. Para el listado, una naviera borrada no existe.
         */
        $query = ShippingLine::query()->with('registeredBy');

        /**
         * El término se normaliza como un nombre —colapsando espacios—; name está siempre
         * en mayúsculas, así que con eso basta para ser insensible a mayúsculas.
         */
        $search = ShippingLine::normalizeName($filters['search'] ?? '');

        if ($search !== '') {
            $query->where('name', 'LIKE', '%'.$search.'%');
        }

        /** El catálogo se lee en el orden en que se dio de alta. */
        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getShippingLineById(int $id): ShippingLine
    {
        /**
         * Deliberadamente sin withTrashed(): quien lee no distingue una naviera borrada de
         * una que nunca existió, y los dos casos salen por el mismo 404. La distinción solo
         * la hace resolveWritableShippingLine(), para poder dar un 400 con sentido a quien
         * intenta editar o volver a borrar.
         */
        $shippingLine = ShippingLine::query()->with('registeredBy')->find($id);

        if ($shippingLine === null) {
            throw new NotFoundError('La naviera no existe');
        }

        return $shippingLine;
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
