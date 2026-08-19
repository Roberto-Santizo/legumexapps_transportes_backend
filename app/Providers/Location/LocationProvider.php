<?php

namespace App\Providers\Location;

use App\Interfaces\Location\LocationServiceInterface;
use App\Services\Location\LocationService;
use Illuminate\Support\ServiceProvider;

class LocationProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LocationServiceInterface::class, LocationService::class);
    }

    public function boot(): void
    {
        //
    }
}
