<?php

namespace App\Providers\Trip;

use App\Interfaces\Trip\TripServiceInterface;
use App\Services\Trip\TripService;
use Illuminate\Support\ServiceProvider;

class TripProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TripServiceInterface::class, TripService::class);
    }

    public function boot(): void
    {
        //
    }
}
