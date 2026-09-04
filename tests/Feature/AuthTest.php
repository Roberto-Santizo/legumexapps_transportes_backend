<?php

use App\Enums\UserRole;
use App\Mail\Auth\AccountConfirmationMail;
use App\Mail\Auth\PasswordResetMail;
use App\Mail\Auth\WelcomeMail;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

const CONFIRMATION_TABLE = 'account_confirmation_tokens';
const RESET_TABLE = 'password_reset_tokens';

/**
 * @return array<string, string>
 */
function validRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Juan Pérez',
        'email' => 'juan.perez@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => UserRole::Pilot->value,
    ], $overrides);
}

/**
 * Register a pilot through the endpoint, attaching the two documents SPEC 25 requires.
 *
 * Goes through `post()` and not `postJson()` on purpose: since SPEC 25 the pilot
 * registration carries files, so the body travels as multipart/form-data.
 */
function registerPilot(array $overrides = []): TestResponse
{
    return test()->post(route('auth.register'), validRegisterPayload(array_merge([
        'dpi' => UploadedFile::fake()->image('dpi.jpg'),
        'license' => UploadedFile::fake()->image('license.png'),
    ], $overrides)));
}

/**
 * Log in through the endpoint and return the issued token.
 */
function tokenFor(User $user, string $password = 'password123'): string
{
    $token = test()->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => $password,
    ])->json('data.token');

    resetAuthState();

    return $token;
}

/**
 * Log in through the endpoint and return both issued tokens.
 *
 * @return array{token: string, refreshToken: string}
 */
function tokensFor(User $user, string $password = 'password123'): array
{
    $data = test()->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => $password,
    ])->json('data');

    resetAuthState();

    return ['token' => $data['token'], 'refreshToken' => $data['refreshToken']];
}

/**
 * Decode the claims of a token, leaving the JWT singletons clean on both ends.
 *
 * @return array<string, mixed>
 */
function authClaimsOf(string $token): array
{
    resetAuthState();

    $claims = JWTAuth::setToken($token)->getPayload()->toArray();

    resetAuthState();

    return $claims;
}

/*
|--------------------------------------------------------------------------
| POST /api/auth/register
|--------------------------------------------------------------------------
*/

it('registra un usuario y devuelve 201 con el recurso del usuario', function () {
    $response = registerPilot();

    $response->assertCreated()
        ->assertJson([
            'statusCode' => 201,
            'message' => 'Hemos enviado instrucciones a tu correo electronico',
            'data' => [
                'name' => 'Juan Pérez',
                'email' => 'juan.perez@example.com',
                'role' => UserRole::Pilot->value,
                'emailVerifiedAt' => null,
            ],
        ])
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => ['id', 'name', 'email', 'role', 'emailVerifiedAt'],
        ]);

    $this->assertDatabaseHas('users', [
        'email' => 'juan.perez@example.com',
        'role' => UserRole::Pilot->value,
        'email_verified_at' => null,
    ]);
});

it('no expone la contraseña ni un token en la respuesta del registro', function () {
    $response = registerPilot();

    $response->assertCreated()
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('token');

    expect(array_keys($response->json('data')))
        ->toBe(['id', 'name', 'email', 'role', 'carrierId', 'carrierName', 'carrierCode', 'emailVerifiedAt']);
});

it('guarda un único código de confirmación hasheado que expira una hora después de crearse', function () {
    $this->freezeTime();

    registerPilot()->assertCreated();

    $codes = DB::table(CONFIRMATION_TABLE)->get();

    expect($codes)->toHaveCount(1);

    $code = $codes->first();

    expect($code->email)->toBe('juan.perez@example.com')
        ->and($code->token)->not->toMatch('/^\d{6}$/')
        ->and(Hash::check('123456', $code->token))->toBeFalse()
        ->and(Carbon::parse($code->expiration_date)->equalTo(Carbon::parse($code->created_at)->addHour()))->toBeTrue();
});

