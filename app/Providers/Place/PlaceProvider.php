<?php

namespace App\Providers\Place;

use App\Interfaces\Place\PlaceServiceInterface;
use App\Services\Place\GooglePlacesService;
use Illuminate\Support\ServiceProvider;

class PlaceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PlaceServiceInterface::class, GooglePlacesService::class);
    }

    public function boot(): void
    {
        //
    }
}
