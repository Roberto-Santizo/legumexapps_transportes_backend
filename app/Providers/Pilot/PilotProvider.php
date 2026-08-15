<?php

namespace App\Providers\Pilot;

use App\Interfaces\Pilot\PilotServiceInterface;
use App\Services\Pilot\PilotService;
use Illuminate\Support\ServiceProvider;

class PilotProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PilotServiceInterface::class, PilotService::class);
    }

    public function boot(): void
    {
        //
    }
}
