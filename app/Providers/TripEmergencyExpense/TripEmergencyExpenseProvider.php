<?php

namespace App\Providers\TripEmergencyExpense;

use App\Interfaces\TripEmergencyExpense\TripEmergencyExpenseServiceInterface;
use App\Services\TripEmergencyExpense\TripEmergencyExpenseService;
use Illuminate\Support\ServiceProvider;

class TripEmergencyExpenseProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TripEmergencyExpenseServiceInterface::class, TripEmergencyExpenseService::class);
    }

    public function boot(): void
    {
        //
    }
}
