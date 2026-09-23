<?php

namespace App\Models;

use App\Enums\DevicePlatform;
use Database\Factories\UserDeviceTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An FCM token of one of the user's devices (SPEC 34).
 *
 * Stored for a capability that does not exist yet: nothing sends a push notification,
 * the future sending spec will only read these rows. The token is globally unique and
 * moves between users when a phone changes account; there is no `status`, no
 * `deleted_at` and no `registered_by` — the author is `user_id`.
 */
#[Fillable(['user_id', 'token', 'platform', 'last_seen_at'])]
class UserDeviceToken extends Model
{
    /** @use HasFactory<UserDeviceTokenFactory> */
    use HasFactory;

    /**
     * The user that owns the device.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'last_seen_at' => 'datetime',
        ];
    }
}
