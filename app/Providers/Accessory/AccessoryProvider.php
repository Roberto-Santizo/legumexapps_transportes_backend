<?php

namespace App\Providers\Accessory;

use App\Interfaces\Accessory\AccessoryServiceInterface;
use App\Services\Accessory\AccessoryService;
use Illuminate\Support\ServiceProvider;

class AccessoryProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccessoryServiceInterface::class, AccessoryService::class);
    }

    public function boot(): void
    {
        //
    }
}
