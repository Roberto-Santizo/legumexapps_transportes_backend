<?php

namespace App\Providers\TripFinishedProduct;

use App\Interfaces\TripFinishedProduct\TripFinishedProductServiceInterface;
use App\Services\TripFinishedProduct\TripFinishedProductService;
use Illuminate\Support\ServiceProvider;

class TripFinishedProductProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TripFinishedProductServiceInterface::class, TripFinishedProductService::class);
    }

    public function boot(): void
    {
        //
    }
}