it('rechaza el registro con un rol no permitido', function (string $role) {
    $this->postJson(route('auth.register'), validRegisterPayload(['role' => $role]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role' => 'El rol debe ser piloto o transportista']);

    $this->assertDatabaseCount('users', 0);
})->with([
    'administrator' => UserRole::Administrator->value,
    'manager' => UserRole::Manager->value,
]);

it('rechaza el registro con un correo ya registrado', function () {
    User::factory()->create(['email' => 'juan.perez@example.com']);

    $this->postJson(route('auth.register'), validRegisterPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'El correo ya está registrado']);
});

it('rechaza el registro cuando falta la confirmación de la contraseña', function () {
    $payload = validRegisterPayload();
    unset($payload['password_confirmation']);

    $this->postJson(route('auth.register'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password' => 'La confirmación de la contraseña no coincide']);

    $this->assertDatabaseCount('users', 0);
});

it('rechaza el registro cuando falta un campo obligatorio', function (string $field) {
    $payload = validRegisterPayload();
    unset($payload[$field]);

    $this->postJson(route('auth.register'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with(['name', 'email', 'password', 'role']);

/*
|--------------------------------------------------------------------------
| POST /api/auth/confirm-account
|--------------------------------------------------------------------------
*/

it('confirma la cuenta con el código correcto y elimina el código usado', function () {
    $user = User::factory()->unverified()->create();
    seedAuthCode(CONFIRMATION_TABLE, $user->email);

    $this->postJson(route('auth.confirm-account'), [
        'email' => $user->email,
        'code' => '123456',
    ])->assertOk()
        ->assertExactJson([
            'statusCode' => 200,
            'message' => 'La cuenta ha sido confirmada correctamente, inicie sesión.',
            'data' => null,
        ]);

    expect($user->fresh()->email_verified_at)->not->toBeNull();

    $this->assertDatabaseMissing(CONFIRMATION_TABLE, ['email' => $user->email]);
});

it('rechaza la confirmación con un código incorrecto', function () {
    $user = User::factory()->unverified()->create();
    seedAuthCode(CONFIRMATION_TABLE, $user->email);

    $this->postJson(route('auth.confirm-account'), [
        'email' => $user->email,
        'code' => '999999',
    ])->assertBadRequest()
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El código es inválido o ya expiró',
            'data' => null,
        ]);

    expect($user->fresh()->email_verified_at)->toBeNull();

    $this->assertDatabaseHas(CONFIRMATION_TABLE, ['email' => $user->email]);
});

it('rechaza la confirmación con un código expirado', function () {
    $user = User::factory()->unverified()->create();
    seedAuthCode(CONFIRMATION_TABLE, $user->email, '123456', now()->subMinute());

    $this->postJson(route('auth.confirm-account'), [
        'email' => $user->email,
        'code' => '123456',
    ])->assertBadRequest()
        ->assertJson(['message' => 'El código es inválido o ya expiró']);

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('rechaza el segundo uso del mismo código de confirmación', function () {
    $user = User::factory()->unverified()->create();
    seedAuthCode(CONFIRMATION_TABLE, $user->email);

    $payload = ['email' => $user->email, 'code' => '123456'];

    $this->postJson(route('auth.confirm-account'), $payload)->assertOk();
    $this->postJson(route('auth.confirm-account'), $payload)
        ->assertBadRequest()
        ->assertJson(['message' => 'El código es inválido o ya expiró']);
});

it('rechaza la confirmación de un correo sin código pendiente', function () {
    $user = User::factory()->unverified()->create();

    $this->postJson(route('auth.confirm-account'), [
        'email' => $user->email,
        'code' => '123456',
    ])->assertBadRequest()
        ->assertJson(['message' => 'El código es inválido o ya expiró']);
});

it('valida los campos obligatorios al confirmar la cuenta', function (array $payload, string $field) {
    $this->postJson(route('auth.confirm-account'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'sin correo' => [['code' => '123456'], 'email'],
    'sin código' => [['email' => 'juan.perez@example.com'], 'code'],
    'código de menos de 6 dígitos' => [['email' => 'juan.perez@example.com', 'code' => '123'], 'code'],
]);

/*
|--------------------------------------------------------------------------
| POST /api/auth/login
|--------------------------------------------------------------------------
*/

it('inicia sesión con credenciales válidas de una cuenta confirmada', function () {
    $user = User::factory()->create(['password' => 'password123']);

    $response = $this->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Sesión iniciada correctamente',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                ],
            ],
        ])
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => [
                'user' => ['id', 'name', 'email', 'role', 'emailVerifiedAt'],
                'token',
            ],
        ]);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
});

it('emite un token con los claims del usuario y una hora de vigencia', function () {
    $user = User::factory()->create(['password' => 'password123']);

    $token = $this->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => 'password123',
    ])->json('data.token');

    resetAuthState();

    $payload = JWTAuth::setToken($token)->getPayload();

    expect($payload->get('id'))->toBe($user->id)
        ->and($payload->get('name'))->toBe($user->name)
        ->and($payload->get('email'))->toBe($user->email)
        ->and($payload->get('role'))->toBe($user->role->value)
        ->and($payload->get('exp') - $payload->get('iat'))->toBe(60 * 60);

    resetAuthState();
});

it('rechaza el inicio de sesión con una contraseña incorrecta', function () {
    $user = User::factory()->create(['password' => 'password123']);

    $this->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => 'contrasena-incorrecta',
    ])->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'Las credenciales son incorrectas',
            'data' => null,
        ]);
});

