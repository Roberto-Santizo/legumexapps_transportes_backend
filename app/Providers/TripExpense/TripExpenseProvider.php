<?php

namespace App\Providers\TripExpense;

use App\Interfaces\TripExpense\TripExpenseServiceInterface;
use App\Services\TripExpense\TripExpenseService;
use Illuminate\Support\ServiceProvider;

class TripExpenseProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TripExpenseServiceInterface::class, TripExpenseService::class);
    }

    public function boot(): void
    {
        //
    }
}
