<?php

use App\Providers\AppServiceProvider;
use App\Providers\Auth\AuthProvider;
use App\Providers\Carrier\CarrierProvider;
use App\Providers\Vehicle\VehicleProvider;

return [
    AppServiceProvider::class,
    AuthProvider::class,
    CarrierProvider::class,
    VehicleProvider::class,
];
