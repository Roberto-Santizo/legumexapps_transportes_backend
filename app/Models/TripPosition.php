<?php

namespace App\Models;

use Database\Factories\TripPositionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One point of a trip's live track: where the assigned pilot was, and when.
 *
 * The first append only table of the project: nothing edits a row and nothing deletes
 * one. There is no `SoftDeletes`, no `status`, no normalization —not a single business
 * text field— and no `registered_by`: the author here is `pilot_id`, and naming it
 * twice would be a lie.
 *
 * `recorded_at` is written by the server with `now()`, never taken from the device, so
 * the track can always be ordered by the time each point arrived.
 */
#[Fillable(['trip_id', 'pilot_id', 'latitude', 'longitude', 'recorded_at'])]
class TripPosition extends Model
{
    /** @use HasFactory<TripPositionFactory> */
    use HasFactory;

    /**
     * The trip this point belongs to.
     *
     * `Trip` deliberately gains no `positions()` on the other side: the relation would
     * invite a `with('positions')` on the trip listing that would drag thousands of
     * rows along. Same criterion that left `Vehicle` without `expenses()` in SPEC 14.
     *
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * The pilot that reported this point.
     *
     * Stored on its own instead of being read from the trip, because SPEC 24 allows the
     * assignment to change while the trip is still `pending`: the track must keep saying
     * who was driving when each point was recorded.
     *
     * @return BelongsTo<User, $this>
     */
    public function pilot(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pilot_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * The same `decimal:8` as `Location` (SPEC 15), so both coordinates leave the
     * Resource as an eight decimal **string** and never as a float.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'recorded_at' => 'datetime',
        ];
    }
}
