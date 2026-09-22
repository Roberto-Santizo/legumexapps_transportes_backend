<?php

namespace App\Providers\TripCost;

use App\Interfaces\TripCost\TripCostServiceInterface;
use App\Services\TripCost\TripCostService;
use Illuminate\Support\ServiceProvider;

class TripCostProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TripCostServiceInterface::class, TripCostService::class);
    }

    public function boot(): void
    {
        //
    }
}
