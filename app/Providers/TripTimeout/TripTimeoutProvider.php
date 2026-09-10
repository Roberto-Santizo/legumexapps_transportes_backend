<?php

namespace App\Providers\TripTimeout;

use App\Interfaces\TripTimeout\TripTimeoutServiceInterface;
use App\Services\TripTimeout\TripTimeoutService;
use Illuminate\Support\ServiceProvider;

class TripTimeoutProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TripTimeoutServiceInterface::class, TripTimeoutService::class);
    }

    public function boot(): void
    {
        //
    }
}
