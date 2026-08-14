<?php

namespace App\Providers\FreightRate;

use App\Interfaces\FreightRate\FreightRateServiceInterface;
use App\Services\FreightRate\FreightRateService;
use Illuminate\Support\ServiceProvider;

class FreightRateProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FreightRateServiceInterface::class, FreightRateService::class);
    }

    public function boot(): void
    {
        //
    }
}
