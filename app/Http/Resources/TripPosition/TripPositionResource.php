<?php

namespace App\Http\Resources\TripPosition;

use App\Models\TripPosition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TripPosition
 */
class TripPositionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Five keys and no `tripId`: whoever asks for the track already carries it in the
     * URL, and whoever receives the event gets it inside the payload.
     *
     * Both coordinates leave as an eight decimal **string**, as in `Location` since
     * SPEC 15: a float would round away the very precision the column was widened for.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            /** La hora de llegada al servidor, con el formato del resto del proyecto. */
            'recordedAt' => $this->recorded_at?->format('d-m-Y h:i:s A'),
            'pilotId' => $this->pilot_id,
        ];
    }
}
