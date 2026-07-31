<?php

namespace App\Services\Auth;

use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\UnauthorizedError;
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

    #[Override]
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = new User($data);
            $user->email_verified_at = null;
            $user->save();

            $this->storeCode(self::CONFIRMATION_TABLE, $user->email);

            return $user;
        });
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

        return ['user' => $user, 'token' => $token];
    }

    #[Override]
    public function checkStatus(): array
    {
        $user = auth('api')->user();

        if (! $user) {
            throw new UnauthorizedError('El token no es válido');
        }

        return ['user' => $user, 'token' => auth('api')->refresh()];
    }

    #[Override]
    public function forgotPassword(array $data): void
    {
        throw new \LogicException('Pendiente: paso 10 de la spec 01.');
    }

    #[Override]
    public function resetPassword(array $data): void
    {
        throw new \LogicException('Pendiente: paso 10 de la spec 01.');
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
