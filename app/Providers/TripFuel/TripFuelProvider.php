<?php

namespace App\Providers\TripFuel;

use App\Interfaces\TripFuel\TripFuelServiceInterface;
use App\Services\TripFuel\TripFuelService;
use Illuminate\Support\ServiceProvider;

class TripFuelProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TripFuelServiceInterface::class, TripFuelService::class);
    }

    public function boot(): void
    {
        //
    }
}
