<?php

use App\Providers\Accessory\AccessoryProvider;
use App\Providers\AccessoryCharacteristic\AccessoryCharacteristicProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Assistant\AssistantProvider;
use App\Providers\Auth\AuthProvider;
use App\Providers\Carrier\CarrierProvider;
use App\Providers\Client\ClientProvider;
use App\Providers\Dashboard\DashboardProvider;
use App\Providers\DeparturePoint\DeparturePointProvider;
use App\Providers\DeviceToken\DeviceTokenProvider;
use App\Providers\FinishedProduct\FinishedProductProvider;
use App\Providers\FreightRate\FreightRateProvider;
use App\Providers\FuelPrice\FuelPriceProvider;
use App\Providers\Location\LocationProvider;
use App\Providers\Pilot\PilotProvider;
use App\Providers\Place\PlaceProvider;
use App\Providers\Product\ProductProvider;
use App\Providers\PushNotification\PushNotificationProvider;
use App\Providers\Report\ReportProvider;
use App\Providers\ShippingLine\ShippingLineProvider;
use App\Providers\Storage\StorageProvider;
use App\Providers\Trip\TripProvider;
use App\Providers\TripCost\TripCostProvider;
use App\Providers\TripExpense\TripExpenseProvider;
use App\Providers\TripFinishedProduct\TripFinishedProductProvider;
use App\Providers\TripFuel\TripFuelProvider;
use App\Providers\TripNotification\TripNotificationProvider;
use App\Providers\TripPosition\TripPositionProvider;
use App\Providers\TripTimeout\TripTimeoutProvider;
use App\Providers\Vehicle\VehicleProvider;
use App\Providers\VehicleExpense\VehicleExpenseProvider;
use App\Providers\Zone\ZoneProvider;

return [
    AccessoryProvider::class,
    AccessoryCharacteristicProvider::class,
    AppServiceProvider::class,
    AssistantProvider::class,
    AuthProvider::class,
    CarrierProvider::class,
    ClientProvider::class,
    DashboardProvider::class,
    DeparturePointProvider::class,
    DeviceTokenProvider::class,
    FinishedProductProvider::class,
    FreightRateProvider::class,
    FuelPriceProvider::class,
    LocationProvider::class,
    PilotProvider::class,
    PlaceProvider::class,
    ProductProvider::class,
    PushNotificationProvider::class,
    ReportProvider::class,
    ShippingLineProvider::class,
    StorageProvider::class,
    TripProvider::class,
    TripCostProvider::class,
    TripExpenseProvider::class,
    TripFinishedProductProvider::class,
    TripFuelProvider::class,
    TripNotificationProvider::class,
    TripPositionProvider::class,
    TripTimeoutProvider::class,
    VehicleProvider::class,
    VehicleExpenseProvider::class,
    ZoneProvider::class,
];