it('rechaza el inicio de sesión con un correo inexistente', function () {
    $this->postJson(route('auth.login'), [
        'email' => 'nadie@example.com',
        'password' => 'password123',
    ])->assertUnauthorized()
        ->assertJson(['message' => 'Las credenciales son incorrectas']);
});

it('rechaza con 403 el inicio de sesión de una cuenta sin confirmar', function () {
    $user = User::factory()->unverified()->create(['password' => 'password123']);

    $this->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'La cuenta aún no ha sido confirmada',
            'data' => null,
        ]);
});

it('valida los campos obligatorios al iniciar sesión', function (array $payload, string $field) {
    $this->postJson(route('auth.login'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'sin correo' => [['password' => 'password123'], 'email'],
    'sin contraseña' => [['email' => 'juan.perez@example.com'], 'password'],
    'correo con formato inválido' => [['email' => 'no-es-un-correo', 'password' => 'password123'], 'email'],
]);

it('devuelve exactamente el usuario y los dos tokens al iniciar sesión', function () {
    $user = User::factory()->create(['password' => 'password123']);

    $response = $this->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertOk();

    expect(array_keys($response->json('data')))->toBe(['user', 'token', 'refreshToken'])
        ->and($response->json('data.refreshToken'))->toBeString()->not->toBeEmpty()
        ->and($response->json('data.refreshToken'))->not->toBe($response->json('data.token'));

    resetAuthState();
});

it('emite el token de refresco con catorce días de vigencia y marca el tokenType de cada uno', function () {
    $user = User::factory()->create(['password' => 'password123']);

    $tokens = tokensFor($user);

    $access = authClaimsOf($tokens['token']);
    $refresh = authClaimsOf($tokens['refreshToken']);

    expect($access['tokenType'])->toBe('access')
        ->and($access['exp'] - $access['iat'])->toBe(60 * 60)
        ->and($refresh['tokenType'])->toBe('refresh')
        ->and($refresh['exp'] - $refresh['iat'])->toBe(14 * 24 * 60 * 60);
});

it('emite los dos tokens del login con los mismos claims de negocio', function (string $claim) {
    $user = User::factory()->create(['password' => 'password123']);

    $tokens = tokensFor($user);

    expect(authClaimsOf($tokens['refreshToken'])[$claim])->toBe(authClaimsOf($tokens['token'])[$claim]);
})->with(['id', 'name', 'email', 'role', 'carrierId', 'carrierName', 'carrierCode']);

/*
|--------------------------------------------------------------------------
| GET /api/auth/check-status
|--------------------------------------------------------------------------
*/

it('rechaza check-status sin cabecera de autorización devolviendo el sobre de la API', function () {
    $this->getJson(route('auth.check-status'))
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
});

it('devuelve el usuario autenticado y un token renovado distinto al enviado', function () {
    $user = User::factory()->create(['password' => 'password123']);
    $token = tokenFor($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('auth.check-status'));

    $response->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Sesión válida',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role->value,
                ],
            ],
        ])
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => [
                'user' => ['id', 'name', 'email', 'role', 'emailVerifiedAt'],
                'token',
            ],
        ]);

    expect($response->json('data.token'))->not->toBe($token);

    resetAuthState();
});

it('acepta el token renovado en una segunda llamada a check-status', function () {
    $user = User::factory()->create(['password' => 'password123']);
    $token = tokenFor($user);

    $renewed = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('auth.check-status'))
        ->assertOk()
        ->json('data.token');

    resetAuthState();

    $this->withHeader('Authorization', "Bearer {$renewed}")
        ->getJson(route('auth.check-status'))
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id);

    resetAuthState();
});

