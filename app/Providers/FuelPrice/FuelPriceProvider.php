<?php

namespace App\Providers\FuelPrice;

use App\Interfaces\FuelPrice\FuelPriceServiceInterface;
use App\Services\FuelPrice\FuelPriceService;
use Illuminate\Support\ServiceProvider;

class FuelPriceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FuelPriceServiceInterface::class, FuelPriceService::class);
    }

    public function boot(): void
    {
        //
    }
}
