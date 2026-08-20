<?php

namespace App\Services\AccessoryCharacteristic;

use App\Errors\NotFoundError;
use App\Interfaces\AccessoryCharacteristic\AccessoryCharacteristicServiceInterface;
use App\Models\Accessory;
use App\Models\AccessoryCharacteristic;
use Override;

class AccessoryCharacteristicService implements AccessoryCharacteristicServiceInterface
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
    public function getAccessoryCharacteristics(array $filters): array
    {
        /** Primero el accesorio: si no existe es 404, no un listado vacío. */
        $accessory = $this->resolveAccessory((int) $filters['accessoryId']);

        $query = AccessoryCharacteristic::query()
            ->with('registeredBy')
            ->where('accessory_id', '=', $accessory->id);

        /** Orden fijo: el orden en que se capturaron. No hay sortBy ni sortDir. */
        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return [
            'characteristics' => $perPage === null ? $query->get() : $query->paginate($perPage),
        ];
    }

    #[Override]
    public function getAccessoryCharacteristicById(int $id): AccessoryCharacteristic
    {
        $characteristic = AccessoryCharacteristic::query()->with('registeredBy')->find($id);

        if ($characteristic === null) {
            throw new NotFoundError('La característica no existe');
        }

        return $characteristic;
    }

    /**
     * Resolve the accessory a characteristic hangs from.
     *
     * Its status is never looked at: an inactive or under_repair accessory lists,
     * accepts and edits characteristics like any other, because the record of a
     * retired part stays valid information.
     * Throws a NotFoundError when the accessory does not exist.
     */
    private function resolveAccessory(int $id): Accessory
    {
        $accessory = Accessory::query()->find($id);

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
