<?php

namespace App\Services\Dashboard;

use App\Enums\TripStatus;
use App\Interfaces\Dashboard\DashboardServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use Override;

class DashboardService implements DashboardServiceInterface
{
    /**
     * The only date format the range filters accept, as in `GET /api/trips`.
     */
    private const DATE_FORMAT = 'Y-m-d';

    #[Override]
    public function getTripsSummary(array $filters): array
    {
        $query = $this->applyTripFilters(Trip::query(), $filters);

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
    public function getTripsInRoute(array $filters): Collection
    {
        throw new LogicException('Not implemented');
    }

    #[Override]
    public function getVehicleExpensesSummary(array $filters): array
    {
        throw new LogicException('Not implemented');
    }

    #[Override]
    public function getVehicles(array $filters): Collection|LengthAwarePaginator
    {
        throw new LogicException('Not implemented');
    }

    /**
     * Narrow a trips query with the tolerant filters of the summary.
     *
     * Every column is qualified with `trips.` because the breakdowns join other tables
     * on top of the very same query. The range cuts on `recolection_date` by whole day.
     *
     * @param  Builder<Trip>  $query
     * @param  array{carrierId?: mixed, dateFrom?: mixed, dateTo?: mixed}  $filters
     * @return Builder<Trip>
     */
    private function applyTripFilters(Builder $query, array $filters): Builder
    {
        $carrierId = $this->resolveCarrierId($filters['carrierId'] ?? null);

        if ($carrierId !== null) {
            $query->whereIn('trips.assigned_by', function ($subQuery) use ($carrierId): void {
                $subQuery->select('user_id')->from('carriers')->where('id', $carrierId);
            });
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
