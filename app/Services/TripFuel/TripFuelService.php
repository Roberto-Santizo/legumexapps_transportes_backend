<?php

namespace App\Services\TripFuel;

use App\Interfaces\Trip\TripServiceInterface;
use App\Interfaces\TripFuel\TripFuelServiceInterface;
use App\Models\TripFuel;
use App\Models\User;
use Override;

class TripFuelService implements TripFuelServiceInterface
{
    /**
     * Smallest page size accepted, as in the rest of the project.
     */
    private const MIN_PER_PAGE = 10;

    /**
     * Largest page size accepted, so nobody asks for the whole table at once.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * The trip domain resolves both the trip and the reading scope of SPEC 24.
     *
     * Injected by constructor —the by method parameter rule is the controller's alone—
     * so this service never rewrites that matrix. Precedent: the TripPositionService of
     * SPEC 26 and, before it, the FreightRateService that injected ZoneServiceInterface
     * until SPEC 15.
     */
    public function __construct(private TripServiceInterface $tripService) {}

    #[Override]
    public function getTripFuels(User $user, int $tripId, array $filters): array
    {
        //
    }

    #[Override]
    public function create(User $user, int $tripId, array $data): TripFuel
    {
        //
    }

    #[Override]
    public function confirm(User $user, int $tripFuelId): TripFuel
    {
        //
    }

    /**
     * Resolve the page size requested by the client.
     *
     * A missing or non numeric limit means "do not paginate"; a numeric one is clamped
     * to [10, 100]. Opt in like the rest of the project.
     */
    private function resolvePerPage(?string $limit): ?int
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }
}
