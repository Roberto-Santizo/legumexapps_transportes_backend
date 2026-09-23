<?php

use App\Enums\DevicePlatform;
use App\Enums\UserRole;
use App\Errors\NotFoundError;
use App\Interfaces\DeviceToken\DeviceTokenServiceInterface;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Services\DeviceToken\DeviceTokenService;

/**
 * Resolve the service through the container, which checks the Provider binding too.
 */
function deviceTokenService(): DeviceTokenServiceInterface
{
    return app(DeviceTokenServiceInterface::class);
}

function deviceTokenServiceUser(): User
{
    return User::factory()->create(['role' => UserRole::Pilot]);
}

it('resuelve el contrato al service concreto', function () {
    expect(deviceTokenService())->toBeInstanceOf(DeviceTokenService::class);
});

it('crea el token cuando nadie lo tiene', function () {
    $user = deviceTokenServiceUser();

    $result = deviceTokenService()->registerToken($user, ['token' => 'abc:123', 'platform' => 'ios']);

    expect($result['created'])->toBeTrue()
        ->and($result['token']->user_id)->toBe($user->id)
        ->and($result['token']->token)->toBe('abc:123')
        ->and($result['token']->platform)->toBe(DevicePlatform::Ios)
        ->and($result['token']->last_seen_at)->not->toBeNull();
});

it('guarda el token tal cual, sin cambiar mayúsculas', function () {
    $result = deviceTokenService()->registerToken(deviceTokenServiceUser(), ['token' => 'AbC:xYz_-', 'platform' => 'android']);

    expect($result['token']->fresh()->token)->toBe('AbC:xYz_-');
});

it('refresca plataforma y last_seen_at de un token propio', function () {
    $user = deviceTokenServiceUser();
    $existing = UserDeviceToken::factory()->for($user)->create([
        'token' => 'abc',
        'platform' => DevicePlatform::Android,
        'last_seen_at' => now()->subWeek(),
    ]);

    $this->travel(5)->minutes();

    $result = deviceTokenService()->registerToken($user, ['token' => 'abc', 'platform' => 'ios']);

    expect($result['created'])->toBeFalse()
        ->and($result['token']->id)->toBe($existing->id)
        ->and($existing->fresh()->platform)->toBe(DevicePlatform::Ios)
        ->and($existing->fresh()->last_seen_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and(UserDeviceToken::count())->toBe(1);
});

it('reasigna al usuario un token ajeno', function () {
    $previous = deviceTokenServiceUser();
    $user = deviceTokenServiceUser();
    $existing = UserDeviceToken::factory()->for($previous)->create(['token' => 'abc']);

    $result = deviceTokenService()->registerToken($user, ['token' => 'abc', 'platform' => 'android']);

    expect($result['created'])->toBeFalse()
        ->and($existing->fresh()->user_id)->toBe($user->id)
        ->and(UserDeviceToken::count())->toBe(1);
});

it('borra un token propio y devuelve la fila borrada', function () {
    $user = deviceTokenServiceUser();
    $existing = UserDeviceToken::factory()->for($user)->create();

    $deleted = deviceTokenService()->deleteToken($user, $existing->token);

    expect($deleted->id)->toBe($existing->id)
        ->and(UserDeviceToken::count())->toBe(0);
});

it('lanza NotFoundError con un token inexistente', function () {
    deviceTokenService()->deleteToken(deviceTokenServiceUser(), 'nope');
})->throws(NotFoundError::class, 'El token de dispositivo no existe');

it('lanza NotFoundError con un token ajeno y no lo borra', function () {
    $existing = UserDeviceToken::factory()->create();

    expect(fn () => deviceTokenService()->deleteToken(deviceTokenServiceUser(), $existing->token))
        ->toThrow(NotFoundError::class, 'El token de dispositivo no existe')
        ->and(UserDeviceToken::count())->toBe(1);
});
