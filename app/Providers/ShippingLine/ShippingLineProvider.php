<?php

namespace App\Providers\ShippingLine;

use App\Interfaces\ShippingLine\ShippingLineServiceInterface;
use App\Services\ShippingLine\ShippingLineService;
use Illuminate\Support\ServiceProvider;

class ShippingLineProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShippingLineServiceInterface::class, ShippingLineService::class);
    }

    public function boot(): void
    {
        //
    }
}
