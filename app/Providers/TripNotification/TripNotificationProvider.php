<?php

namespace App\Providers\TripNotification;

use App\Interfaces\TripNotification\TripNotificationServiceInterface;
use App\Services\TripNotification\TripNotificationService;
use Illuminate\Support\ServiceProvider;

class TripNotificationProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TripNotificationServiceInterface::class, TripNotificationService::class);
    }

    public function boot(): void
    {
        //
    }
}
