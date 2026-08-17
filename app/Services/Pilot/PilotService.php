<?php

namespace App\Services\Pilot;

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Pilot\PilotServiceInterface;
use App\Models\CarrierPilot;
use App\Models\CarrierPilotSalaryHistory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Override;

class PilotService implements PilotServiceInterface
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
     * Roles that reach every company's pilots, with no scope of their own.
     */
    private const UNSCOPED_ROLES = [UserRole::Administrator, UserRole::Manager];

    #[Override]
    public function getPilots(User $user, array $filters): LengthAwarePaginator|Collection
    {
        $query = CarrierPilot::query()->with('user', 'carrier');

        $scopedCarrierId = $this->resolveScopedCarrierId($user);

        if ($scopedCarrierId !== null) {
            $query->where('carrier_id', '=', $scopedCarrierId);
        } elseif (isset($filters['carrierId']) && is_numeric($filters['carrierId'])) {
            $query->where('carrier_id', '=', (int) $filters['carrierId']);
        }

        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function updateSalary(int $userId, array $data, User $user): CarrierPilot
    {
        return DB::transaction(function () use ($userId, $data, $user): CarrierPilot {
            $pilot = $this->resolvePilot($user, $userId, lockForUpdate: true);

            $newSalary = $this->salaryValue($data['salary']);
            $previousSalary = $pilot->salary;

            /** La comparación va sobre el valor formateado: 4500, 4500.00 y 4500.004 son el mismo salario. */
            if ($previousSalary !== null && $this->salaryValue($previousSalary) === $newSalary) {
                throw new BadRequestError('El salario indicado es el mismo que el piloto ya tiene registrado');
            }

            $pilot->salary = $newSalary;
            $pilot->save();

            /** Misma transacción que el UPDATE: a medias quedaría un salario sin rastro o un rastro de un cambio que no ocurrió. */
            CarrierPilotSalaryHistory::create([
                'carrier_pilot_id' => $pilot->id,
                'previous_salary' => $previousSalary,
                'new_salary' => $newSalary,
                /** Sale del usuario autenticado, nunca del cuerpo de la petición. */
                'changed_by' => $user->id,
            ]);

            return $pilot;
        });
    }

    #[Override]
    public function getSalaryHistory(int $userId, User $user, ?string $limit): LengthAwarePaginator|Collection
    {
        /** Misma guarda que el PATCH: mismo 404 y mismo 403. */
        $pilot = $this->resolvePilot($user, $userId);

        /**
         * Ordena por id y no por created_at: dos cambios en el mismo segundo empatarían
         * la fecha y el orden quedaría indefinido.
         */
        $query = $pilot->salaryHistories()->with('changedBy')->orderByDesc('id');

        $perPage = $this->resolvePerPage($limit);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    /**
     * Resolve the carrier_pilots row of the given pilot, within the user's scope.
     *
     * The 404 covers two cases on purpose: a user_id that does not exist and a user
     * that exists but is not a pilot of any company. Telling them apart would leak
     * which user ids are registered in the system.
     *
     * @param  int  $userId  the pilot's user_id, not the id of the carrier_pilots row.
     * @param  bool  $lockForUpdate  Held while a salary change is written.
     */
    private function resolvePilot(User $user, int $userId, bool $lockForUpdate = false): CarrierPilot
    {
        $query = CarrierPilot::query()->where('user_id', '=', $userId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $pilot = $query->first();

        if ($pilot === null) {
            throw new NotFoundError('El piloto no existe o no está vinculado a ninguna empresa transportista');
        }

        $scopedCarrierId = $this->resolveScopedCarrierId($user);

        if ($scopedCarrierId !== null && $pilot->carrier_id !== $scopedCarrierId) {
            throw new ForbiddenError('No puedes acceder a un piloto que no pertenece a tu empresa transportista');
        }

        return $pilot;
    }

    /**
     * Render a salary the way the column stores it, with exactly two decimals.
     *
     * Comparing what arrives against what the table holds only works when both have
     * the same shape: without this, a 4500.004 would look like a different salary and
     * would slip into the log as a change that never happened.
     */
    private function salaryValue(int|float|string $salary): string
    {
        return number_format((float) $salary, 2, '.', '');
    }

    /**
     * Resolve the company the given user is restricted to.
     *
     * An administrator and a manager have no scope at all — they read every
     * company's pilots; anybody else only reaches the pilots of the company it
     * belongs to.
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
