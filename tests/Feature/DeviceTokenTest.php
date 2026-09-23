<?php

use App\Enums\DevicePlatform;
use App\Enums\UserRole;
use App\Http\Resources\Auth\UserResource;
use App\Models\User;
use App\Models\UserDeviceToken;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * The two routes of the domain as method and URI, for the middleware datasets.
 *
 * @return array<string, array{string, string}>
 */
function deviceTokenEndpoints(): array
{
    return [
        'store' => ['POST', '/api/device-tokens'],
        'destroy' => ['DELETE', '/api/device-tokens/some-token'],
    ];
}

/**
 * Every role of the project: both routes are `jwt.auth` alone.
 *
 * @return array<string, array{UserRole}>
 */
function deviceTokenAllRoles(): array
{
    return collect(UserRole::cases())
        ->mapWithKeys(fn (UserRole $role) => [$role->value => [$role]])
        ->all();
}

if (! function_exists('userWithRole')) {
    /**
     * Create a confirmed user with the given role.
     */
    function userWithRole(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }
}

if (! function_exists('asUser')) {
    /**
     * Authenticate the next request as the given user.
     *
     * The JWT singletons survive between calls of the same test, so the guard state is
     * dropped before handing the fresh token over.
     */
    function asUser(User $user): TestCase
    {
        resetAuthState();

        return test()->withToken(JWTAuth::fromUser($user))->withHeader('Accept', 'application/json');
    }
}

/**
 * A valid store payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function deviceTokenPayload(array $overrides = []): array
{
    return array_merge([
        'token' => 'dXk3:APA91bHfake_token-123',
        'platform' => DevicePlatform::Android->value,
    ], $overrides);
}

/**
 * The five keys `DeviceTokenResource` promises, in order.
 *
 * @return array<int, string>
 */
function deviceTokenResourceKeys(): array
{
    return ['id', 'token', 'platform', 'lastSeenAt', 'createdAt'];
}

/*
|--------------------------------------------------------------------------
| Middlewares
|--------------------------------------------------------------------------
*/

