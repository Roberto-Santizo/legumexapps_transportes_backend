<?php

namespace App\Providers\VehicleExpense;

use App\Interfaces\VehicleExpense\VehicleExpenseServiceInterface;
use App\Services\VehicleExpense\VehicleExpenseService;
use Illuminate\Support\ServiceProvider;

class VehicleExpenseProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VehicleExpenseServiceInterface::class, VehicleExpenseService::class);
    }

    public function boot(): void
    {
        //
    }
}
