<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * The company this user owns, when the user has the carrier role.
     *
     * @return HasOne<Carrier, $this>
     */
    public function carrier(): HasOne
    {
        return $this->hasOne(Carrier::class);
    }

    /**
     * The company this user joined as a pilot, resolved through carrier_pilots.
     *
     * @return HasOneThrough<Carrier, CarrierPilot, $this>
     */
    public function pilotCarrier(): HasOneThrough
    {
        return $this->hasOneThrough(
            Carrier::class,
            CarrierPilot::class,
            'user_id',
            'id',
            'id',
            'carrier_id',
        );
    }

    /**
     * The company this user belongs to, either as owner or as pilot.
     *
     * Single source of truth for "does this user have a carrier?".
     */
    public function currentCarrier(): ?Carrier
    {
        return $this->carrier ?? $this->pilotCarrier;
    }

    /**
     * The DPI and license photos this user uploaded when registering as a pilot.
     *
     * Null for every other role, and null for pilots registered before SPEC 25:
     * there was no backfill, and nothing in the application blocks them for it.
     *
     * @return HasOne<PilotDocument, $this>
     */
    public function pilotDocument(): HasOne
    {
        return $this->hasOne(PilotDocument::class);
    }

    /**
     * Get the identifier that will be stored in the "sub" claim of the JWT.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Get the custom claims added to the JWT payload.
     *
     * The carrier claims mirror the user's company, and are null while the user
     * has none. They are informative for the front end, never a source of truth
     * for authorization.
     *
     * @return array{id: int, name: string, email: string, role: string, carrierId: int|null, carrierName: string|null, carrierCode: string|null}
     */
    public function getJWTCustomClaims(): array
    {
        $carrier = $this->currentCarrier();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'carrierId' => $carrier?->id,
            'carrierName' => $carrier?->name,
            'carrierCode' => $carrier?->code,
        ];
    }
}
