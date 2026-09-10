<?php

namespace App\Http\Resources\TripTimeout;

use App\Models\TripTimeout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TripTimeout
 */
class TripTimeoutResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Nine keys and no `tripId`: whoever asks for the stops already carries it in the
     * URL, exactly as in `TripPositionResource`.
     *
     * Both coordinates —the anchor's— leave as an eight decimal **string**, as in
     * `TripPosition` (SPEC 26) and `Location` (SPEC 15): a float would round away the
     * very precision the column was widened for.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            /** Las dos horas con el formato del resto del proyecto, nunca ISO 8601. */
            'startedAt' => $this->started_at?->format('d-m-Y h:i:s A'),
            'endedAt' => $this->ended_at?->format('d-m-Y h:i:s A'),
            'durationMinutes' => $this->resolveDurationMinutes(),
            'pilotId' => $this->pilot_id,
            'startPositionId' => $this->start_position_id,
            'endPositionId' => $this->end_position_id,
        ];
    }

    /**
     * How long the stop lasted, in minutes with two decimals.
     *
     * Computed on read and stored nowhere, with the precedent of `currentValue` in
     * SPEC 17: a value derived from two timestamps needs neither a column nor a job
     * keeping it in sync.
     *
     * `null` while the stop is still open, on purpose: measuring it against `now()`
     * would give a different number on every read, not comparable between two requests
     * and plainly misleading in a screenshot.
     *
     * The difference is taken in absolute value —same criterion as `secondsSince()` in
     * SPEC 26— so a server clock drifting backwards cannot produce a negative duration.
     */
    private function resolveDurationMinutes(): ?float
    {
        if ($this->started_at === null || $this->ended_at === null) {
            return null;
        }

        return round(abs($this->started_at->diffInSeconds($this->ended_at)) / 60, 2);
    }
}
