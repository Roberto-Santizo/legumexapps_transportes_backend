<?php

namespace App\Providers\DeviceToken;

use App\Interfaces\DeviceToken\DeviceTokenServiceInterface;
use App\Services\DeviceToken\DeviceTokenService;
use Illuminate\Support\ServiceProvider;

class DeviceTokenProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DeviceTokenServiceInterface::class, DeviceTokenService::class);
    }

    public function boot(): void
    {
        //
    }
}
