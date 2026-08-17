<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        /** Ningún test manda correo de verdad, ni siquiera por descuido. */
        Mail::fake();

        /** Ningún test sube nada de verdad: el disco por defecto se sustituye entero. */
        fakeDefaultDisk();

        /**
         * Ningún test sale a internet ni gasta cuota de un proveedor de pago.
         *
         * Http::fake() sin argumentos responde 200 vacío a cualquier petición, y
         * preventStrayRequests() convierte en fallo del test cualquier llamada que
         * el propio test no haya declarado con su Http::fake([...]).
         */
        Http::fake();
        Http::preventStrayRequests();
    })
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Swap the default filesystem disk for an in-memory fake.
 *
 * The fake is given the url of the real disk — or a stand-in host when it has
 * none — because a bare fake resolves to the relative "/storage/{key}" and the
 * application promises absolute URLs.
 */
function fakeDefaultDisk(): void
{
    $disk = config('filesystems.default');

    Storage::fake($disk, [
        'url' => config("filesystems.disks.{$disk}.url") ?: 'https://bucket.s3.test',
    ]);
}

/**
 * Store a known confirmation/reset code for an email.
 *
 * The service hashes the generated code and discards the plain value, so tests
 * that need to submit a valid code must plant the row themselves.
 */
function seedAuthCode(string $table, string $email, string $code = '123456', ?DateTimeInterface $expirationDate = null): void
{
    DB::table($table)->updateOrInsert(
        ['email' => $email],
        [
            'token' => Hash::make($code),
            'expiration_date' => $expirationDate ?? now()->addHour(),
            'created_at' => now(),
        ],
    );
}

/**
 * Drop the guard and JWT state kept in memory between calls of the same test.
 *
 * In production every request boots its own process; here the "tymon.jwt" and
 * "tymon.jwt.auth" singletons survive between calls and would keep serving the
 * token parsed by the previous request.
 */
function resetAuthState(): void
{
    app('auth')->forgetGuards();

    JWTAuth::unsetToken();
    app('tymon.jwt')->unsetToken();
}