it('rechaza check-status con un token manipulado', function () {
    $user = User::factory()->create(['password' => 'password123']);
    $token = tokenFor($user);

    $this->withHeader('Authorization', 'Bearer '.$token.'manipulado')
        ->getJson(route('auth.check-status'))
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);

    resetAuthState();
});

it('devuelve exactamente el usuario y un par nuevo de tokens en check-status', function () {
    $user = User::factory()->create(['password' => 'password123']);
    $tokens = tokensFor($user);

    $response = $this->withHeader('Authorization', "Bearer {$tokens['token']}")
        ->getJson(route('auth.check-status'))
        ->assertOk();

    expect(array_keys($response->json('data')))->toBe(['user', 'token', 'refreshToken'])
        ->and($response->json('data.token'))->not->toBe($tokens['token'])
        ->and($response->json('data.refreshToken'))->not->toBe($tokens['refreshToken']);

    resetAuthState();
});

it('acepta el token de refresco en check-status y devuelve un par nuevo', function () {
    $user = User::factory()->create(['password' => 'password123']);
    $tokens = tokensFor($user);

    $response = $this->withHeader('Authorization', "Bearer {$tokens['refreshToken']}")
        ->getJson(route('auth.check-status'))
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id);

    expect(array_keys($response->json('data')))->toBe(['user', 'token', 'refreshToken'])
        ->and($response->json('data.refreshToken'))->not->toBe($tokens['refreshToken']);

    resetAuthState();
});

it('renueva la ventana completa de catorce días del token de refresco', function () {
    $user = User::factory()->create(['password' => 'password123']);
    $tokens = tokensFor($user);

    $issued = authClaimsOf($tokens['refreshToken']);

    $this->travel(7)->days();

    $renewed = authClaimsOf(
        $this->withHeader('Authorization', "Bearer {$tokens['refreshToken']}")
            ->getJson(route('auth.check-status'))
            ->assertOk()
            ->json('data.refreshToken'),
    );

    /** No hereda el remanente del que llegó: vuelve a contar catorce días desde ahora. */
    expect($renewed['exp'] - $renewed['iat'])->toBe(14 * 24 * 60 * 60)
        ->and($renewed['exp'])->toBeGreaterThan($issued['exp']);

    resetAuthState();
});

it('encadena check-status con cada token de refresco devuelto sin tope de renovaciones', function () {
    $user = User::factory()->create(['password' => 'password123']);
    $refreshToken = tokensFor($user)['refreshToken'];

    foreach (range(1, 3) as $ignored) {
        $this->travel(13)->days();

        $refreshToken = $this->withHeader('Authorization', "Bearer {$refreshToken}")
            ->getJson(route('auth.check-status'))
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->json('data.refreshToken');

        resetAuthState();
    }

    expect($refreshToken)->toBeString()->not->toBeEmpty();
});

it('rechaza check-status con un token de refresco expirado', function () {
    $user = User::factory()->create(['password' => 'password123']);
    $tokens = tokensFor($user);

    $this->travel(15)->days();

    $this->withHeader('Authorization', "Bearer {$tokens['refreshToken']}")
        ->getJson(route('auth.check-status'))
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);

    resetAuthState();
});

it('rechaza con 403 el check-status de una cuenta sin confirmar', function () {
    $user = User::factory()->unverified()->create(['password' => 'password123']);

    /** El login bloquea la cuenta sin confirmar, así que el token se emite directamente. */
    $token = JWTAuth::fromUser($user);

    resetAuthState();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('auth.check-status'))
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'La cuenta aún no ha sido confirmada',
            'data' => null,
        ]);

    resetAuthState();
});

/*
|--------------------------------------------------------------------------
| POST /api/auth/forgot-password
|--------------------------------------------------------------------------
*/

it('genera un código de recuperación hasheado con una hora de vigencia', function () {
    $this->freezeTime();

    $user = User::factory()->create();

    $this->postJson(route('auth.forgot-password'), ['email' => $user->email])
        ->assertOk()
        ->assertExactJson([
            'statusCode' => 200,
            'message' => 'Si el correo está registrado recibirás un código de recuperación',
            'data' => null,
        ]);

    $codes = DB::table(RESET_TABLE)->get();

    expect($codes)->toHaveCount(1);

    $code = $codes->first();

    expect($code->email)->toBe($user->email)
        ->and($code->token)->not->toMatch('/^\d{6}$/')
        ->and(Carbon::parse($code->expiration_date)->equalTo(Carbon::parse($code->created_at)->addHour()))->toBeTrue();
});

