<?php

namespace App\Services\Carrier;

use App\Errors\NotFoundError;
use App\Interfaces\Carrier\CarrierServiceInterface;
use App\Models\Carrier;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Override;

class CarrierService implements CarrierServiceInterface
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
    public function getCarriers(?string $limit): LengthAwarePaginator|Collection
    {
        $perPage = $this->resolvePerPage($limit);

        $query = Carrier::query();

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getCarrierById(int $id): Carrier
    {
        $carrier = Carrier::query()->find($id);

        if ($carrier === null) {
            throw new NotFoundError('El transportista no existe');
        }

        return $carrier;
    }

    #[Override]
    public function getMyCarrier(User $user): Carrier
    {
        $carrier = $user->carrier;

        if ($carrier === null) {
            throw new NotFoundError('Todavía no has registrado tu empresa transportista');
        }

        return $carrier;
    }

    #[Override]
    public function getMyPilots(User $user, ?string $limit): LengthAwarePaginator|Collection
    {
        $carrier = $this->getMyCarrier($user);

        $perPage = $this->resolvePerPage($limit);

        $query = $carrier->pilots();

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    /**
     * Resolve the page size requested by the client.
     *
     * A missing or non numeric limit means "do not paginate"; a numeric one is
     * clamped to [10, 100].
     */
    private function resolvePerPage(?string $limit): ?int
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }
}
