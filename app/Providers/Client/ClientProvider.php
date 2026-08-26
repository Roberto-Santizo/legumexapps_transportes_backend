<?php

namespace App\Providers\Client;

use App\Interfaces\Client\ClientServiceInterface;
use App\Services\Client\ClientService;
use Illuminate\Support\ServiceProvider;

class ClientProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ClientServiceInterface::class, ClientService::class);
    }

    public function boot(): void
    {
        //
    }
}
