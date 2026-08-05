<?php

namespace App\Providers\Carrier;

use App\Interfaces\Carrier\CarrierServiceInterface;
use App\Services\Carrier\CarrierService;
use Illuminate\Support\ServiceProvider;

class CarrierProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CarrierServiceInterface::class, CarrierService::class);
    }

    public function boot(): void
    {
        //
    }
}
