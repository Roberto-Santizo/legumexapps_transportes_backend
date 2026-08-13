<?php

namespace App\Providers\Zone;

use App\Interfaces\Zone\ZoneServiceInterface;
use App\Services\Zone\ZoneService;
use Illuminate\Support\ServiceProvider;

class ZoneProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ZoneServiceInterface::class, ZoneService::class);
    }

    public function boot(): void
    {
        //
    }
}
