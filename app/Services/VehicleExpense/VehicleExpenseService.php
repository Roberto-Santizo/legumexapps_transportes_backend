<?php

namespace App\Services\VehicleExpense;

use App\Enums\UserRole;
use App\Enums\VehicleExpenseCategory;
use App\Enums\VehicleExpenseNature;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\VehicleExpense\VehicleExpenseServiceInterface;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use Override;

class VehicleExpenseService implements VehicleExpenseServiceInterface
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
     * Roles that reach every company's vehicles instead of just their own.
     */
    private const UNSCOPED_ROLES = [UserRole::Administrator, UserRole::Manager];

    /**
     * Format `expense_date` bounds travel in.
     */
    private const DATE_FORMAT = 'Y-m-d';

    #[Override]
    public function getVehicleExpenses(User $user, array $filters): array
    {
        $vehicle = $this->resolveVehicle($user, (int) $filters['vehicleId']);

        $query = VehicleExpense::query()
            ->with('registeredBy')
            ->where('vehicle_id', '=', $vehicle->id);

        $category = isset($filters['category']) ? VehicleExpenseCategory::tryFrom($filters['category']) : null;

        if ($category !== null) {
            $query->where('category', '=', $category->value);
        }

        $nature = isset($filters['nature']) ? VehicleExpenseNature::tryFrom($filters['nature']) : null;

        if ($nature !== null) {
            $query->where('nature', '=', $nature->value);
        }

        $dateFrom = $this->normalizeDate($filters['dateFrom'] ?? null);

        if ($dateFrom !== null) {
            $query->where('expense_date', '>=', $dateFrom);
        }

        $dateTo = $this->normalizeDate($filters['dateTo'] ?? null);

        if ($dateTo !== null) {
            $query->where('expense_date', '<=', $dateTo);
        }

        /** El acumulado se calcula sobre la consulta ya filtrada y ANTES de paginar: es la suma de todos los gastos que cumplen los filtros, no la de la página devuelta. */
        $totalAmount = (clone $query)->sum('amount');

        $query->orderByDesc('expense_date')->orderByDesc('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return [
            'expenses' => $perPage === null ? $query->get() : $query->paginate($perPage),
            'totalAmount' => number_format((float) $totalAmount, 2, '.', ''),
        ];
    }

    /**
     * Resolve the vehicle an expense hangs from, within the user's scope.
     *
     * The guard shared by every endpoint of the domain: the vehicle must exist
     * and, for a carrier, belong to its own company. The vehicle's status is
     * never checked — an inactive vehicle takes expenses like any other,
     * because the maintenance may predate its deactivation.
     *
     * @throws NotFoundError when the vehicle does not exist
     * @throws ForbiddenError when a carrier reaches a vehicle of another company
     */
    private function resolveVehicle(User $user, int $vehicleId): Vehicle
    {
        $vehicle = Vehicle::query()->find($vehicleId);

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
     * Company the given user is confined to, or null when it reaches them all.
     *
     * @throws ForbiddenError when a scoped user belongs to no company
     */
    private function resolveScopedCarrierId(User $user): ?int
    {
        if (in_array($user->role, self::UNSCOPED_ROLES, true)) {
            return null;
        }

        $carrier = $user->currentCarrier();

        if ($carrier === null) {
            throw new ForbiddenError('No perteneces a ninguna empresa transportista');
        }

        return $carrier->id;
    }

    /**
     * Page size to apply, or null when the caller did not ask for pagination.
     */
    private function resolvePerPage(?string $limit): ?int
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }

    /**
     * Validate a date bound of the listing, or null when it is unusable.
     *
     * Tolerant like every other filter of the project: a malformed bound is
     * ignored instead of failing, so the caller gets the full listing rather
     * than a 422. The round trip through the format is what rejects a date that
     * parses but does not exist, such as 2026-13-45.
     */
    private function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $value);

        if ($date === false || $date->format(self::DATE_FORMAT) !== $value) {
            return null;
        }

        return $date->format(self::DATE_FORMAT);
    }
}
