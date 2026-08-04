<?php

use App\Providers\AppServiceProvider;
use App\Providers\Auth\AuthProvider;
use App\Providers\Carrier\CarrierProvider;

return [
    AppServiceProvider::class,
    AuthProvider::class,
    CarrierProvider::class,
];