it('rechaza con 401 las dos rutas de tokens de dispositivo sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(deviceTokenEndpoints());

it('deja registrar y borrar tokens a los siete roles, sin exigir empresa', function (UserRole $role) {
    $user = userWithRole($role);

    asUser($user)->postJson('/api/device-tokens', deviceTokenPayload())
        ->assertCreated();

    asUser($user)->deleteJson('/api/device-tokens/'.rawurlencode(deviceTokenPayload()['token']))
        ->assertOk();
})->with(deviceTokenAllRoles());

/*
|--------------------------------------------------------------------------
| POST /api/device-tokens
|--------------------------------------------------------------------------
*/

it('crea el token con 201 a nombre del autenticado', function () {
    $user = userWithRole(UserRole::Pilot);

    $response = asUser($user)->postJson('/api/device-tokens', deviceTokenPayload())
        ->assertCreated()
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Token de dispositivo registrado correctamente')
        ->assertJsonPath('data.token', 'dXk3:APA91bHfake_token-123')
        ->assertJsonPath('data.platform', 'android');

    expect(array_keys($response->json('data')))->toBe(deviceTokenResourceKeys())
        ->and($response->json('data.lastSeenAt'))->toMatch('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} (AM|PM)$/');

    $row = UserDeviceToken::sole();

    expect($row->user_id)->toBe($user->id)
        ->and($row->last_seen_at)->not->toBeNull();
});

it('refresca con 200 un token que ya es del autenticado sin crear otra fila', function () {
    $user = userWithRole(UserRole::Carrier);
    $existing = UserDeviceToken::factory()->for($user)->create([
        'token' => deviceTokenPayload()['token'],
        'platform' => DevicePlatform::Android,
        'last_seen_at' => now()->subDays(3),
    ]);

    asUser($user)->postJson('/api/device-tokens', deviceTokenPayload(['platform' => 'ios']))
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Token de dispositivo actualizado correctamente')
        ->assertJsonPath('data.id', $existing->id)
        ->assertJsonPath('data.platform', 'ios');

    $row = UserDeviceToken::sole();

    expect($row->platform)->toBe(DevicePlatform::Ios)
        ->and($row->last_seen_at->isAfter(now()->subMinute()))->toBeTrue();
});

it('reasigna con 200 un token de otro usuario al autenticado', function () {
    $previous = userWithRole(UserRole::Pilot);
    $user = userWithRole(UserRole::Pilot);
    UserDeviceToken::factory()->for($previous)->create(['token' => deviceTokenPayload()['token']]);

    asUser($user)->postJson('/api/device-tokens', deviceTokenPayload())
        ->assertOk();

    expect(UserDeviceToken::sole()->user_id)->toBe($user->id)
        ->and($previous->deviceTokens()->count())->toBe(0);
});

it('ignora un user_id en el cuerpo', function () {
    $user = userWithRole(UserRole::Manager);
    $other = userWithRole(UserRole::Manager);

    asUser($user)->postJson('/api/device-tokens', deviceTokenPayload(['user_id' => $other->id, 'userId' => $other->id]))
        ->assertCreated();

    expect(UserDeviceToken::sole()->user_id)->toBe($user->id);
});

it('valida el cuerpo del registro con mensajes en español', function (array $payload, string $field, string $message) {
    asUser(userWithRole(UserRole::User))->postJson('/api/device-tokens', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);

    expect(UserDeviceToken::count())->toBe(0);
})->with([
    'sin token' => [['platform' => 'android'], 'token', 'El token de dispositivo es obligatorio'],
    'token no texto' => [['token' => 12345, 'platform' => 'android'], 'token', 'El token de dispositivo debe ser un texto'],
    'token largo' => [['token' => str_repeat('a', 513), 'platform' => 'android'], 'token', 'El token de dispositivo no puede superar los 512 caracteres'],
    'token con espacios' => [['token' => 'abc def', 'platform' => 'android'], 'token', 'El token de dispositivo no puede contener espacios'],
    'sin plataforma' => [['token' => 'abc'], 'platform', 'La plataforma es obligatoria'],
    'plataforma inválida' => [['token' => 'abc', 'platform' => 'web'], 'platform', 'La plataforma seleccionada no es válida'],
]);

it('acepta un token de 512 caracteres', function () {
    asUser(userWithRole(UserRole::Export))->postJson('/api/device-tokens', deviceTokenPayload(['token' => str_repeat('a', 512)]))
        ->assertCreated();
});

/*
|--------------------------------------------------------------------------
| DELETE /api/device-tokens/{token}
|--------------------------------------------------------------------------
*/

it('borra físicamente un token propio con 200', function () {
    $user = userWithRole(UserRole::Shipment);
    $deviceToken = UserDeviceToken::factory()->for($user)->create();

    $response = asUser($user)->deleteJson('/api/device-tokens/'.rawurlencode($deviceToken->token))
        ->assertOk()
        ->assertJsonPath('statusCode', 200)
        ->assertJsonPath('message', 'Token de dispositivo eliminado correctamente')
        ->assertJsonPath('data.id', $deviceToken->id);

    expect(array_keys($response->json('data')))->toBe(deviceTokenResourceKeys())
        ->and(UserDeviceToken::count())->toBe(0);
});

it('responde 404 al borrar un token inexistente', function () {
    asUser(userWithRole(UserRole::Administrator))->deleteJson('/api/device-tokens/no-existe')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El token de dispositivo no existe',
            'data' => null,
        ]);
});

it('responde 404 al borrar un token de otro usuario y no lo toca', function () {
    $deviceToken = UserDeviceToken::factory()->create();

    asUser(userWithRole(UserRole::Administrator))->deleteJson('/api/device-tokens/'.rawurlencode($deviceToken->token))
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El token de dispositivo no existe',
            'data' => null,
        ]);

    expect(UserDeviceToken::whereKey($deviceToken->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Transversales
|--------------------------------------------------------------------------
*/

it('no expone los tokens en UserResource ni en los claims del JWT', function () {
    $user = userWithRole(UserRole::Pilot);
    UserDeviceToken::factory()->for($user)->create();

    $resource = (new UserResource($user))->toArray(request());

    expect($resource)->not->toHaveKey('deviceTokens')
        ->and($user->getJWTCustomClaims())->not->toHaveKey('deviceTokens');
});

it('borra los tokens en cascada al borrar el usuario', function () {
    $user = userWithRole(UserRole::Pilot);
    UserDeviceToken::factory()->for($user)->count(2)->create();

    expect($user->deviceTokens()->count())->toBe(2);

    $user->delete();

    expect(UserDeviceToken::count())->toBe(0);
});
