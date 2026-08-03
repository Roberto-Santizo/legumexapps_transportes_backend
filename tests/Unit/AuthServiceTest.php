<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\UnauthorizedError;
use App\Interfaces\Auth\AuthServiceInterface;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const SERVICE_CONFIRMATION_TABLE = 'account_confirmation_tokens';
const SERVICE_RESET_TABLE = 'password_reset_tokens';

function authService(): AuthServiceInterface
{
    return app(AuthServiceInterface::class);
}

it('resuelve la implementación registrada en el provider', function () {
    expect(authService())->toBeInstanceOf(AuthService::class);
});

/*
|--------------------------------------------------------------------------
| register()
|--------------------------------------------------------------------------
*/

it('crea el usuario sin confirmar y guarda su código de confirmación', function () {
    $this->freezeTime();

    $user = authService()->register([
        'name' => 'Juan Pérez',
        'email' => 'juan.perez@example.com',
        'password' => 'password123',
        'role' => UserRole::Carrier->value,
    ]);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->role)->toBe(UserRole::Carrier)
        ->and(Hash::check('password123', $user->password))->toBeTrue();

    $this->assertDatabaseHas('users', ['email' => 'juan.perez@example.com', 'email_verified_at' => null]);
    $this->assertDatabaseCount(SERVICE_CONFIRMATION_TABLE, 1);

    $code = DB::table(SERVICE_CONFIRMATION_TABLE)->first();

    expect($code->token)->not->toMatch('/^\d{6}$/')
        ->and(Carbon::parse($code->expiration_date)->equalTo(Carbon::parse($code->created_at)->addHour()))->toBeTrue();
});

it('reemplaza el código anterior en lugar de acumular filas', function () {
    $user = User::factory()->create();
    seedAuthCode(SERVICE_RESET_TABLE, $user->email);

    $previous = DB::table(SERVICE_RESET_TABLE)->where('email', '=', $user->email)->value('token');

    authService()->forgotPassword(['email' => $user->email]);

    $this->assertDatabaseCount(SERVICE_RESET_TABLE, 1);

    expect(DB::table(SERVICE_RESET_TABLE)->where('email', '=', $user->email)->value('token'))->not->toBe($previous);
});

/*
|--------------------------------------------------------------------------
| confirmAccount()
|--------------------------------------------------------------------------
*/

it('confirma la cuenta y borra el código cuando el código es correcto', function () {
    $user = User::factory()->unverified()->create();
    seedAuthCode(SERVICE_CONFIRMATION_TABLE, $user->email);

    authService()->confirmAccount(['email' => $user->email, 'code' => '123456']);

    expect($user->fresh()->email_verified_at)->not->toBeNull();
    $this->assertDatabaseMissing(SERVICE_CONFIRMATION_TABLE, ['email' => $user->email]);
});

it('lanza BadRequestError al confirmar con un código inválido', function (?string $expiration, string $code) {
    $user = User::factory()->unverified()->create();

    if ($expiration !== null) {
        seedAuthCode(SERVICE_CONFIRMATION_TABLE, $user->email, '123456', Carbon::parse($expiration));
    }

    expect(fn () => authService()->confirmAccount(['email' => $user->email, 'code' => $code]))
        ->toThrow(BadRequestError::class, 'El código es inválido o ya expiró');

    expect($user->fresh()->email_verified_at)->toBeNull();
})->with([
    'código incorrecto' => ['+1 hour', '999999'],
    'código expirado' => ['-1 minute', '123456'],
    'sin código pendiente' => [null, '123456'],
]);

it('lanza BadRequestError al confirmar un correo que no pertenece a ningún usuario', function () {
    seedAuthCode(SERVICE_CONFIRMATION_TABLE, 'fantasma@example.com');

    expect(fn () => authService()->confirmAccount(['email' => 'fantasma@example.com', 'code' => '123456']))
        ->toThrow(BadRequestError::class, 'El código es inválido o ya expiró');
});

/*
|--------------------------------------------------------------------------
| login()
|--------------------------------------------------------------------------
*/

it('devuelve el usuario y un token al iniciar sesión con credenciales válidas', function () {
    $user = User::factory()->create(['password' => 'password123']);

    $result = authService()->login(['email' => $user->email, 'password' => 'password123']);

    expect($result)->toHaveKeys(['user', 'token'])
        ->and($result['user']->id)->toBe($user->id)
        ->and($result['token'])->toBeString()->not->toBeEmpty();

    resetAuthState();
});

