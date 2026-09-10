<?php

namespace App\Providers\TripPosition;

use App\Interfaces\TripPosition\TripPositionServiceInterface;
use App\Services\TripPosition\TripPositionService;
use Illuminate\Support\ServiceProvider;

class TripPositionProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TripPositionServiceInterface::class, TripPositionService::class);
    }

    public function boot(): void
    {
        //
    }
}
