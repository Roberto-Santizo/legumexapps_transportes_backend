<?php

namespace App\Interfaces\Pilot;

use App\Models\CarrierPilot;
use App\Models\CarrierPilotSalaryHistory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface PilotServiceInterface
{
    /**
     * List the pilots the given user is allowed to see, with their salary.
     *
     * An administrator and a manager reach every company's pilots; any other role is
     * scoped to its own company. Invalid filters are ignored instead of failing.
     *
     * @param  array{carrierId?: string|null, limit?: string|null}  $filters
     *                                                                       carrierId: only honoured for an administrator or a manager; limit: page size
     *                                                                       requested by the client, clamped to [10, 100].
     * @return LengthAwarePaginator<int, CarrierPilot>|Collection<int, CarrierPilot>
     */
    public function getPilots(User $user, array $filters): LengthAwarePaginator|Collection;

    /**
     * Assign or update the monthly base salary, in GTQ, of the given pilot.
     *
     * The salary row and its log entry are written in the same transaction: a salary
     * cannot end up changed without a trace, nor a trace without the change.
     *
     * @param  int  $userId  the pilot's user_id, not the id of the carrier_pilots row.
     * @param  array{salary: float}  $data
     */
    public function updateSalary(int $userId, array $data, User $user): CarrierPilot;

    /**
     * List the salary changes logged for the given pilot, newest first.
     *
     * @param  int  $userId  the pilot's user_id, not the id of the carrier_pilots row.
     * @param  string|null  $limit  page size requested by the client, clamped to [10, 100].
     * @return LengthAwarePaginator<int, CarrierPilotSalaryHistory>|Collection<int, CarrierPilotSalaryHistory>
     */
    public function getSalaryHistory(int $userId, User $user, ?string $limit): LengthAwarePaginator|Collection;
}