it('responde igual y no genera código cuando el correo no está registrado', function () {
    $this->postJson(route('auth.forgot-password'), ['email' => 'nadie@example.com'])
        ->assertOk()
        ->assertExactJson([
            'statusCode' => 200,
            'message' => 'Si el correo está registrado recibirás un código de recuperación',
            'data' => null,
        ]);

    $this->assertDatabaseCount(RESET_TABLE, 0);
});

it('valida el correo al solicitar la recuperación de contraseña', function (array $payload) {
    $this->postJson(route('auth.forgot-password'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
})->with([
    'sin correo' => [[]],
    'correo con formato inválido' => [['email' => 'no-es-un-correo']],
]);

/*
|--------------------------------------------------------------------------
| POST /api/auth/reset-password
|--------------------------------------------------------------------------
*/

it('restablece la contraseña con un código válido y elimina el código usado', function () {
    $user = User::factory()->create(['password' => 'password123']);
    seedAuthCode(RESET_TABLE, $user->email);

    $this->postJson(route('auth.reset-password'), [
        'email' => $user->email,
        'code' => '123456',
        'password' => 'nueva-password',
    ])->assertOk()
        ->assertExactJson([
            'statusCode' => 200,
            'message' => 'La contraseña ha sido actualizada correctamente',
            'data' => null,
        ]);

    expect(Hash::check('nueva-password', $user->fresh()->password))->toBeTrue();

    $this->assertDatabaseMissing(RESET_TABLE, ['email' => $user->email]);
});

it('invalida la contraseña anterior y acepta la nueva tras el restablecimiento', function () {
    $user = User::factory()->create(['password' => 'password123']);
    seedAuthCode(RESET_TABLE, $user->email);

    $this->postJson(route('auth.reset-password'), [
        'email' => $user->email,
        'code' => '123456',
        'password' => 'nueva-password',
    ])->assertOk();

    $this->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertUnauthorized();

    resetAuthState();

    $this->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => 'nueva-password',
    ])->assertOk()->assertJsonPath('data.user.id', $user->id);

    resetAuthState();
});

it('rechaza el restablecimiento con un código incorrecto y conserva la contraseña', function () {
    $user = User::factory()->create(['password' => 'password123']);
    seedAuthCode(RESET_TABLE, $user->email);

    $this->postJson(route('auth.reset-password'), [
        'email' => $user->email,
        'code' => '999999',
        'password' => 'nueva-password',
    ])->assertBadRequest()
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El código es inválido o ya expiró',
            'data' => null,
        ]);

    expect(Hash::check('password123', $user->fresh()->password))->toBeTrue();

    $this->assertDatabaseHas(RESET_TABLE, ['email' => $user->email]);
});

it('rechaza el restablecimiento con un código expirado y conserva la contraseña', function () {
    $user = User::factory()->create(['password' => 'password123']);
    seedAuthCode(RESET_TABLE, $user->email, '123456', now()->subMinute());

    $this->postJson(route('auth.reset-password'), [
        'email' => $user->email,
        'code' => '123456',
        'password' => 'nueva-password',
    ])->assertBadRequest()
        ->assertJson(['message' => 'El código es inválido o ya expiró']);

    expect(Hash::check('password123', $user->fresh()->password))->toBeTrue();
});

