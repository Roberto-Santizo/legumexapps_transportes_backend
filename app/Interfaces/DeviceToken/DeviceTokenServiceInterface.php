<?php

namespace App\Interfaces\DeviceToken;

use App\Enums\DevicePlatform;
use App\Errors\NotFoundError;
use App\Models\User;
use App\Models\UserDeviceToken;

interface DeviceTokenServiceInterface
{
    /**
     * Register an FCM token for the given user, idempotently.
     *
     * The owner is always the given user and never the body. Three cases, depending on
     * who already holds the token:
     *  - nobody: a new row is created and `created` is true;
     *  - the same user: `platform` and `last_seen_at` are refreshed and `created` is false;
     *  - another user: the row is **reassigned** to the given user (`user_id`,
     *    `platform`, `last_seen_at`) and `created` is false — the previous owner stops
     *    receiving that phone's notifications.
     *
     * The upsert runs in a transaction with `lockForUpdate` on the existing row. The
     * token is stored exactly as received: it is an opaque, case-sensitive identifier.
     *
     * @param  array{token: string, platform: DevicePlatform|string}  $data
     * @return array{token: UserDeviceToken, created: bool}
     */
    public function registerToken(User $user, array $data): array;

    /**
     * Physically delete one of the given user's tokens and return the deleted row.
     *
     * A token that does not exist and a token that belongs to another user are the same
     * NotFoundError, so the response never reveals that a token is registered on another
     * account — precedent of `resolvePilot()` in SPEC 11. The other user's row is left
     * untouched.
     *
     * @throws NotFoundError when the token does not exist or belongs to another user
     */
    public function deleteToken(User $user, string $token): UserDeviceToken;
}
