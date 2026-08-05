<?php

namespace App\Providers\Vehicle;

use App\Interfaces\Vehicle\VehicleServiceInterface;
use App\Services\Vehicle\VehicleService;
use Illuminate\Support\ServiceProvider;

class VehicleProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VehicleServiceInterface::class, VehicleService::class);
    }

    public function boot(): void
    {
        //
    }
}
