<?php

namespace App\Services\Auth;

use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\UnauthorizedError;
use App\Interfaces\Auth\AuthEmailsInterface;
use App\Interfaces\Auth\AuthServiceInterface;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Override;

class AuthService implements AuthServiceInterface
{
    /**
     * Table holding the pending account confirmation codes.
     */
    private const CONFIRMATION_TABLE = 'account_confirmation_tokens';

    /**
     * Table holding the pending password reset codes.
     */
    private const RESET_TABLE = 'password_reset_tokens';

    /**
     * Injected by constructor, unlike the controllers' per-method injection:
     * the emails are a dependency of several methods of this service.
     */
    public function __construct(
        private AuthEmailsInterface $authEmails,
    ) {}

    #[Override]
    public function register(array $data): User
    {
        /** El correo sale fuera de la transacción: un commit fallido no debe dejar un código enviado que no existe. */
        [$user, $code] = DB::transaction(function () use ($data): array {
            $user = new User($data);
            $user->email_verified_at = null;
            $user->save();

            return [$user, $this->storeCode(self::CONFIRMATION_TABLE, $user->email)];
        });

        $this->authEmails->sendAccountConfirmation($user, $code);

        return $user;
    }

    #[Override]
    public function confirmAccount(array $data): void
    {
        $user = $this->getUserByValidCode(self::CONFIRMATION_TABLE, $data['email'], $data['code']);

        DB::transaction(function () use ($user, $data): void {
            $user->email_verified_at = now();
            $user->save();

            DB::table(self::CONFIRMATION_TABLE)->where('email', '=', $data['email'])->delete();
        });

        $this->authEmails->sendWelcome($user);
    }

    #[Override]
    public function login(array $data): array
    {
        $token = auth('api')->attempt([
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        if (! $token) {
            throw new UnauthorizedError('Las credenciales son incorrectas');
        }

        $user = auth('api')->user();

        if (! $user->email_verified_at) {
            /** El attempt() ya dejó al usuario en el guard y el token en la instancia de JWT: se descartan ambos. */
            auth('api')->unsetToken();
            Auth::forgetGuards();

            throw new ForbiddenError('La cuenta aún no ha sido confirmada');
        }

        /** El token de attempt() se descarta: los dos que viajan salen de issueTokens(), que es quien les pone el tokenType. */
        return ['user' => $user, ...$this->issueTokens($user)];
    }

    #[Override]
    public function checkStatus(): array
    {
        $user = auth('api')->user();

        if (! $user) {
            throw new UnauthorizedError('El token no es válido');
        }

        if(!$user->email_verified_at){
            throw new ForbiddenError('La cuenta aún no ha sido confirmada');
        }

        /** Se reemite desde el usuario, no con refresh(): refresh arrastra los claims del token anterior y dejaría los de transportista desactualizados hasta que expirase la sesión. */
        return ['user' => $user, 'token' => auth('api')->login($user)];
    }

    #[Override]
    public function forgotPassword(array $data): void
    {
        $user = User::where('email', '=', $data['email'])->first();

        /** Se responde igual exista o no la cuenta: el endpoint no revela qué correos están registrados. */
        if (! $user) {
            return;
        }

        $code = $this->storeCode(self::RESET_TABLE, $user->email);

        $this->authEmails->sendPasswordReset($user, $code);
    }

    #[Override]
    public function resetPassword(array $data): void
    {
        $user = $this->getUserByValidCode(self::RESET_TABLE, $data['email'], $data['code']);

        DB::transaction(function () use ($user, $data): void {
            $user->password = $data['password'];
            $user->save();

            DB::table(self::RESET_TABLE)->where('email', '=', $data['email'])->delete();
        });
    }

    /**
     * Issue the access and refresh tokens of a user.
     *
     * Only place in the project aware of claims() and factory()->setTTL(): both the
     * TTL and the custom claims are request state, so the access token is issued
     * first with the config TTL intact, both declare their tokenType explicitly, and
     * the TTL is restored afterwards — otherwise any later token of the same request
     * would inherit the refresh one.
     *
     * @return array{token: string, refreshToken: string}
     */
    private function issueTokens(User $user): array
    {
        $token = auth('api')->claims(['tokenType' => 'access'])->login($user);

        auth('api')->factory()->setTTL(config('jwt.refresh_token_ttl'));
        $refreshToken = auth('api')->claims(['tokenType' => 'refresh'])->login($user);
        auth('api')->factory()->setTTL(config('jwt.ttl'));

        return ['token' => $token, 'refreshToken' => $refreshToken];
    }

    /**
     * Generate a 6 digit code and store it hashed, replacing any previous one.
     */
    private function storeCode(string $table, string $email): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table($table)->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($code),
                'expiration_date' => now()->addHour(),
                'created_at' => now(),
            ],
        );

        return $code;
    }

    /**
     * Resolve the user behind a code, failing when it is missing, expired or wrong.
     */
    private function getUserByValidCode(string $table, string $email, string $code): User
    {
        $record = DB::table($table)->where('email', '=', $email)->first();

        if (! $record || Carbon::parse($record->expiration_date)->isPast() || ! Hash::check($code, $record->token)) {
            throw new BadRequestError('El código es inválido o ya expiró');
        }

        $user = User::where('email', '=', $email)->first();

        if (! $user) {
            throw new BadRequestError('El código es inválido o ya expiró');
        }

        return $user;
    }
}
