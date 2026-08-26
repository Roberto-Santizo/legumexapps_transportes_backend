<?php

namespace App\Providers\DeparturePoint;

use App\Interfaces\DeparturePoint\DeparturePointServiceInterface;
use App\Services\DeparturePoint\DeparturePointService;
use Illuminate\Support\ServiceProvider;

class DeparturePointProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DeparturePointServiceInterface::class, DeparturePointService::class);
    }

    public function boot(): void
    {
        //
    }
}
