<?php

namespace App\Interfaces\Dashboard;

use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cross-domain read model for `administrator`, `manager` and `carrier`.
 *
 * The first domain of the project that owns neither a table nor a write path and
 * reads from other domains: it only aggregates what `trips`, `vehicles`,
 * `vehicle_expenses`, `trip_positions`, `trip_fuels` and `trip_timeouts` already
 * store. `administrator` and `manager` have **no scope**: they already see
 * everything domain by domain, so for them `carrierId` is a voluntary filter. A
 * `carrier` is always narrowed to its own company (`User::currentCarrier()`, never
 * the token claim) and its `carrierId` is ignored; without a company it gets a
 * `ForbiddenError`, as in `VehicleService`.
 *
 * Every filter arrives as it came in the query string and is normalized here with
 * `filter_var(..., FILTER_NULL_ON_FAILURE)` / `tryFrom()`, as in the rest of the
 * project: an invalid value is ignored, never a 422.
 */
interface DashboardServiceInterface
{
    /**
     * Aggregate the trips: totals, the free pool and the breakdowns by status,
     * company, client, shipping line, port and month.
     *
     * Deleted trips are always out. The date range cuts on `recolection_date` by whole
     * day; without dates the whole history is aggregated. `carrierId` narrows **every**
     * block to the trips assigned by that company —with it `unassigned` is always 0—.
     *
     * @param  array{carrierId?: mixed, dateFrom?: mixed, dateTo?: mixed}  $filters
     * @return array{
     *     total: int,
     *     unassigned: int,
     *     byStatus: array{pending: int, inRoute: int, finished: int},
     *     byCarrier: list<array{carrierId: int, carrierName: string, total: int}>,
     *     byClient: list<array{clientId: int, clientName: string, total: int}>,
     *     byShippingLine: list<array{shippingLineId: int, shippingLineName: string, total: int}>,
     *     byLocation: list<array{locationId: int, locationName: string, total: int}>,
     *     byMonth: list<array{month: string, total: int}>
     * }
     */
    public function getTripsSummary(User $user, array $filters): array;

    /**
     * Every trip currently `in_route`, newest start first, with its last recorded
     * position, its fuel sums and its open stop attached as transient attributes.
     *
     * Not paginated: they are the trips in route *now*, dozens at most. The number of
     * queries does not depend on the number of trips: last positions and open stops
     * are fetched once per set and indexed by trip in PHP. Dates are ignored.
     *
     * @param  array{carrierId?: mixed}  $filters
     * @return Collection<int, Trip>
     */
    public function getTripsInRoute(User $user, array $filters): Collection;

    /**
     * Aggregate the vehicle expenses: total, count and the breakdowns by category,
     * nature, invoicing, company and month.
     *
     * The company comes from `vehicles.carrier_id`, whatever the vehicle status. The
     * date range cuts on `expense_date` by whole day; without dates the whole history
     * is aggregated.
     *
     * @param  array{carrierId?: mixed, dateFrom?: mixed, dateTo?: mixed}  $filters
     * @return array{
     *     totalAmount: string,
     *     count: int,
     *     byCategory: list<array{category: string, count: int, totalAmount: string}>,
     *     byNature: array{preventive: array{count: int, totalAmount: string}, corrective: array{count: int, totalAmount: string}},
     *     invoiced: array{count: int, totalAmount: string},
     *     notInvoiced: array{count: int, totalAmount: string},
     *     byCarrier: list<array{carrierId: int, carrierName: string, count: int, totalAmount: string}>,
     *     byMonth: list<array{month: string, count: int, totalAmount: string}>
     * }
     */
    public function getVehicleExpensesSummary(User $user, array $filters): array;

    /**
     * The whole fleet —`inactive` included— in `id ASC` order, each vehicle carrying
     * its current `in_route` trip (or null) as a transient attribute.
     *
     * Pagination is opt-in through `limit`, clamped to [10, 100]. The `inRoute` filter
     * is applied **before** paginating so `total` reflects the cut.
     *
     * @param  array{carrierId?: mixed, status?: mixed, condition?: mixed, inRoute?: mixed, limit?: mixed}  $filters
     * @return Collection<int, Vehicle>|LengthAwarePaginator<int, Vehicle>
     */
    public function getVehicles(User $user, array $filters): Collection|LengthAwarePaginator;
}
