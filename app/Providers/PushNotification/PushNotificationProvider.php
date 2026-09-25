<?php

namespace App\Providers\PushNotification;

use App\Interfaces\PushNotification\PushNotificationServiceInterface;
use App\Services\PushNotification\FcmPushNotificationService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

class PushNotificationProvider extends ServiceProvider
{
    /**
     * Bind the contract to a lazy proxy of the FCM implementation.
     *
     * Resolving the provider's client throws when FIREBASE_CREDENTIALS is missing.
     * The proxy defers that resolution to the first send(), so the failure lands
     * inside the caller's try/catch instead of breaking the constructor of every
     * service that depends on this contract.
     */
    public function register(): void
    {
        $this->app->bind(
            PushNotificationServiceInterface::class,
            fn (Application $app): FcmPushNotificationService => new ReflectionClass(FcmPushNotificationService::class)
                ->newLazyProxy(fn (): FcmPushNotificationService => $app->make(FcmPushNotificationService::class)),
        );
    }

    public function boot(): void
    {
        //
    }
}
