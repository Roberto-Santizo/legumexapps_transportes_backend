<?php

namespace App\Models;

use Database\Factories\TripTimeoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stop detected on a trip's live track: where the truck rested, and for how long.
 *
 * The first table of the project nobody asks for. No endpoint creates a row: they are
 * born as a side effect of `POST /api/trips/{trip}/positions` and closed either by the
 * point that proves the truck moved again or by `PATCH /api/trips/{trip}/finish`.
 *
 * There is no `status` —a stop has two states and `ended_at` says which one (`null`
 * means still open)—, no `SoftDeletes`, no normalization and no `registered_by`: the
 * author here is `pilot_id`, exactly as in `TripPosition`.
 *
 * `latitude`/`longitude` are the **anchor's** coordinates, copied on purpose so a map
 * can draw the pin without a second trip to the database. The copy is safe because a
 * position is immutable: no endpoint can ever desynchronize the two.
 */
#[Fillable([
    'trip_id', 'pilot_id', 'start_position_id', 'end_position_id',
    'latitude', 'longitude', 'started_at', 'ended_at',
])]
class TripTimeout extends Model
{
    /** @use HasFactory<TripTimeoutFactory> */
    use HasFactory;

    /**
     * The trip this stop belongs to.
     *
     * `Trip` deliberately gains no `timeouts()` on the other side, for the same reason
     * it gained no `positions()` in SPEC 26: an inverse relation would invite a
     * `with('timeouts')` on the trip listing that drags dozens of rows along.
     *
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * The pilot that was driving when the truck stopped.
     *
     * @return BelongsTo<User, $this>
     */
    public function pilot(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pilot_id');
    }

    /**
     * The anchor: the point where the truck already was at rest, and the one every later
     * point is measured against to decide whether the stop is over.
     *
     * @return BelongsTo<TripPosition, $this>
     */
    public function startPosition(): BelongsTo
    {
        return $this->belongsTo(TripPosition::class, 'start_position_id');
    }

    /**
     * The point that closed the stop, or `null` when it was the trip's `finish` that
     * closed it. That `null` is the only thing telling the two causes apart.
     *
     * @return BelongsTo<TripPosition, $this>
     */
    public function endPosition(): BelongsTo
    {
        return $this->belongsTo(TripPosition::class, 'end_position_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * The same `decimal:8` as `TripPosition` (SPEC 26) and `Location` (SPEC 15), so both
     * coordinates leave the Resource as an eight decimal **string** and never as a float.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
