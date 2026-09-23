<?php

namespace App\Services\Dashboard;

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Enums\VehicleCondition;
use App\Enums\VehicleExpenseNature;
use App\Enums\VehicleStatus;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Dashboard\DashboardServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripPosition;
use App\Models\TripTimeout;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Override;

class DashboardService implements DashboardServiceInterface
{
    /**
     * The only date format the range filters accept, as in `GET /api/trips`.
     */
    private const DATE_FORMAT = 'Y-m-d';

    /**
     * Smallest page size accepted by the fleet listing, as in the rest of the project.
     */
    private const MIN_PER_PAGE = 10;

    /**
     * Largest page size accepted, so nobody asks for the whole fleet as one page.
     */
    private const MAX_PER_PAGE = 100;

    #[Override]
    public function getTripsSummary(User $user, array $filters): array
    {
        $query = $this->applyTripFilters(Trip::query(), $this->resolveEffectiveCarrierId($user, $filters), $filters);

        /**
         * Cada bloque sale de la misma consulta base clonada: el ámbito de los filtros se
         * aplica una vez y ningún desglose puede desviarse de él.
         */
        $countByStatus = (clone $query)
            ->selectRaw('trips.status, count(*) as total')
            ->groupBy('trips.status')
            ->toBase()
            ->pluck('total', 'status');

        $unassigned = (clone $query)
            ->where('trips.status', TripStatus::Pending)
            ->whereNull('trips.pilot_id')
            ->whereNull('trips.vehicle_id')
            ->count();

        /**
         * La empresa del viaje es la de assigned_by, resuelta en SQL: /assignment exige
         * role:carrier, así que assigned_by es siempre el dueño de una empresa. Los viajes
         * sin asignar no tienen empresa y quedan fuera del desglose, por eso su suma puede
         * ser menor que total.
         */
        $byCarrier = (clone $query)
            ->join('carriers', 'carriers.user_id', '=', 'trips.assigned_by')
            ->selectRaw('carriers.id as carrier_id, carriers.name as carrier_name, count(*) as total')
            ->groupBy('carriers.id', 'carriers.name')
            ->orderByDesc('total')
            ->orderBy('carriers.id')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'carrierId' => (int) $row->carrier_id,
                'carrierName' => (string) $row->carrier_name,
                'total' => (int) $row->total,
            ])
            ->all();

        $byClient = $this->countTripsBy($query, 'clients', 'client_id', 'clientId', 'clientName');
        $byShippingLine = $this->countTripsBy($query, 'shipping_lines', 'shipping_line_id', 'shippingLineId', 'shippingLineName');
        $byLocation = $this->countTripsBy($query, 'locations', 'location_id', 'locationId', 'locationName');

        $byMonth = (clone $query)
            ->selectRaw("to_char(trips.recolection_date, 'YYYY-MM') as month, count(*) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'month' => (string) $row->month,
                'total' => (int) $row->total,
            ])
            ->all();

        return [
            'total' => (int) $countByStatus->sum(),
            'unassigned' => $unassigned,
            'byStatus' => [
                'pending' => (int) ($countByStatus[TripStatus::Pending->value] ?? 0),
                'inRoute' => (int) ($countByStatus[TripStatus::InRoute->value] ?? 0),
                'finished' => (int) ($countByStatus[TripStatus::Finished->value] ?? 0),
            ],
            'byCarrier' => $byCarrier,
            'byClient' => $byClient,
            'byShippingLine' => $byShippingLine,
            'byLocation' => $byLocation,
            'byMonth' => $byMonth,
        ];
    }

    #[Override]
    public function getTripsInRoute(User $user, array $filters): Collection
    {
        $query = Trip::query()
            ->where('trips.status', TripStatus::InRoute)
            /** La empresa del viaje es la de assigned_by, siempre un dueño: su HasOne carrier, sin consulta por viaje. */
            ->with(['assignedBy.carrier', 'pilot', 'vehicle', 'client', 'location'])
            ->withSum(['fuels as total_fuel_gallons' => fn (Builder $fuels) => $fuels->whereNotNull('loaded_at')], 'gallons')
            ->withSum(['fuels as unconfirmed_fuel_gallons' => fn (Builder $fuels) => $fuels->whereNull('loaded_at')], 'gallons')
            ->orderByDesc('trips.start_date')
            ->orderByDesc('trips.id');

        $carrierId = $this->resolveEffectiveCarrierId($user, $filters);

        if ($carrierId !== null) {
            $this->whereTripsTakenBy($query, $carrierId);
        }

        $trips = $query->get();

        if ($trips->isEmpty()) {
            return $trips;
        }

        /**
         * Dos consultas por el conjunto entero, indexadas por viaje en PHP: el número de
         * consultas no depende de cuántos viajes haya en curso.
         */
        $lastPositions = $this->latestPositionsByTrip($trips->modelKeys());

        $openTimeouts = TripTimeout::query()
            ->whereIn('trip_id', $trips->modelKeys())
            ->whereNull('ended_at')
            ->get()
            ->keyBy('trip_id');

        foreach ($trips as $trip) {
            $trip->setAttribute('lastPosition', $lastPositions->get($trip->id));
            $trip->setAttribute('openTimeout', $openTimeouts->get($trip->id));
        }

        return $trips;
    }

    #[Override]
    public function getVehicleExpensesSummary(User $user, array $filters): array
    {
        $query = $this->applyVehicleExpenseFilters(VehicleExpense::query(), $this->resolveEffectiveCarrierId($user, $filters), $filters);

        $totals = (clone $query)
            ->selectRaw('count(*) as count, coalesce(sum(vehicle_expenses.amount), 0) as total_amount')
            ->toBase()
            ->first();

        $byCategory = (clone $query)
            ->selectRaw('vehicle_expenses.category, count(*) as count, sum(vehicle_expenses.amount) as total_amount')
            ->groupBy('vehicle_expenses.category')
            ->orderByDesc('total_amount')
            ->orderBy('vehicle_expenses.category')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'category' => (string) $row->category,
                'count' => (int) $row->count,
                'totalAmount' => $this->money($row->total_amount),
            ])
            ->all();

        $byNature = (clone $query)
            ->selectRaw('vehicle_expenses.nature, count(*) as count, sum(vehicle_expenses.amount) as total_amount')
            ->groupBy('vehicle_expenses.nature')
            ->toBase()
            ->get()
            ->keyBy('nature');

        $byInvoiced = (clone $query)
            ->selectRaw('vehicle_expenses.is_invoiced, count(*) as count, sum(vehicle_expenses.amount) as total_amount')
            ->groupBy('vehicle_expenses.is_invoiced')
            ->toBase()
            ->get()
            ->keyBy(fn (object $row): string => $row->is_invoiced ? 'invoiced' : 'notInvoiced');

        /**
         * La empresa del gasto es la del vehículo, esté como esté: un gasto de un vehículo
         * dado de baja sigue contando, porque el mantenimiento pudo ocurrir antes.
         */
        $byCarrier = (clone $query)
            ->join('carriers', 'carriers.id', '=', 'vehicles.carrier_id')
            ->selectRaw('carriers.id as carrier_id, carriers.name as carrier_name, count(*) as count, sum(vehicle_expenses.amount) as total_amount')
            ->groupBy('carriers.id', 'carriers.name')
            ->orderByDesc('total_amount')
            ->orderBy('carriers.id')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'carrierId' => (int) $row->carrier_id,
                'carrierName' => (string) $row->carrier_name,
                'count' => (int) $row->count,
                'totalAmount' => $this->money($row->total_amount),
            ])
            ->all();

        $byMonth = (clone $query)
            ->selectRaw("to_char(vehicle_expenses.expense_date, 'YYYY-MM') as month, count(*) as count, sum(vehicle_expenses.amount) as total_amount")
            ->groupBy('month')
            ->orderBy('month')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'month' => (string) $row->month,
                'count' => (int) $row->count,
                'totalAmount' => $this->money($row->total_amount),
            ])
            ->all();

        return [
            'totalAmount' => $this->money($totals->total_amount ?? 0),
            'count' => (int) ($totals->count ?? 0),
            'byCategory' => $byCategory,
            'byNature' => [
                'preventive' => $this->countAndAmount($byNature->get(VehicleExpenseNature::Preventive->value)),
                'corrective' => $this->countAndAmount($byNature->get(VehicleExpenseNature::Corrective->value)),
            ],
            'invoiced' => $this->countAndAmount($byInvoiced->get('invoiced')),
            'notInvoiced' => $this->countAndAmount($byInvoiced->get('notInvoiced')),
            'byCarrier' => $byCarrier,
            'byMonth' => $byMonth,
        ];
    }

    #[Override]
    public function getVehicles(User $user, array $filters): Collection|LengthAwarePaginator
    {
        /** Toda la flota, incluidos los dados de baja: el tablero muestra cada vehículo tal como está. */
        $query = Vehicle::query()->with('carrier')->orderBy('id');

        $carrierId = $this->resolveEffectiveCarrierId($user, $filters);

        if ($carrierId !== null) {
            $query->where('carrier_id', $carrierId);
        }

        $status = is_string($filters['status'] ?? null) ? VehicleStatus::tryFrom($filters['status']) : null;

        if ($status !== null) {
            $query->where('status', $status);
        }

        $condition = is_string($filters['condition'] ?? null) ? VehicleCondition::tryFrom($filters['condition']) : null;

        if ($condition !== null) {
            $query->where('condition', $condition);
        }

        /**
         * Antes de paginar, para que total refleje el recorte. El subconjunto es el de
         * vehículos con algún viaje en curso; `false` es su complemento exacto.
         */
        $inRoute = is_string($filters['inRoute'] ?? null)
            ? filter_var($filters['inRoute'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        if ($inRoute !== null) {
            $vehiclesInRoute = Trip::query()
                ->where('status', TripStatus::InRoute)
                ->whereNotNull('vehicle_id')
                ->select('vehicle_id');

            $inRoute
                ? $query->whereIn('id', $vehiclesInRoute)
                : $query->whereNotIn('id', $vehiclesInRoute);
        }

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        $vehicles = $perPage === null ? $query->get() : $query->paginate($perPage);

        $this->attachCurrentTrips($perPage === null ? $vehicles : $vehicles->getCollection());

        return $vehicles;
    }

    #[Override]
    public function getVehicle(User $user, int $id): Vehicle
    {
        $vehicle = Vehicle::query()->with('carrier')->find($id);

        if ($vehicle === null) {
            throw new NotFoundError('El vehículo no existe');
        }

        $carrierId = $this->resolveEffectiveCarrierId($user, []);

        if ($carrierId !== null && $vehicle->carrier_id !== $carrierId) {
            throw new ForbiddenError('No puedes acceder a un vehículo que no pertenece a tu empresa transportista');
        }

        $this->attachCurrentTrips(new Collection([$vehicle]));

        return $vehicle;
    }

    /**
     * Narrow a trips query with the tolerant filters of the summary.
     *
     * Every column is qualified with `trips.` because the breakdowns join other tables
     * on top of the very same query. The range cuts on `recolection_date` by whole day.
     * The company, already resolved against the caller, arrives apart from the filters.
     *
     * @param  Builder<Trip>  $query
     * @param  array{dateFrom?: mixed, dateTo?: mixed}  $filters
     * @return Builder<Trip>
     */
    private function applyTripFilters(Builder $query, ?int $carrierId, array $filters): Builder
    {
        if ($carrierId !== null) {
            $this->whereTripsTakenBy($query, $carrierId);
        }

        $dateFrom = $this->resolveDate($filters['dateFrom'] ?? null);

        if ($dateFrom !== null) {
            $query->whereDate('trips.recolection_date', '>=', $dateFrom);
        }

        $dateTo = $this->resolveDate($filters['dateTo'] ?? null);

        if ($dateTo !== null) {
            $query->whereDate('trips.recolection_date', '<=', $dateTo);
        }

        return $query;
    }

    /**
     * Narrow a vehicle expenses query with the tolerant filters of the summary.
     *
     * The join with `vehicles` is always there, because the company of an expense is
     * the company of its vehicle; the `select` stays on the caller. The range cuts on
     * `expense_date`, a plain date, so a straight comparison is already by whole day.
     * The company, already resolved against the caller, arrives apart from the filters.
     *
     * @param  Builder<VehicleExpense>  $query
     * @param  array{dateFrom?: mixed, dateTo?: mixed}  $filters
     * @return Builder<VehicleExpense>
     */
    private function applyVehicleExpenseFilters(Builder $query, ?int $carrierId, array $filters): Builder
    {
        $query->join('vehicles', 'vehicles.id', '=', 'vehicle_expenses.vehicle_id');

        if ($carrierId !== null) {
            $query->where('vehicles.carrier_id', $carrierId);
        }

        $dateFrom = $this->resolveDate($filters['dateFrom'] ?? null);

        if ($dateFrom !== null) {
            $query->where('vehicle_expenses.expense_date', '>=', $dateFrom);
        }

        $dateTo = $this->resolveDate($filters['dateTo'] ?? null);

        if ($dateTo !== null) {
            $query->where('vehicle_expenses.expense_date', '<=', $dateTo);
        }

        return $query;
    }

    /**
     * The last recorded position of each of the given trips, keyed by trip id.
     *
     * One query for the whole set with Postgres' `DISTINCT ON (trip_id)`: the first row
     * per trip in `recorded_at desc, id desc` order is the newest point. Postgres only,
     * which the project already requires since PostGIS (SPEC 08); this is the single
     * method to rewrite if that ever changes. `Trip` does not gain `positions()`.
     *
     * @param  list<int>  $tripIds
     * @return Collection<int, TripPosition>
     */
    private function latestPositionsByTrip(array $tripIds): Collection
    {
        return TripPosition::query()
            ->selectRaw('distinct on (trip_id) trip_positions.*')
            ->whereIn('trip_id', $tripIds)
            ->orderBy('trip_id')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get()
            ->keyBy('trip_id');
    }

    /**
     * Attach to every vehicle of the page its current `in_route` trip, or null.
     *
     * One query for the whole set, indexed by `vehicle_id` in PHP keeping the first
     * trip in `start_date desc, id desc` order, so the Resource never queries anything.
     * It is a transient attribute, not a relation: `Vehicle` does not gain `trips()`.
     *
     * @param  Collection<int, Vehicle>  $vehicles
     */
    private function attachCurrentTrips(Collection $vehicles): void
    {
        if ($vehicles->isEmpty()) {
            return;
        }

        $currentTrips = Trip::query()
            ->where('status', TripStatus::InRoute)
            ->whereIn('vehicle_id', $vehicles->modelKeys())
            ->with('pilot')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->unique('vehicle_id')
            ->keyBy('vehicle_id');

        foreach ($vehicles as $vehicle) {
            $vehicle->setAttribute('currentTrip', $currentTrips->get($vehicle->id));
        }
    }

    /**
     * Clamp the requested page size, or return null when the client did not ask to paginate.
     */
    private function resolvePerPage(mixed $limit): ?int
    {
        if (! is_string($limit) || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }

    /**
     * Shape one aggregated row as `{count, totalAmount}`, zeroed when there is no row.
     *
     * @return array{count: int, totalAmount: string}
     */
    private function countAndAmount(?object $row): array
    {
        return [
            'count' => (int) ($row->count ?? 0),
            'totalAmount' => $this->money($row->total_amount ?? 0),
        ];
    }

    /**
     * Format an amount as the two decimal string every money field of the project uses.
     */
    private function money(int|float|string|null $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }

    /**
     * Count the trips of the base query grouped by one of its catalog relations.
     *
     * The join resolves the name in SQL; only the rows with at least one trip come
     * back, ordered by `total desc, id asc`.
     *
     * @param  Builder<Trip>  $query
     * @return list<array<string, int|string>>
     */
    private function countTripsBy(Builder $query, string $table, string $foreignKey, string $idKey, string $nameKey): array
    {
        return (clone $query)
            ->join($table, "{$table}.id", '=', "trips.{$foreignKey}")
            ->selectRaw("{$table}.id as entity_id, {$table}.name as entity_name, count(*) as total")
            ->groupBy("{$table}.id", "{$table}.name")
            ->orderByDesc('total')
            ->orderBy("{$table}.id")
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                $idKey => (int) $row->entity_id,
                $nameKey => (string) $row->entity_name,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Narrow a trips query to the ones taken by the given company.
     *
     * The company of a trip is the one of `assigned_by`, always an owner because
     * `/assignment` requires `role:carrier`; the subquery resolves it in SQL.
     *
     * @param  Builder<Trip>  $query
     */
    private function whereTripsTakenBy(Builder $query, int $carrierId): void
    {
        $query->whereIn('trips.assigned_by', function ($subQuery) use ($carrierId): void {
            $subQuery->select('user_id')->from('carriers')->where('id', $carrierId);
        });
    }

    /**
     * Resolve the company every block of the dashboard is narrowed to, or null for all.
     *
     * `administrator`, `manager` and `export` have no scope: for them the answer is the voluntary
     * `carrierId` filter, tolerant as ever. Anybody else is pinned to its own company
     * —resolved against the database, never the token claim— and its `carrierId` is
     * ignored, so a `carrier` can never read another company's numbers. Without a
     * company it is a 403, the same rule `VehicleService` applies.
     *
     * @param  array{carrierId?: mixed}  $filters
     */
    private function resolveEffectiveCarrierId(User $user, array $filters): ?int
    {
        if (in_array($user->role, [UserRole::Administrator, UserRole::Manager, UserRole::Export], true)) {
            return $this->resolveCarrierId($filters['carrierId'] ?? null);
        }

        $carrier = $user->currentCarrier();

        if ($carrier === null) {
            throw new ForbiddenError('No perteneces a ninguna empresa transportista');
        }

        return $carrier->id;
    }

    /**
     * Read the `carrierId` filter, returning null unless it names an existing company.
     *
     * Tolerant on purpose: a non numeric or unknown id is ignored and the whole history
     * comes back, never an empty summary.
     */
    private function resolveCarrierId(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $carrierId = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

        if ($carrierId === null || $carrierId < 1) {
            return null;
        }

        return Carrier::query()->whereKey($carrierId)->exists() ? $carrierId : null;
    }

    /**
     * Read a date filter, returning null for anything that is not exactly a Y-m-d date.
     *
     * Tolerant on purpose, like every other filter of the project: a malformed value is
     * ignored and the summary covers the whole history, instead of failing with a 422.
     */
    private function resolveDate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $value);

        if ($date === false || $date->format(self::DATE_FORMAT) !== $value) {
            return null;
        }

        return $date->format(self::DATE_FORMAT);
    }
}