it('lanza UnauthorizedError con credenciales incorrectas', function (string $email, string $password) {
    User::factory()->create(['email' => 'juan.perez@example.com', 'password' => 'password123']);

    expect(fn () => authService()->login(['email' => $email, 'password' => $password]))
        ->toThrow(UnauthorizedError::class, 'Las credenciales son incorrectas');

    resetAuthState();
})->with([
    'contraseña incorrecta' => ['juan.perez@example.com', 'contrasena-incorrecta'],
    'correo inexistente' => ['nadie@example.com', 'password123'],
]);

it('lanza ForbiddenError al iniciar sesión con una cuenta sin confirmar', function () {
    $user = User::factory()->unverified()->create(['password' => 'password123']);

    expect(fn () => authService()->login(['email' => $user->email, 'password' => 'password123']))
        ->toThrow(ForbiddenError::class, 'La cuenta aún no ha sido confirmada');

    resetAuthState();
});

/*
|--------------------------------------------------------------------------
| checkStatus()
|--------------------------------------------------------------------------
*/

it('devuelve el usuario autenticado con un token renovado', function () {
    $user = User::factory()->create(['password' => 'password123']);

    $token = authService()->login(['email' => $user->email, 'password' => 'password123'])['token'];

    $result = authService()->checkStatus();

    expect($result)->toHaveKeys(['user', 'token'])
        ->and($result['user']->id)->toBe($user->id)
        ->and($result['token'])->toBeString()->not->toBe($token);

    resetAuthState();
});

it('lanza UnauthorizedError en checkStatus cuando no hay sesión', function () {
    resetAuthState();

    expect(fn () => authService()->checkStatus())
        ->toThrow(UnauthorizedError::class, 'El token no es válido');
});

/*
|--------------------------------------------------------------------------
| forgotPassword()
|--------------------------------------------------------------------------
*/

it('guarda un código de recuperación hasheado para un correo registrado', function () {
    $this->freezeTime();

    $user = User::factory()->create();

    authService()->forgotPassword(['email' => $user->email]);

    $this->assertDatabaseCount(SERVICE_RESET_TABLE, 1);

    $code = DB::table(SERVICE_RESET_TABLE)->where('email', '=', $user->email)->first();

    expect($code->token)->not->toMatch('/^\d{6}$/')
        ->and(Carbon::parse($code->expiration_date)->equalTo(Carbon::parse($code->created_at)->addHour()))->toBeTrue();
});

it('no guarda código ni falla cuando el correo no está registrado', function () {
    authService()->forgotPassword(['email' => 'nadie@example.com']);

    $this->assertDatabaseCount(SERVICE_RESET_TABLE, 0);
});

/*
|--------------------------------------------------------------------------
| resetPassword()
|--------------------------------------------------------------------------
*/

it('reemplaza la contraseña y borra el código de recuperación usado', function () {
    $user = User::factory()->create(['password' => 'password123']);
    seedAuthCode(SERVICE_RESET_TABLE, $user->email);

    authService()->resetPassword([
        'email' => $user->email,
        'code' => '123456',
        'password' => 'nueva-password',
    ]);

    $user->refresh();

    expect(Hash::check('nueva-password', $user->password))->toBeTrue()
        ->and(Hash::check('password123', $user->password))->toBeFalse();

    $this->assertDatabaseMissing(SERVICE_RESET_TABLE, ['email' => $user->email]);
});

it('lanza BadRequestError al restablecer con un código inválido y conserva la contraseña', function (?string $expiration, string $code) {
    $user = User::factory()->create(['password' => 'password123']);

    if ($expiration !== null) {
        seedAuthCode(SERVICE_RESET_TABLE, $user->email, '123456', Carbon::parse($expiration));
    }

    expect(fn () => authService()->resetPassword([
        'email' => $user->email,
        'code' => $code,
        'password' => 'nueva-password',
    ]))->toThrow(BadRequestError::class, 'El código es inválido o ya expiró');

    expect(Hash::check('password123', $user->fresh()->password))->toBeTrue();
})->with([
    'código incorrecto' => ['+1 hour', '999999'],
    'código expirado' => ['-1 minute', '123456'],
    'sin código pendiente' => [null, '123456'],
]);
