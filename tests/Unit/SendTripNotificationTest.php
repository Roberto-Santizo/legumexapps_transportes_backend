<?php

use App\Enums\TripNotificationType;
use App\Interfaces\TripNotification\TripNotificationServiceInterface;
use App\Jobs\SendTripNotification;

it('delega en el service con el id del viaje y el tipo', function () {
    $service = Mockery::mock(TripNotificationServiceInterface::class);
    $service->shouldReceive('notify')->once()->with(42, TripNotificationType::Started);

    new SendTripNotification(42, TripNotificationType::Started)->handle($service);
});

it('lo resuelve el contenedor al ejecutarse', function () {
    $service = Mockery::mock(TripNotificationServiceInterface::class);
    $service->shouldReceive('notify')->once()->with(7, TripNotificationType::Finished);
    app()->instance(TripNotificationServiceInterface::class, $service);

    SendTripNotification::dispatchSync(7, TripNotificationType::Finished);
});
