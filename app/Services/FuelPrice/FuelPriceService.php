<?php

namespace App\Services\FuelPrice;

use App\Enums\FuelPriceStatus;
use App\Enums\FuelType;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\FuelPrice\FuelPriceServiceInterface;
use App\Models\FuelPrice;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Override;

class FuelPriceService implements FuelPriceServiceInterface
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
    public function getFuelPrices(array $filters): LengthAwarePaginator|Collection
    {
        $query = FuelPrice::query()->with('registeredBy');

        $fuelType = isset($filters['fuelType']) ? FuelType::tryFrom($filters['fuelType']) : null;

        if ($fuelType !== null) {
            $query->where('fuel_type', '=', $fuelType->value);
        }

        $status = isset($filters['status']) ? FuelPriceStatus::tryFrom($filters['status']) : null;

        if ($status !== null) {
            $query->where('status', '=', $status->value);
        }

        /** El id desempata las altas del mismo instante, que en SQLite son habituales. */
        $query->orderByDesc('created_at')->orderByDesc('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getFuelPriceById(int $id): FuelPrice
    {
        $fuelPrice = FuelPrice::query()->with('registeredBy')->find($id);

        if ($fuelPrice === null) {
            throw new NotFoundError('El precio de combustible no existe');
        }

        return $fuelPrice;
    }

    #[Override]
    public function getCurrentByType(string $fuelType): FuelPrice
    {
        $fuelPrice = FuelPrice::query()
            ->with('registeredBy')
            ->where('fuel_type', '=', $fuelType)
            ->where('status', '=', FuelPriceStatus::Active->value)
            ->first();

        if ($fuelPrice === null) {
            throw new NotFoundError('No existe un precio vigente para el combustible indicado');
        }

        return $fuelPrice;
    }

    #[Override]
    public function create(User $user, array $data): FuelPrice
    {
        /** Desactivar y crear van juntos: a medias, el tipo quedaría con cero o con dos vigentes. */
        return DB::transaction(function () use ($user, $data): FuelPrice {
            $fuelType = FuelType::from($data['fuelType']);

            $current = FuelPrice::query()
                ->where('fuel_type', '=', $fuelType->value)
                ->where('status', '=', FuelPriceStatus::Active->value)
                /** El bloqueo evita que dos altas simultáneas del mismo tipo dejen dos filas vigentes. */
                ->lockForUpdate()
                ->first();

            if ($current !== null) {
                $current->status = FuelPriceStatus::Inactive;

                $current->save();
            }

            $fuelPrice = FuelPrice::create([
                'fuel_type' => $fuelType,
                'price' => $data['price'],
                'status' => FuelPriceStatus::Active,
                'registered_by' => $user->id,
            ]);

            return $fuelPrice->load('registeredBy');
        });
    }

    #[Override]
    public function update(int $id, array $data): FuelPrice
    {
        //
    }

    #[Override]
    public function deactivate(int $id): FuelPrice
    {
        //
    }

    #[Override]
    public function destroy(int $id): FuelPrice
    {
        //
    }

    /**
     * Resolve the row matching the given id, refusing to touch the history.
     *
     * Every write of this domain goes through here: once a price has been
     * displaced it is read-only, so an inactive row is a client error, not a
     * missing one. Throws a NotFoundError when the row does not exist and a
     * BadRequestError when it is already inactive.
     */
    private function resolveActiveFuelPrice(int $id): FuelPrice
    {
        $fuelPrice = $this->getFuelPriceById($id);

        if ($fuelPrice->status === FuelPriceStatus::Inactive) {
            throw new BadRequestError('Solo se puede modificar el precio vigente');
        }

        return $fuelPrice;
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
