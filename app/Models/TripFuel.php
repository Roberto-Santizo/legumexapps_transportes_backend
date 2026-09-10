<?php

namespace App\Models;

use App\Enums\FuelType;
use Database\Factories\TripFuelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fuel load a carrier company registers on a trip, for its assigned pilot to confirm.
 *
 * A table of its own instead of two columns on `trips`, because a trip can be fuelled
 * more than once —a refill on the road is the real case— and a column would have ruled
 * that out forever. Reassigning a trip appends another row instead of overwriting the
 * previous one, which makes this the only history SPEC 27 keeps.
 *
 * Append only, like `trip_positions` (SPEC 26): nothing edits a row and nothing deletes
 * one. There is no `status` column either — the two states are «unconfirmed» and
 * «confirmed», and `loaded_at` already tells them apart without ambiguity, the same way
 * `currentValue` is derived in SPEC 17 instead of stored.
 */
#[Fillable(['trip_id', 'gallons', 'fuel_type', 'loaded_at', 'confirmed_by', 'registered_by'])]
class TripFuel extends Model
{
    /** @use HasFactory<TripFuelFactory> */
    use HasFactory;

    /**
     * The trip this load belongs to.
     *
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * The pilot that confirmed the load, `null` while nobody has.
     *
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * The carrier company user that registered the load.
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * `gallons` is deliberately **not** cast: the Resource formats it to two decimals on
     * its way out, like `price` in SPEC 17 and `salary` in SPEC 11.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fuel_type' => FuelType::class,
            'loaded_at' => 'datetime',
        ];
    }
}
