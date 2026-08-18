<?php

namespace App\Interfaces\VehicleExpense;

use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Models\User;
use App\Models\VehicleExpense;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface VehicleExpenseServiceInterface
{
    /**
     * List the maintenance expenses of a single vehicle.
     *
     * The listing never spans the whole fleet: `vehicleId` is required by the
     * caller and the vehicle resolves both the scope and the company, so there
     * is no carrierId filter. A carrier only reaches the vehicles of its own
     * company; an administrator and a manager reach every company's. The
     * vehicle's status is irrelevant: an inactive vehicle lists its expenses
     * like any other. Invalid filters are ignored instead of failing, so the
     * full listing comes back rather than an empty one.
     *
     * Results are ordered by `expense_date` descending, with `id` descending
     * breaking the tie between two expenses of the same day.
     *
     * @param  array{vehicleId: int, category?: string|null, nature?: string|null, dateFrom?: string|null, dateTo?: string|null, limit?: string|null}  $filters
     *                                                                                                                                                           vehicleId: already validated as required by the FormRequest;
     *                                                                                                                                                           category: value of VehicleExpenseCategory; nature: value of VehicleExpenseNature;
     *                                                                                                                                                           dateFrom/dateTo: Y-m-d bounds on expense_date, both inclusive;
     *                                                                                                                                                           limit: page size requested by the client, clamped to [10, 100].
     * @return array{expenses: LengthAwarePaginator<int, VehicleExpense>|Collection<int, VehicleExpense>, totalAmount: string}
     *                                                                                                                         totalAmount is the sum of every expense matching the filters, formatted to two
     *                                                                                                                         decimals — not the sum of the returned page, and present with or without paging.
     *
     * @throws NotFoundError when the vehicle does not exist
     * @throws ForbiddenError when a carrier reaches a vehicle of another company
     */
    public function getVehicleExpenses(User $user, array $filters): array;

    /**
     * Register an expense against a vehicle, within the user's scope.
     *
     * `registered_by` is taken from the authenticated user, never from the
     * payload, and the vehicle's status is not checked: an inactive vehicle
     * accepts expenses, because the maintenance may predate its deactivation.
     *
     * @param  array{vehicle_id: int, category: string, nature: string, amount: float, expense_date: string, description: string}  $data
     *                                                                                                                                    amount travels in GTQ; expense_date is a Y-m-d day, never in the future.
     *
     * @throws NotFoundError when the vehicle does not exist
     * @throws ForbiddenError when a carrier reaches a vehicle of another company
     */
    public function createVehicleExpense(array $data, User $user): VehicleExpense;

    /**
     * Return the expense matching the given id, within the user's scope.
     *
     * @throws NotFoundError when the expense does not exist
     * @throws ForbiddenError when a carrier reaches an expense of another company
     */
    public function getVehicleExpenseById(User $user, int $id): VehicleExpense;

    /**
     * Update the expense matching the given id, within the user's scope.
     *
     * The vehicle is immutable: `vehicle_id` is not accepted, so an expense
     * never moves between vehicles nor between companies. `registered_by` is
     * not rewritten either — it keeps the user that created the expense, even
     * when an administrator is the one editing it. An empty payload is a no-op
     * that still answers 200.
     *
     * @param  array{category?: string, nature?: string, amount?: float, expense_date?: string, description?: string}  $data
     *
     * @throws NotFoundError when the expense does not exist
     * @throws ForbiddenError when a carrier reaches an expense of another company
     */
    public function updateVehicleExpense(array $data, int $id, User $user): VehicleExpense;

    /**
     * Delete the expense matching the given id, within the user's scope.
     *
     * The deletion is real: the row is removed and a second delete of the same
     * id answers 404. The returned model is the already deleted one, kept so
     * the caller can render what disappeared.
     *
     * @throws NotFoundError when the expense does not exist
     * @throws ForbiddenError when a carrier reaches an expense of another company
     */
    public function deleteVehicleExpense(int $id, User $user): VehicleExpense;
}
