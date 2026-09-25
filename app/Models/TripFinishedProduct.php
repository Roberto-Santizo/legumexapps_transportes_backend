<?php

namespace App\Models;

use Database\Factories\TripFinishedProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a trip's cargo: how many boxes of a client's finished product it carries.
 *
 * Unrelated to `Product` (SPEC 07), which is why the table is not `trip_products`. The
 * product's `code`, `name`, `presentation` and `boxes_per_pallet` are never copied here:
 * they are read live, so editing the SKU changes what every trip carrying it shows.
 * `boxes` is the only editable column and deletion is physical.
 */
#[Fillable(['trip_id', 'finished_product_id', 'boxes', 'registered_by'])]
class TripFinishedProduct extends Model
{
    /** @use HasFactory<TripFinishedProductFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'boxes' => 'integer',
        ];
    }

    /**
     * The trip this line belongs to.
     *
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * The finished product carried on this line.
     *
     * Reads trashed products on purpose: deleting a SKU does not block on the trips that
     * carry it, and those lines must keep showing its code and name.
     *
     * @return BelongsTo<FinishedProduct, $this>
     */
    public function finishedProduct(): BelongsTo
    {
        return $this->belongsTo(FinishedProduct::class)->withTrashed();
    }

    /**
     * The user that captured this line.
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
