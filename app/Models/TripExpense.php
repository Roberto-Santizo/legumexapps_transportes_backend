<?php

namespace App\Models;

use Database\Factories\TripExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One travel allowance («viático») a carrier company hands to the pilot of a trip, for
 * that pilot to confirm they received it.
 *
 * Sibling of `TripFuel` (SPEC 27) and shaped after it: a table of its own instead of a
 * column on `trips`, because a trip can be topped up more than once —an extra on the road
 * is the real case— and a column would have ruled that out forever. Reassigning a trip
 * with an amount appends another row instead of overwriting the previous one.
 *
 * Append only, like `trip_fuels` and `trip_positions`: nothing edits a row and nothing
 * deletes one. There is no `status` column either — the two states are «unconfirmed» and
 * «received», and `received_at` already tells them apart without ambiguity.
 */
#[Fillable(['trip_id', 'amount', 'description', 'received_at', 'confirmed_by', 'registered_by'])]
class TripExpense extends Model
{
    /** @use HasFactory<TripExpenseFactory> */
    use HasFactory;

    /**
     * The trip this allowance belongs to.
     *
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * The pilot that confirmed receiving the money, `null` while nobody has.
     *
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * The carrier company user that registered the allowance.
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
     * `amount` is deliberately **not** cast: the Resource formats it to two decimals on
     * its way out, like `gallons` in SPEC 27 and `price` in SPEC 17.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }
}
