<?php

namespace App\Providers\Auth;

use App\Interfaces\Auth\AuthServiceInterface;
use App\Services\Auth\AuthService;
use Illuminate\Support\ServiceProvider;

class AuthProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuthServiceInterface::class, AuthService::class);
    }

    public function boot(): void
    {
        //
    }
}
