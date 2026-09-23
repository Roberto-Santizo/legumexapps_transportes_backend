<?php

namespace App\Services\DeviceToken;

use App\Errors\NotFoundError;
use App\Interfaces\DeviceToken\DeviceTokenServiceInterface;
use App\Models\User;
use App\Models\UserDeviceToken;
use Illuminate\Support\Facades\DB;
use Override;

class DeviceTokenService implements DeviceTokenServiceInterface
{
    #[Override]
    public function registerToken(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            $deviceToken = UserDeviceToken::query()
                ->where('token', $data['token'])
                ->lockForUpdate()
                ->first();

            $attributes = [
                'user_id' => $user->id,
                'platform' => $data['platform'],
                'last_seen_at' => now(),
            ];

            if ($deviceToken === null) {
                return [
                    'token' => UserDeviceToken::create([...$attributes, 'token' => $data['token']]),
                    'created' => true,
                ];
            }

            /**
             * Propio o ajeno, el camino es el mismo: el token pasa (o sigue) siendo de
             * quien llama. Reasignar es la decisión de la spec: si el teléfono cambió de
             * cuenta, el usuario anterior deja de recibir sus notificaciones.
             */
            $deviceToken->update($attributes);

            return ['token' => $deviceToken, 'created' => false];
        });
    }

    #[Override]
    public function deleteToken(User $user, string $token): UserDeviceToken
    {
        /**
         * Inexistente y ajeno son el mismo 404: distinguirlos revelaría que el token
         * está registrado en otra cuenta.
         */
        $deviceToken = UserDeviceToken::query()
            ->where('token', $token)
            ->where('user_id', $user->id)
            ->first();

        if ($deviceToken === null) {
            throw new NotFoundError('El token de dispositivo no existe');
        }

        $deviceToken->delete();

        return $deviceToken;
    }
}
