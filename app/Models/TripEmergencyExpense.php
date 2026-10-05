<?php

namespace App\Models;

use Database\Factories\TripEmergencyExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One unforeseen expense of a trip on the road («gasto emergente», SPEC 39): a flat
 * tyre, a tow truck. The carrier company registers it after the pilot tells them about
 * it outside the system, optionally with a receipt.
 *
 * Sibling of `TripExpense` (SPEC 31), not its copy: an allowance is money **handed over**
 * that the pilot confirms; this one is money **already spent**, so there is no
 * confirmation and no `received_at`/`confirmed_by`. With no second signature to protect,
 * the row is not append only either — it is corrected with `PATCH` and physically
 * deleted with `DELETE`, dragging its receipt out of the bucket like SPEC 19.
 *
 * No `casts()`: no dates of its own and no enums, like `AccessoryCharacteristic`.
 * `amount` is formatted by the Resource on its way out.
 */
#[Fillable(['trip_id', 'amount', 'description', 'receipt', 'registered_by'])]
class TripEmergencyExpense extends Model
{
    /** @use HasFactory<TripEmergencyExpenseFactory> */
    use HasFactory;

    /**
     * The trip this expense belongs to.
     *
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * The user that registered the expense.
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
