<?php

namespace App\Providers\FinishedProduct;

use App\Interfaces\FinishedProduct\FinishedProductServiceInterface;
use App\Services\FinishedProduct\FinishedProductService;
use Illuminate\Support\ServiceProvider;

class FinishedProductProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FinishedProductServiceInterface::class, FinishedProductService::class);
    }

    public function boot(): void
    {
        //
    }
}