it('valida los campos obligatorios al restablecer la contraseña', function (array $payload, string $field) {
    $this->postJson(route('auth.reset-password'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'sin correo' => [['code' => '123456', 'password' => 'nueva-password'], 'email'],
    'sin código' => [['email' => 'juan.perez@example.com', 'password' => 'nueva-password'], 'code'],
    'sin contraseña' => [['email' => 'juan.perez@example.com', 'code' => '123456'], 'password'],
    'contraseña demasiado corta' => [['email' => 'juan.perez@example.com', 'code' => '123456', 'password' => 'corta'], 'password'],
]);

/*
|--------------------------------------------------------------------------
| Correos de autenticación
|--------------------------------------------------------------------------
*/

it('envía exactamente un correo de confirmación al correo recién registrado', function () {
    registerPilot()->assertCreated();

    Mail::assertSentCount(1);
    Mail::assertSent(
        AccountConfirmationMail::class,
        fn (AccountConfirmationMail $mail): bool => $mail->hasTo('juan.perez@example.com'),
    );
});

it('envía en el correo de confirmación el código que valida el confirm-account siguiente', function () {
    registerPilot()->assertCreated();

    $code = null;

    Mail::assertSent(AccountConfirmationMail::class, function (AccountConfirmationMail $mail) use (&$code): bool {
        $code = $mail->code;

        /** El código de 6 dígitos llega renderizado en el cuerpo del correo, no solo como propiedad. */
        $mail->assertSeeInHtml($code);

        return true;
    });

    expect($code)->toMatch('/^\d{6}$/');

    $this->postJson(route('auth.confirm-account'), [
        'email' => 'juan.perez@example.com',
        'code' => $code,
    ])->assertOk();

    expect(User::where('email', '=', 'juan.perez@example.com')->first()->email_verified_at)->not->toBeNull();
});

it('envía exactamente un correo de bienvenida al confirmar la cuenta', function () {
    $user = User::factory()->unverified()->create();
    seedAuthCode(CONFIRMATION_TABLE, $user->email);

    $this->postJson(route('auth.confirm-account'), [
        'email' => $user->email,
        'code' => '123456',
    ])->assertOk();

    Mail::assertSentCount(1);
    Mail::assertSent(WelcomeMail::class, fn (WelcomeMail $mail): bool => $mail->hasTo($user->email));
});

it('no envía ningún correo cuando la confirmación falla por un código incorrecto', function () {
    $user = User::factory()->unverified()->create();
    seedAuthCode(CONFIRMATION_TABLE, $user->email);

    $this->postJson(route('auth.confirm-account'), [
        'email' => $user->email,
        'code' => '999999',
    ])->assertBadRequest();

    Mail::assertNothingSent();
});

it('envía exactamente un correo de recuperación al correo registrado', function () {
    $user = User::factory()->create();

    $this->postJson(route('auth.forgot-password'), ['email' => $user->email])->assertOk();

    Mail::assertSentCount(1);
    Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => $mail->hasTo($user->email));
});

it('no envía ningún correo de recuperación cuando el correo no está registrado', function () {
    $this->postJson(route('auth.forgot-password'), ['email' => 'nadie@example.com'])->assertOk();

    Mail::assertNothingSent();
});

it('no envía ningún correo al iniciar sesión', function () {
    $user = User::factory()->create(['password' => 'password123']);

    $this->postJson(route('auth.login'), [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertOk();

    Mail::assertNothingSent();

    resetAuthState();
});

it('devuelve 201 en el registro aunque el proveedor de correo falle', function () {
    Log::spy();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('proveedor de correo caído'));

    registerPilot()->assertCreated();

    $this->assertDatabaseHas('users', ['email' => 'juan.perez@example.com']);
    $this->assertDatabaseCount(CONFIRMATION_TABLE, 1);
});

/*
|--------------------------------------------------------------------------
| El token de refresco en el resto de la API
|--------------------------------------------------------------------------
*/

it('autentica una ruta protegida de otro dominio con el token de refresco', function () {
    $user = User::factory()->create(['password' => 'password123']);
    $refreshToken = tokensFor($user)['refreshToken'];

    $this->withHeader('Authorization', "Bearer {$refreshToken}")
        ->getJson(route('products.index'))
        ->assertOk();

    resetAuthState();
});

it('aplica el middleware de rol igual con el token de acceso que con el de refresco', function (string $key) {
    $pilot = User::factory()->create(['role' => UserRole::Pilot->value, 'password' => 'password123']);
    $administrator = User::factory()->create(['role' => UserRole::Administrator->value, 'password' => 'password123']);

    $pilotTokens = tokensFor($pilot);
    $administratorTokens = tokensFor($administrator);

    $this->withHeader('Authorization', "Bearer {$pilotTokens[$key]}")
        ->postJson(route('products.store'), [])
        ->assertForbidden();

    resetAuthState();

    /** El administrador sí atraviesa el middleware: el 422 lo produce la validación, no el rol. */
    $this->withHeader('Authorization', "Bearer {$administratorTokens[$key]}")
        ->postJson(route('products.store'), [])
        ->assertUnprocessable();

    resetAuthState();
})->with(['token', 'refreshToken']);
