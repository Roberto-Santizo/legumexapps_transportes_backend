<?php

use App\Providers\AppServiceProvider;
use App\Providers\Auth\AuthProvider;
use App\Providers\Carrier\CarrierProvider;
use App\Providers\Storage\StorageProvider;
use App\Providers\Vehicle\VehicleProvider;

return [
    AppServiceProvider::class,
    AuthProvider::class,
    CarrierProvider::class,
    StorageProvider::class,
    VehicleProvider::class,
];
