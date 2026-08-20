<?php

namespace App\Services\Accessory;

use App\Enums\AccessoryStatus;
use App\Errors\NotFoundError;
use App\Interfaces\Accessory\AccessoryServiceInterface;
use App\Models\Accessory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Override;

class AccessoryService implements AccessoryServiceInterface
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
    public function getAccessories(array $filters): LengthAwarePaginator|Collection
    {
        $query = Accessory::query()->with('registeredBy');

        /** Cualquier valor fuera del enum se ignora en vez de vaciar el listado. */
        $status = AccessoryStatus::tryFrom((string) ($filters['status'] ?? ''));

        if ($status !== null) {
            $query->where('status', '=', $status->value);
        }

        /**
         * El término se normaliza como un nombre —colapsando espacios— porque es la
         * búsqueda habitual; name y code están siempre en mayúsculas, así que con eso
         * basta para ser insensible a mayúsculas contra los dos.
         */
        $search = Accessory::normalizeName($filters['search'] ?? '');

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'LIKE', '%'.$search.'%')
                    ->orWhere('code', 'LIKE', '%'.$search.'%');
            });
        }

        /** El inventario se lee en el orden en que se dio de alta. */
        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getAccessoryById(int $id): Accessory
    {
        $accessory = Accessory::query()->with('registeredBy')->find($id);

        if ($accessory === null) {
            throw new NotFoundError('El accesorio no existe');
        }

        return $accessory;
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
