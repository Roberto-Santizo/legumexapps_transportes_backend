<?php

namespace App\Services\Vehicle;

use App\Enums\UserRole;
use App\Enums\VehicleStatus;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Vehicle\VehicleServiceInterface;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Override;

class VehicleService implements VehicleServiceInterface
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
    public function getVehicles(User $user, array $filters): LengthAwarePaginator|Collection
    {
        $query = Vehicle::query()->with('carrier');

        $scopedCarrierId = $this->resolveScopedCarrierId($user);

        if ($scopedCarrierId !== null) {
            $query->where('carrier_id', '=', $scopedCarrierId);
        } elseif (isset($filters['carrierId']) && is_numeric($filters['carrierId'])) {
            $query->where('carrier_id', '=', (int) $filters['carrierId']);
        }

        $status = isset($filters['status']) ? VehicleStatus::tryFrom($filters['status']) : null;

        if ($status !== null) {
            $query->where('status', '=', $status->value);
        }

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getVehicleById(User $user, int $id): Vehicle
    {
        $vehicle = Vehicle::query()->with('carrier')->find($id);

        if ($vehicle === null) {
            throw new NotFoundError('El vehículo no existe');
        }

        $scopedCarrierId = $this->resolveScopedCarrierId($user);

        if ($scopedCarrierId !== null && $vehicle->carrier_id !== $scopedCarrierId) {
            throw new ForbiddenError('No puedes acceder a un vehículo que no pertenece a tu empresa transportista');
        }

        return $vehicle;
    }

    /**
     * Fail when the given plate is already held by a vehicle still in service.
     *
     * The uniqueness of a plate is conditional — a deactivated vehicle releases
     * it — so it cannot live in a database index and is checked here instead.
     *
     * @param  string  $plate  Already normalized to upper case.
     * @param  int|null  $exceptId  Vehicle updating its own plate, never a conflict with itself.
     */
    private function ensurePlateIsAvailable(string $plate, ?int $exceptId = null): void
    {
        $query = Vehicle::query()
            ->where('plate', '=', $plate)
            ->where('status', '!=', VehicleStatus::Inactive->value);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw new BadRequestError('La placa ya está registrada en un vehículo que no está desactivado');
        }
    }

    /**
     * Resolve the company the given user is restricted to.
     *
     * An administrator has no scope at all; anybody else only reaches the
     * vehicles of the company it belongs to.
     */
    private function resolveScopedCarrierId(User $user): ?int
    {
        if ($user->role === UserRole::Administrator) {
            return null;
        }

        $carrier = $user->currentCarrier();

        if ($carrier === null) {
            throw new ForbiddenError('No perteneces a ninguna empresa transportista');
        }

        return $carrier->id;
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
