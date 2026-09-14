<?php

namespace App\Services\Dashboard;

use App\Interfaces\Dashboard\DashboardServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use Override;

class DashboardService implements DashboardServiceInterface
{
    #[Override]
    public function getTripsSummary(array $filters): array
    {
        throw new LogicException('Not implemented');
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
}
