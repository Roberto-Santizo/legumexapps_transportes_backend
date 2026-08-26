<?php

use App\Providers\Accessory\AccessoryProvider;
use App\Providers\AccessoryCharacteristic\AccessoryCharacteristicProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Auth\AuthProvider;
use App\Providers\Carrier\CarrierProvider;
use App\Providers\Client\ClientProvider;
use App\Providers\DeparturePoint\DeparturePointProvider;
use App\Providers\FreightRate\FreightRateProvider;
use App\Providers\FuelPrice\FuelPriceProvider;
use App\Providers\Location\LocationProvider;
use App\Providers\Pilot\PilotProvider;
use App\Providers\Place\PlaceProvider;
use App\Providers\Product\ProductProvider;
use App\Providers\ShippingLine\ShippingLineProvider;
use App\Providers\Storage\StorageProvider;
use App\Providers\Vehicle\VehicleProvider;
use App\Providers\VehicleExpense\VehicleExpenseProvider;
use App\Providers\Zone\ZoneProvider;

return [
    AccessoryProvider::class,
    AccessoryCharacteristicProvider::class,
    AppServiceProvider::class,
    AuthProvider::class,
    CarrierProvider::class,
    ClientProvider::class,
    DeparturePointProvider::class,
    FreightRateProvider::class,
    FuelPriceProvider::class,
    LocationProvider::class,
    PilotProvider::class,
    PlaceProvider::class,
    ProductProvider::class,
    ShippingLineProvider::class,
    StorageProvider::class,
    VehicleProvider::class,
    VehicleExpenseProvider::class,
    ZoneProvider::class,
];
