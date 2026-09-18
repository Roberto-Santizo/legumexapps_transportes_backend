<?php

namespace App\Providers\Assistant;

use App\Interfaces\Assistant\AssistantServiceInterface;
use App\Services\Assistant\AssistantService;
use Illuminate\Support\ServiceProvider;

class AssistantProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(AssistantServiceInterface::class, AssistantService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
