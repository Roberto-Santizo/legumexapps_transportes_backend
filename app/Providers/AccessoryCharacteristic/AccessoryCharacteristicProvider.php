<?php

namespace App\Providers\AccessoryCharacteristic;

use App\Interfaces\AccessoryCharacteristic\AccessoryCharacteristicServiceInterface;
use App\Services\AccessoryCharacteristic\AccessoryCharacteristicService;
use Illuminate\Support\ServiceProvider;

class AccessoryCharacteristicProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccessoryCharacteristicServiceInterface::class, AccessoryCharacteristicService::class);
    }

    public function boot(): void
    {
        //
    }
}
