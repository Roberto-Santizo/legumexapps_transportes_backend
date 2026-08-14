<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\UnauthorizedError;
use App\Interfaces\Auth\AuthEmailsInterface;
use App\Interfaces\Auth\AuthServiceInterface;
use App\Mail\Auth\AccountConfirmationMail;
use App\Mail\Auth\PasswordResetMail;
use App\Mail\Auth\WelcomeMail;
use App\Mail\Services\AuthEmails;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

const SERVICE_CONFIRMATION_TABLE = 'account_confirmation_tokens';
const SERVICE_RESET_TABLE = 'password_reset_tokens';

function authService(): AuthServiceInterface
{
    return app(AuthServiceInterface::class);
}

/**
 * Decode the claims of a token, leaving the JWT singletons clean on both ends.
 *
 * @return array<string, mixed>
 */
function serviceClaimsOf(string $token): array
{
    resetAuthState();

    $claims = JWTAuth::setToken($token)->getPayload()->toArray();

    resetAuthState();

    return $claims;
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

it('devuelve los dos tokens con su vigencia y su tokenType al iniciar sesión', function () {
    $user = User::factory()->create(['password' => 'password123']);

    $result = authService()->login(['email' => $user->email, 'password' => 'password123']);

    expect($result)->toHaveKeys(['user', 'token', 'refreshToken'])
        ->and($result['refreshToken'])->toBeString()->not->toBe($result['token']);

    $access = serviceClaimsOf($result['token']);
    $refresh = serviceClaimsOf($result['refreshToken']);

    expect($access['tokenType'])->toBe('access')
        ->and($access['exp'] - $access['iat'])->toBe(60 * 60)
        ->and($refresh['tokenType'])->toBe('refresh')
        ->and($refresh['exp'] - $refresh['iat'])->toBe(14 * 24 * 60 * 60);

    resetAuthState();
});

it('restaura el TTL del factory tras emitir el par de tokens', function () {
    $user = User::factory()->create(['password' => 'password123']);

    authService()->login(['email' => $user->email, 'password' => 'password123']);

    /** El Factory es un singleton de la petición: sin restaurar, este token saldría con catorce días. */
    $later = serviceClaimsOf(auth('api')->login($user));

    expect($later['exp'] - $later['iat'])->toBe(60 * 60);

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

it('devuelve un par nuevo de tokens en checkStatus', function () {
    $user = User::factory()->create(['password' => 'password123']);

    $issued = authService()->login(['email' => $user->email, 'password' => 'password123']);

    $result = authService()->checkStatus();

    expect($result)->toHaveKeys(['user', 'token', 'refreshToken'])
        ->and($result['token'])->not->toBe($issued['token'])
        ->and($result['refreshToken'])->not->toBe($issued['refreshToken']);

    $refresh = serviceClaimsOf($result['refreshToken']);

    expect($refresh['tokenType'])->toBe('refresh')
        ->and($refresh['exp'] - $refresh['iat'])->toBe(14 * 24 * 60 * 60);

    resetAuthState();
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

/*
|--------------------------------------------------------------------------
| AuthEmails
|--------------------------------------------------------------------------
*/

/**
 * @return array<string, string>
 */
function serviceRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Juan Pérez',
        'email' => 'juan.perez@example.com',
        'password' => 'password123',
        'role' => UserRole::Pilot->value,
    ], $overrides);
}

it('resuelve siempre la misma instancia del emisor de correos', function () {
    expect(app(AuthEmailsInterface::class))->toBeInstanceOf(AuthEmails::class)
        ->and(app(AuthEmailsInterface::class))->toBe(app(AuthEmailsInterface::class));
});

it('entrega al emisor de correos el código en claro que corresponde al hash persistido', function () {
    $code = null;

    $emails = Mockery::mock(AuthEmailsInterface::class);
    $emails->shouldReceive('sendAccountConfirmation')
        ->once()
        ->with(Mockery::type(User::class), Mockery::capture($code));

    $this->app->instance(AuthEmailsInterface::class, $emails);

    $user = authService()->register(serviceRegisterPayload());

    $stored = DB::table(SERVICE_CONFIRMATION_TABLE)->where('email', '=', $user->email)->value('token');

    expect($code)->toMatch('/^\d{6}$/')
        ->and(Hash::check($code, $stored))->toBeTrue();
});

/**
 * El try/catch real vive dentro de AuthEmails, no en AuthService: por eso el fallo
 * se fuerza en el transporte y se deja actuar a la implementación registrada, en
 * lugar de inyectar un doble que lanza (eso solo probaría el doble).
 */
it('no rompe el registro ni pierde el código cuando el proveedor de correo falla', function () {
    Log::spy();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('proveedor de correo caído'));

    $user = authService()->register(serviceRegisterPayload());

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->email)->toBe('juan.perez@example.com');

    $this->assertDatabaseHas('users', ['email' => 'juan.perez@example.com', 'email_verified_at' => null]);
    $this->assertDatabaseCount(SERVICE_CONFIRMATION_TABLE, 1);
});

it('registra el fallo del proveedor con el correo del usuario y sin el código en claro', function () {
    $user = User::factory()->create(['email' => 'juan.perez@example.com']);

    Log::spy();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('proveedor de correo caído'));

    app(AuthEmails::class)->sendAccountConfirmation($user, '123456');

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'No se pudo enviar el correo de autenticación'
                && $context['mailable'] === AccountConfirmationMail::class
                && $context['email'] === 'juan.perez@example.com'
                && $context['exception'] === 'proveedor de correo caído'
                && ! str_contains((string) json_encode($context), '123456');
        });
});

it('renderiza cada plantilla de correo con el nombre del usuario y su código cuando lo lleva', function (string $mailable, bool $withCode) {
    $user = User::factory()->make(['name' => 'Juan Perez']);

    $html = $withCode
        ? (new $mailable($user, '123456'))->render()
        : (new $mailable($user))->render();

    expect($html)->toContain('Juan Perez');

    $withCode
        ? expect($html)->toContain('123456')
        : expect($html)->not->toContain('123456');
})->with([
    'confirmación de cuenta' => [AccountConfirmationMail::class, true],
    'restablecimiento de contraseña' => [PasswordResetMail::class, true],
    'bienvenida' => [WelcomeMail::class, false],
]);

it('no envía ningún correo cuando falla el guardado del usuario en el registro', function () {
    User::factory()->create(['email' => 'juan.perez@example.com']);

    expect(fn () => authService()->register(serviceRegisterPayload()))->toThrow(QueryException::class);

    $this->assertDatabaseCount(SERVICE_CONFIRMATION_TABLE, 0);

    Mail::assertNothingSent();
});
