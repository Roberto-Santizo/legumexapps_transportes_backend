<?php

namespace App\Services\Pilot;

use App\Enums\UserRole;
use App\Errors\ForbiddenError;
use App\Interfaces\Pilot\PilotServiceInterface;
use App\Models\CarrierPilot;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
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
