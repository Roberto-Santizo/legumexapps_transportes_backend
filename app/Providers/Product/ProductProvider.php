<?php

namespace App\Providers\Product;

use App\Interfaces\Product\ProductServiceInterface;
use App\Services\Product\ProductService;
use Illuminate\Support\ServiceProvider;

class ProductProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProductServiceInterface::class, ProductService::class);
    }

    public function boot(): void
    {
        //
    }
}
