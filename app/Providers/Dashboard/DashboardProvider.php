<?php

namespace App\Providers\Dashboard;

use App\Interfaces\Dashboard\DashboardServiceInterface;
use App\Services\Dashboard\DashboardService;
use Illuminate\Support\ServiceProvider;

class DashboardProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DashboardServiceInterface::class, DashboardService::class);
    }

    public function boot(): void
    {
        //
    }
}
